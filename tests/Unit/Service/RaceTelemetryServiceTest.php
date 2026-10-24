<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Repository\RaceTelemetryRepository;
use App\Service\RaceTelemetryService;
use App\Telemetry\RaceTelemetryMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RaceTelemetryService::class)]
final class RaceTelemetryServiceTest extends TestCase
{
    public function testAFailedIngestLogsTheClassOnlyAndNeverTheMessage(): void
    {
        $repository = $this->createStub(RaceTelemetryRepository::class);
        $repository->method('insertIfNew')->willThrowException(new \RuntimeException('secret-payload-detail'));
        $service = new RaceTelemetryService($repository, new RaceTelemetryMapper());
        $laps = [['idx' => 0, 'pos' => 8, 'tyres' => 'Hard', 'weather' => 'Sunny', 'temp' => 44, 'hum' => 35]];
        for ($i = 1; $i <= 5; $i++) {
            $laps[] = [
                'idx' => $i, 'pos' => 5, 'tyres' => 'Hard', 'weather' => 'Sunny',
                'temp' => 44, 'hum' => 35, 'lapTime' => '1:14.100',
            ];
        }
        $analysis = [
            'selSeasonNb' => '107', 'selRaceNb' => '13', 'group' => 'Amateur - 4',
            'trackName' => 'Hockenheim (Germany)', 'driver' => ['id' => 77, 'OA' => '140'], 'laps' => $laps,
        ];

        $dir = dirname(__DIR__, 3) . '/var/test';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $log = $dir . '/telemetry-log-' . bin2hex(random_bytes(6)) . '.log';
        $prior = ini_set('error_log', $log);
        try {
            $stored = $service->ingest($analysis);
        } finally {
            ini_set('error_log', (string) $prior);
        }
        $lines = (string) file_get_contents($log);
        @unlink($log);

        $this->assertFalse($stored);
        $this->assertStringContainsString('[RaceTelemetry] ingest failed: RuntimeException', $lines);
        $this->assertStringNotContainsString('secret-payload-detail', $lines);
    }
}
