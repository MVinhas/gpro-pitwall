<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Repository\RaceTelemetryRepository;
use App\Service\RiskCurveService;
use App\Tests\Support\ArrayCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @phpstan-import-type Row from \App\Engineer\RiskCurve
 */
#[CoversClass(RiskCurveService::class)]
final class RiskCurveServiceTest extends TestCase
{
    private ArrayCache $cache;
    private string|false $priorLog = false;
    private string $quietLog = '';

    protected function setUp(): void
    {
        $this->cache = new ArrayCache();
        $dir = dirname(__DIR__, 3) . '/var/test';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $this->quietLog = $dir . '/risk-curve-quiet-' . bin2hex(random_bytes(4)) . '.log';
    }

    /** PHPUnit points error_log at its own file just before the test body runs, so this is called from the body. */
    private function captureLog(): void
    {
        $this->priorLog = ini_get('error_log');
        ini_set('error_log', $this->quietLog);
    }

    protected function tearDown(): void
    {
        if ($this->priorLog !== false) {
            ini_set('error_log', $this->priorLog);
        }
        if (is_file($this->quietLog)) {
            unlink($this->quietLog);
        }
    }

    /**
     * Four races a season for each driver, so every row is used.
     *
     * @return list<Row>
     */
    private static function corpus(int $drivers): array
    {
        $rows = [];
        for ($i = 0; $i < 4 * $drivers; $i++) {
            $o = 5 + 5 * ($i % 11);
            $d = 5 + 5 * (($i * 3) % 11);
            $rows[] = [
                'driver' => 1000 + intdiv($i, 4), 'season' => 113, 'grid' => 1 + $i % 30,
                'gained' => intdiv($o, 10) - intdiv($d, 15) + $i % 3,
                'overtake' => $o, 'defend' => $d, 'wet' => $i % 4 === 0,
                'track' => $i % 5, 'grip' => ($i * 2) % 5,
                'agg' => 30 + ($i * 5) % 120, 'exp' => 40 + ($i * 13) % 150, 'con' => 60 + ($i * 7) % 140,
            ];
        }

        return $rows;
    }

    /**
     * @return RaceTelemetryRepository&MockObject
     */
    private function repo(int $total): RaceTelemetryRepository
    {
        $repo = $this->createMock(RaceTelemetryRepository::class);
        $repo->method('total')->willReturn($total);

        return $repo;
    }

    /**
     * @return RaceTelemetryRepository&Stub
     */
    private function stub(int $total): RaceTelemetryRepository
    {
        $repo = $this->createStub(RaceTelemetryRepository::class);
        $repo->method('total')->willReturn($total);

        return $repo;
    }

    public function testFitsFromTheCorpusAndKeepsTheResultForTheNextCall(): void
    {
        $repo = $this->repo(400);
        $repo->expects($this->once())->method('curveRows')->willReturn(self::corpus(100));
        $service = new RiskCurveService($repo, $this->cache);

        $first = $service->curve(113);
        $second = $service->curve(113);

        $this->assertSame(400, $first->rows());
        $this->assertSame($first->toArray(), $second->toArray());
    }

    public function testANewRaceInTheCorpusRefitsAtOnce(): void
    {
        $repo = $this->createMock(RaceTelemetryRepository::class);
        $repo->method('total')->willReturnOnConsecutiveCalls(400, 404);
        $repo->expects($this->exactly(2))->method('curveRows')
            ->willReturnOnConsecutiveCalls(self::corpus(100), self::corpus(101));
        $service = new RiskCurveService($repo, $this->cache);

        $this->assertSame(400, $service->curve(113)->rows());
        $this->assertSame(404, $service->curve(113)->rows());
    }

    public function testANewSeasonRefits(): void
    {
        $repo = $this->repo(400);
        $repo->expects($this->exactly(2))->method('curveRows')->willReturn(self::corpus(100));
        $service = new RiskCurveService($repo, $this->cache);

        $service->curve(113);
        $service->curve(114);
    }

    public function testAnEmptyCorpusGivesACurveThatIsNotReady(): void
    {
        $repo = $this->stub(0);
        $repo->method('curveRows')->willReturn([]);

        $curve = (new RiskCurveService($repo, $this->cache))->curve(113);

        $this->assertFalse($curve->ready());
        $this->assertSame(0, $curve->rows());
    }

    public function testAFailureFallsBackAndLogsOnlyTheClassName(): void
    {
        $this->captureLog();
        $repo = $this->stub(400);
        $repo->method('curveRows')->willThrowException(new RuntimeException('secret path /var/db'));

        $curve = (new RiskCurveService($repo, $this->cache))->curve(113);

        $this->assertFalse($curve->ready());
        $log = (string) file_get_contents($this->quietLog);
        $this->assertStringContainsString('[Engineer] ' . RuntimeException::class, $log);
        $this->assertStringNotContainsString('secret path', $log);
        $this->assertSame([], $this->cache->store, 'a failed fit is never cached');
    }
}
