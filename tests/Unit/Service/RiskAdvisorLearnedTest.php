<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Engineer\PlaceGap;
use App\Engineer\RiskModel;
use App\Service\RiskAdvisorService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type Row from RiskModel
 */
#[CoversClass(RiskAdvisorService::class)]
final class RiskAdvisorLearnedTest extends TestCase
{
    /**
     * Races in which every extra point of overtake risk buys little and costs
     * many mistakes, so a fitted model wants a low overtake risk.
     *
     * @return list<Row>
     */
    private static function costlyCorpus(int $n, bool $replays = true): array
    {
        mt_srand(7);
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $ot = 10 + 5 * mt_rand(0, 10);
            $def = 10 + 5 * mt_rand(0, 10);
            $mistakes = (int) round(60 * 0.01 * exp(6.0 * $ot / 100) * (0.8 + 0.4 * mt_rand() / mt_getrandmax()));
            $rows[] = [
                'level' => 'Amateur', 'wet' => 0.0, 'laps' => 60, 'ot_risk' => $ot, 'def_risk' => $def,
                'ctr' => 30 + mt_rand(0, 30), 'con' => 80 + mt_rand(0, 100), 'tal' => 100, 'agg' => 80 + mt_rand(0, 60),
                'exp' => 100, 'track' => mt_rand(0, 4), 'grid' => 1 + mt_rand(0, 19),
                'overtakes' => 2 + mt_rand(0, 1), 'passed' => 2 + mt_rand(0, 1),
                'replay' => $replays ? [
                    'laps' => 60, 'mistakes' => $mistakes, 'mistake_ms' => $mistakes * 8000,
                    'traffic_share' => 0.3, 'avg_pos' => 10.0,
                ] : null,
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private static function driver(): array
    {
        return ['concentration' => 120, 'talent' => 100, 'aggressiveness' => 100, 'experience' => 110, 'stamina' => 100,
            'motivation' => 100];
    }

    /** @return array<string, mixed> */
    private static function track(): array
    {
        return ['overtaking' => 'Normal', 'grip' => 'Normal', 'tyre_wear' => 'Medium', 'distance' => 250.0];
    }

    /** @return array{division: ?string, laps: int, ctr: int, grid: ?int} */
    private static function race(?string $division = 'Amateur'): array
    {
        return ['division' => $division, 'laps' => 60, 'ctr' => 40, 'grid' => null];
    }

    private function advise(RiskModel $model, ?string $division = 'Amateur', bool $wet = false): array
    {
        return (new RiskAdvisorService())->advise(
            self::driver(),
            self::track(),
            $wet,
            10.0,
            self::race($division),
            $model,
            PlaceGap::fallback(),
        );
    }

    public function testAnEmptyModelIsTheHeuristicFlaggedAsAnEstimate(): void
    {
        $heuristic = (new RiskAdvisorService())->suggest(self::driver(), self::track(), false, 10.0);
        $advice = $this->advise(RiskModel::fallback());

        $this->assertSame($heuristic['overtake'], $advice['overtake']);
        $this->assertSame($heuristic['defend'], $advice['defend']);
        $this->assertSame('estimate', $advice['source']);
        $this->assertSame(0, $advice['races']);
        $this->assertSame(RiskModel::MIN_REPLAY, $advice['needed']);
        $this->assertNull($advice['places']);
    }

    public function testAModelStillLearningReportsHowFarItIs(): void
    {
        $advice = $this->advise(RiskModel::fit(self::costlyCorpus(20)));

        $this->assertSame('estimate', $advice['source']);
        $this->assertSame(20, $advice['races']);
        $this->assertSame(20, $advice['replays']);
    }

    public function testAReadyModelIsLearnedAndPullsTheRisksTowardItsOptimum(): void
    {
        $model = RiskModel::fit(self::costlyCorpus(300));
        $this->assertTrue($model->ready());
        $heuristic = (new RiskAdvisorService())->suggest(self::driver(), self::track(), false, 10.0);

        $advice = $this->advise($model);

        $this->assertSame('learned', $advice['source']);
        $this->assertSame(300, $advice['replays']);
        $this->assertLessThanOrEqual($heuristic['overtake'], $advice['overtake']);
        $this->assertSame(0, $advice['overtake'] % 5);
        $this->assertSame(0, $advice['defend'] % 5);
        $this->assertIsFloat($advice['places']);
        $this->assertIsFloat($advice['mistakes']);
    }

    public function testTheBlendIsBetweenTheHeuristicAndTheLearnedPair(): void
    {
        $model = RiskModel::fit(self::costlyCorpus(300));
        $learned = $model->recommend(
            ['level' => 'Amateur', 'wet' => false, 'laps' => 60, 'ctr' => 40, 'con' => 120, 'tal' => 100,
                'agg' => 100, 'exp' => 110, 'track' => 2, 'grid' => null],
            PlaceGap::fallback()->secondsPerPlace('Amateur', 60),
        );
        $this->assertNotNull($learned);
        $heuristic = (new RiskAdvisorService())->suggest(self::driver(), self::track(), false, 10.0);

        $advice = $this->advise($model);

        $this->assertGreaterThanOrEqual(min($learned['overtake'], $heuristic['overtake']), $advice['overtake']);
        $this->assertLessThanOrEqual(max($learned['overtake'], $heuristic['overtake']), $advice['overtake']);
    }

    public function testAnUnknownDivisionFallsBackToTheEstimate(): void
    {
        $advice = $this->advise(RiskModel::fit(self::costlyCorpus(300)), null);

        $this->assertSame('estimate', $advice['source']);
    }

    public function testNoRaceContextIsTheEstimate(): void
    {
        $advice = (new RiskAdvisorService())->advise(
            self::driver(),
            self::track(),
            false,
            10.0,
            null,
            RiskModel::fit(self::costlyCorpus(300)),
            PlaceGap::fallback(),
        );

        $this->assertSame('estimate', $advice['source']);
    }

    public function testTheDivisionComesFromTheGroupLabel(): void
    {
        $this->assertSame('Pro', RiskAdvisorService::divisionFromGroup('Pro - 8'));
        $this->assertSame('Rookie', RiskAdvisorService::divisionFromGroup('Rookie - 31'));
        $this->assertNull(RiskAdvisorService::divisionFromGroup('Junk - 1'));
        $this->assertNull(RiskAdvisorService::divisionFromGroup(''));
    }
}
