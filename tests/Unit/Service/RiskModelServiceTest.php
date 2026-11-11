<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Repository\RaceTelemetryRepository;
use App\Service\RiskModelService;
use App\Tests\Support\ArrayCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @phpstan-import-type Row from \App\Engineer\RiskModel
 */
#[CoversClass(RiskModelService::class)]
final class RiskModelServiceTest extends TestCase
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
        $this->quietLog = $dir . '/risk-model-quiet-' . bin2hex(random_bytes(4)) . '.log';
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
     * @return list<Row>
     */
    private static function corpus(int $n): array
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $ot = 10 + 5 * ($i % 11);
            $def = 10 + 5 * (($i * 3) % 11);
            $rows[] = [
                'level' => 'Amateur', 'wet' => $i % 3 === 0 ? 1.0 : 0.0, 'laps' => 60,
                'ot_risk' => $ot, 'def_risk' => $def, 'ctr' => 20 + $i % 40,
                'con' => 60 + ($i * 7) % 140, 'tal' => 60 + ($i * 11) % 140,
                'agg' => 30 + ($i * 5) % 120, 'exp' => 40 + ($i * 13) % 150,
                'track' => $i % 5, 'grid' => 1 + $i % 20,
                'overtakes' => intdiv($ot, 10) + $i % 3, 'passed' => 6 - intdiv($def, 15) + $i % 2,
                'replay' => [
                    'laps' => 60, 'mistakes' => intdiv($ot, 25) + $i % 2, 'mistake_ms' => (intdiv($ot, 25) + $i % 2) * 5000,
                    'traffic_share' => 0.2 + ($i % 10) / 20, 'avg_pos' => (float) (1 + $i % 20),
                ],
            ];
        }

        return $rows;
    }

    /**
     * @return RaceTelemetryRepository&MockObject
     */
    private function repo(int $total, int $replays): RaceTelemetryRepository
    {
        $repo = $this->createMock(RaceTelemetryRepository::class);
        $repo->method('total')->willReturn($total);
        $repo->method('replayCount')->willReturn($replays);

        return $repo;
    }

    /**
     * @return RaceTelemetryRepository&Stub
     */
    private function stub(int $total, int $replays): RaceTelemetryRepository
    {
        $repo = $this->createStub(RaceTelemetryRepository::class);
        $repo->method('total')->willReturn($total);
        $repo->method('replayCount')->willReturn($replays);

        return $repo;
    }

    public function testFitsFromTheCorpusAndKeepsTheResultForTheNextCall(): void
    {
        $repo = $this->repo(100, 100);
        $repo->expects($this->once())->method('riskRows')->willReturn(self::corpus(100));
        $service = new RiskModelService($repo, $this->cache);

        $first = $service->model(113);
        $second = $service->model(113);

        $this->assertTrue($first->ready());
        $this->assertSame(100, $first->races());
        $this->assertSame($first->toArray(), $second->toArray());
    }

    public function testANewRaceInTheCorpusRefitsAtOnce(): void
    {
        $repo = $this->createMock(RaceTelemetryRepository::class);
        $repo->method('total')->willReturnOnConsecutiveCalls(100, 101);
        $repo->method('replayCount')->willReturn(100);
        $repo->expects($this->exactly(2))->method('riskRows')
            ->willReturnOnConsecutiveCalls(self::corpus(100), self::corpus(101));
        $service = new RiskModelService($repo, $this->cache);

        $this->assertSame(100, $service->model(113)->races());
        $this->assertSame(101, $service->model(113)->races());
    }

    public function testAReplayFiledForAnExistingRaceRefitsToo(): void
    {
        $repo = $this->createMock(RaceTelemetryRepository::class);
        $repo->method('total')->willReturn(100);
        $repo->method('replayCount')->willReturnOnConsecutiveCalls(50, 51);
        $repo->expects($this->exactly(2))->method('riskRows')->willReturn(self::corpus(100));
        $service = new RiskModelService($repo, $this->cache);

        $service->model(113);
        $service->model(113);
    }

    public function testANewSeasonRefits(): void
    {
        $repo = $this->repo(100, 100);
        $repo->expects($this->exactly(2))->method('riskRows')->willReturn(self::corpus(100));
        $service = new RiskModelService($repo, $this->cache);

        $service->model(113);
        $service->model(114);
    }

    public function testAnEmptyCorpusGivesAModelThatIsNotReady(): void
    {
        $repo = $this->stub(0, 0);
        $repo->method('riskRows')->willReturn([]);

        $model = (new RiskModelService($repo, $this->cache))->model(113);

        $this->assertFalse($model->ready());
        $this->assertSame(0, $model->races());
    }

    public function testAFailureFallsBackAndLogsOnlyTheClassName(): void
    {
        $this->captureLog();
        $repo = $this->stub(100, 100);
        $repo->method('riskRows')->willThrowException(new RuntimeException('secret path /var/db'));

        $model = (new RiskModelService($repo, $this->cache))->model(113);

        $this->assertFalse($model->ready());
        $log = (string) file_get_contents($this->quietLog);
        $this->assertStringContainsString('[Engineer] ' . RuntimeException::class, $log);
        $this->assertStringNotContainsString('secret path', $log);
        $this->assertSame([], $this->cache->store, 'a failed fit is never cached');
    }
}
