<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\RiskModel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type Row from RiskModel
 * @phpstan-import-type Situation from RiskModel
 */
#[CoversClass(RiskModel::class)]
final class RiskModelTest extends TestCase
{
    private const float SECONDS_PER_PLACE = 10.0;

    private static function poisson(float $mean): int
    {
        $limit = exp(-$mean);
        $k = 0;
        $p = 1.0;
        do {
            $k++;
            $p *= mt_rand() / mt_getrandmax();
        } while ($p > $limit);

        return $k - 1;
    }

    private static function uniform(int $lo, int $hi, int $step = 1): int
    {
        return $lo + $step * mt_rand(0, intdiv($hi - $lo, $step));
    }

    /**
     * Races from a known process. Per lap: passes made grow with overtake risk,
     * passes suffered shrink with defend risk, and mistakes grow with both
     * risks, with rain on top of the overtake risk, with traffic, and fall with
     * concentration. Traffic follows the grid slot.
     *
     * @param array<string, float|int> $p
     * @return list<Row>
     */
    private static function corpus(int $n, array $p = [], int $seed = 21, bool $withReplay = true): array
    {
        $p += ['a_ot' => 2.0, 'a_def' => 1.5, 'k_ot' => 2.0, 'k_def' => 0.5, 'k_wet' => 0.0, 'k_con' => 0.0,
            'k_traffic' => 0.0, 'lo' => 10, 'hi' => 60, 'wet_share' => 0.35, 'wet_laps' => 1.0];
        mt_srand($seed);
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $wet = mt_rand() / mt_getrandmax() < $p['wet_share'] ? $p['wet_laps'] : 0.0;
            $ot = self::uniform((int) $p['lo'], (int) $p['hi'], 5);
            $def = self::uniform((int) $p['lo'], (int) $p['hi'], 5);
            $con = self::uniform(50, 200);
            $grid = self::uniform(1, 20);
            $traffic = min(0.9, 0.15 + 0.03 * $grid);
            $laps = 60;
            $mistakes = self::poisson($laps * 0.01 * exp(
                $p['k_ot'] * $ot / 100 + $p['k_def'] * $def / 100 + $p['k_wet'] * $wet * $ot / 100
                + $p['k_traffic'] * $traffic - $p['k_con'] * $con / 250
            ));
            $rows[] = [
                'level' => 'Amateur', 'wet' => $wet, 'laps' => $laps,
                'ot_risk' => $ot, 'def_risk' => $def, 'ctr' => self::uniform(20, 60),
                'con' => $con, 'tal' => self::uniform(50, 200), 'agg' => self::uniform(20, 150),
                'exp' => self::uniform(20, 200), 'track' => mt_rand(0, 4), 'grid' => $grid,
                'overtakes' => self::poisson($laps * 0.05 * exp($p['a_ot'] * $ot / 100)),
                'passed' => self::poisson($laps * 0.05 * exp(-$p['a_def'] * $def / 100)),
                'replay' => $withReplay ? [
                    'laps' => $laps, 'mistakes' => $mistakes, 'mistake_ms' => $mistakes * 5000,
                    'traffic_share' => $traffic, 'avg_pos' => (float) $grid,
                ] : null,
            ];
        }

        return $rows;
    }

    /**
     * @return Situation
     */
    private static function driver(bool $wet = false, ?int $grid = 10, int $con = 120): array
    {
        return ['level' => 'Amateur', 'wet' => $wet, 'laps' => 60, 'ctr' => 40, 'con' => $con,
            'tal' => 120, 'agg' => 80, 'exp' => 110, 'track' => 2, 'grid' => $grid];
    }

    public function testBelowTheGatesItIsNotReadyAndAdvisesNothing(): void
    {
        $model = RiskModel::fit(self::corpus(20));

        $this->assertFalse($model->ready());
        $this->assertSame(20, $model->races());
        $this->assertSame(0.0, $model->weight());
        $this->assertNull($model->recommend(self::driver(), self::SECONDS_PER_PLACE));
    }

    public function testRacesWithoutAReplayTeachPassesButNotMistakes(): void
    {
        $model = RiskModel::fit(self::corpus(200, withReplay: false));

        $this->assertFalse($model->ready(), 'no mistake model without replay rows');
        $this->assertSame(200, $model->races());
        $this->assertSame(0, $model->replayRaces());
    }

    public function testAnEmptyCorpusIsTheFallback(): void
    {
        $model = RiskModel::fallback();

        $this->assertFalse($model->ready());
        $this->assertSame(0, $model->races());
        $this->assertNull($model->recommend(self::driver(), self::SECONDS_PER_PLACE));
    }

    public function testTheBlendWeightGrowsWithTheReplayRacesAndNeverReachesOne(): void
    {
        $small = RiskModel::fit(self::corpus(60));
        $large = RiskModel::fit(self::corpus(300));

        $this->assertTrue($small->ready());
        $this->assertEqualsWithDelta(0.5, $small->weight(), 1e-9);
        $this->assertEqualsWithDelta(300 / 360, $large->weight(), 1e-9);
        $this->assertGreaterThan($small->weight(), $large->weight());
        $this->assertLessThan(1.0, $large->weight());
    }

    public function testRecommendationsAreSnappedToFiveAndInsideTheObservedRange(): void
    {
        $model = RiskModel::fit(self::corpus(300, ['lo' => 20, 'hi' => 40]));

        $advice = $model->recommend(self::driver(), self::SECONDS_PER_PLACE);

        $this->assertNotNull($advice);
        foreach (['overtake', 'defend'] as $key) {
            $this->assertSame(0, $advice[$key] % 5);
            $this->assertGreaterThanOrEqual(20, $advice[$key]);
            $this->assertLessThanOrEqual(40, $advice[$key]);
        }
    }

    public function testManyPassesForFewMistakesPicksTheHighRiskAndCostlyMistakesPickALowerOne(): void
    {
        $cheap = RiskModel::fit(self::corpus(500, ['k_ot' => 1.0]));
        $costly = RiskModel::fit(self::corpus(500, ['k_ot' => 6.0], seed: 22));

        $high = $cheap->recommend(self::driver(), self::SECONDS_PER_PLACE);
        $low = $costly->recommend(self::driver(), self::SECONDS_PER_PLACE);

        $this->assertNotNull($high);
        $this->assertNotNull($low);
        $this->assertGreaterThanOrEqual(55, $high['overtake'], 'passes outweigh mistakes: push');
        $this->assertLessThanOrEqual(40, $low['overtake']);
        $this->assertGreaterThan($low['overtake'], $high['overtake']);
    }

    public function testRainThatMultipliesMistakesLowersTheOvertakeRiskOnlyInTheWet(): void
    {
        $model = RiskModel::fit(self::corpus(600, ['k_ot' => 2.0, 'k_wet' => 5.0]));

        $dry = $model->recommend(self::driver(wet: false), self::SECONDS_PER_PLACE);
        $wet = $model->recommend(self::driver(wet: true), self::SECONDS_PER_PLACE);

        $this->assertNotNull($dry);
        $this->assertNotNull($wet);
        $this->assertGreaterThanOrEqual(55, $dry['overtake']);
        $this->assertLessThanOrEqual(35, $wet['overtake']);
    }

    public function testACalmDriverIsAdvisedMoreRiskThanAnErraticOne(): void
    {
        $model = RiskModel::fit(self::corpus(700, ['k_ot' => 6.0, 'k_con' => 4.0]));

        $calm = $model->recommend(self::driver(con: 200), self::SECONDS_PER_PLACE);
        $erratic = $model->recommend(self::driver(con: 50), self::SECONDS_PER_PLACE);

        $this->assertNotNull($calm);
        $this->assertNotNull($erratic);
        $this->assertGreaterThan($erratic['overtake'], $calm['overtake']);
    }

    public function testCostlyDefendingMistakesLowerTheDefendRisk(): void
    {
        $cheap = RiskModel::fit(self::corpus(500, ['k_def' => 0.2]));
        $costly = RiskModel::fit(self::corpus(500, ['k_def' => 7.0], seed: 23));

        $high = $cheap->recommend(self::driver(), self::SECONDS_PER_PLACE);
        $low = $costly->recommend(self::driver(), self::SECONDS_PER_PLACE);

        $this->assertNotNull($high);
        $this->assertNotNull($low);
        $this->assertGreaterThan($low['defend'], $high['defend']);
    }

    public function testBusyTrafficFromTheGridSlotRaisesTheForecastMistakes(): void
    {
        $model = RiskModel::fit(self::corpus(600, ['k_traffic' => 4.0]));

        $front = $model->forecast(self::driver(grid: 2), 40, 40);
        $back = $model->forecast(self::driver(grid: 18), 40, 40);

        $this->assertNotNull($front);
        $this->assertNotNull($back);
        $this->assertGreaterThan($front['mistakes'], $back['mistakes']);
    }

    public function testTheForecastNamesWhatMoreRiskBuys(): void
    {
        $model = RiskModel::fit(self::corpus(500));

        $low = $model->forecast(self::driver(), 20, 20);
        $high = $model->forecast(self::driver(), 50, 20);

        $this->assertNotNull($low);
        $this->assertNotNull($high);
        $this->assertGreaterThan($low['places'], $high['places']);
        $this->assertGreaterThan($low['mistakes'], $high['mistakes']);
    }

    public function testItRoundTripsThroughAJsonCache(): void
    {
        $model = RiskModel::fit(self::corpus(300));

        $copy = RiskModel::fromArray(json_decode((string) json_encode($model->toArray()), true));

        $this->assertTrue($copy->ready());
        $this->assertSame($model->races(), $copy->races());
        $this->assertSame($model->weight(), $copy->weight());
        $this->assertSame(
            $model->recommend(self::driver(wet: true), self::SECONDS_PER_PLACE),
            $copy->recommend(self::driver(wet: true), self::SECONDS_PER_PLACE),
        );
    }

    public function testAWetRaceIsForecastAtTheWetShareTheCorpusActuallyHad(): void
    {
        $model = RiskModel::fit(self::corpus(600, ['k_ot' => 2.0, 'k_wet' => 5.0, 'wet_laps' => 0.2]));

        $this->assertEqualsWithDelta(0.2, $model->typicalWet(), 1e-9);
        $wet = $model->forecast(self::driver(wet: true), 40, 40);
        $dry = $model->forecast(self::driver(wet: false), 40, 40);

        $this->assertNotNull($wet);
        $this->assertNotNull($dry);
        $this->assertGreaterThan($dry['mistakes'], $wet['mistakes']);
        $this->assertLessThan(3.0 * $dry['mistakes'] + 3.0, $wet['mistakes'], 'no extrapolation to a fully wet race');
    }

    public function testRacesWithSomeWetLapsCountAsWetForTheRangeAndTheMistakeCost(): void
    {
        $model = RiskModel::fit(self::corpus(400, ['wet_laps' => 0.15, 'lo' => 10, 'hi' => 60]));
        $cached = $model->toArray();

        $this->assertArrayHasKey('wet', $cached['range']);
        $this->assertArrayHasKey('wet', $cached['seconds_per_mistake']);
        $this->assertEqualsWithDelta(5.0, $cached['seconds_per_mistake']['wet'], 1e-9);
    }
}
