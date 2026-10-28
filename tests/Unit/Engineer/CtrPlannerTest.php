<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\CtrCeiling;
use App\Engineer\CtrPlanner;
use App\Engineer\SeasonBudget;
use App\Engineer\SeasonObjective;
use App\Service\CarWearService;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(CtrPlanner::class)]
final class CtrPlannerTest extends TestCase
{
    private const array ENGINE = ['Engine' => ['level' => 1, 'start' => 50.0, 'track_base' => 10.0]];
    private const array PRICES = ['Engine' => 100_000, 'Chassis' => 200_000];

    private SeasonBudget $budget;
    private CtrPlanner $planner;

    protected function setUp(): void
    {
        $wear = new CarWearService(new PDO('sqlite::memory:'), [
            'driver_wear_factors' => [],
            'part_level_factors' => [1 => 1.02, 2 => 1.03],
        ]);
        $this->budget = new SeasonBudget($wear);
        $this->planner = new CtrPlanner(new CtrCeiling($wear), $this->budget);
    }

    /**
     * @return non-empty-list<array{race: int, track_name: ?string, base: ?array<string, float>}>
     */
    private function rounds(int $count = 3): array
    {
        $rounds = [];
        for ($i = 0; $i < $count; $i++) {
            $rounds[] = [
                'race' => 4 + $i,
                'track_name' => 'T' . (4 + $i),
                'base' => ['Engine' => 10.0, 'Chassis' => 10.0],
            ];
        }

        return $rounds;
    }

    /**
     * @param array<string, array{level: int, start: int|float, track_base: int|float, training?: int|float}> $parts
     * @return array<string, mixed>
     */
    private function plan(
        string $objective,
        int $cash,
        int $committed = 0,
        int $income = 0,
        ?int $seasonStart = null,
        ?array $parts = null,
        int $races = 3,
    ): array {
        return $this->planner->plan(
            $objective,
            $this->rounds($races),
            $parts ?? self::ENGINE,
            self::PRICES,
            1.0,
            $cash,
            $committed,
            $income,
            $seasonStart,
        );
    }

    public function testPlanNeverReadsTheCurrentCtr(): void
    {
        foreach ((new ReflectionMethod(CtrPlanner::class, 'plan'))->getParameters() as $param) {
            $this->assertStringNotContainsString('current', strtolower($param->getName()));
            $this->assertStringNotContainsString('ctr', strtolower($param->getName()));
        }
    }

    public function testAPlanNeedsAtLeastOneRace(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->planner->plan(SeasonObjective::HOLD, [], self::ENGINE, self::PRICES, 1.0, 1_000_000, 0, 0, null);
    }

    public function testBudgetCeilingIsTheHighestPassingCtr(): void
    {
        $scenarios = [];
        foreach ([0, 40_000, 80_000, 120_000, 150_000, 250_000, 400_000, 1_000_000] as $cash) {
            $scenarios[] = [SeasonObjective::PROMOTE, $cash, 0, 0, null];
        }
        foreach ([300_000, 600_000, 900_000, 2_000_000] as $cash) {
            $scenarios[] = [SeasonObjective::PROMOTE, $cash, 50_000, 30_000, null];
            $scenarios[] = [SeasonObjective::HOLD, $cash, 50_000, 30_000, 400_000];
        }
        $rounds = $this->rounds();

        foreach ($scenarios as [$objective, $cash, $committed, $income, $start]) {
            $label = "$objective cash $cash committed $committed";
            $plan = $this->plan($objective, $cash, $committed, $income, $start);
            $at = fn (int $c): array => $this->budget->forecast(
                $rounds,
                self::ENGINE,
                self::PRICES,
                1.0,
                $c,
                100,
                $cash,
                $committed,
                $income,
            )['rows'];

            $cushion = $committed + intdiv(array_sum(array_column($at($plan['safety']), 'repairs')), 3);
            $end = $start !== null && $start > $cushion ? $start : $cushion;
            $this->assertSame($cushion, $plan['cushion'], $label);
            $this->assertSame($end, $plan['target'], $label);

            $expected = null;
            for ($c = 100; $c >= 0; $c--) {
                $rows = $at($c);
                $ok = $rows[2]['balance'] >= $end
                    && min(array_column($rows, 'balance')) >= $cushion
                    && ($c <= $plan['safety'] || $rows[0]['replaced'] !== []);
                if ($ok) {
                    $expected = $c;
                    break;
                }
            }

            $this->assertSame($expected ?? 0, $plan['ctr'], $label);
            $this->assertSame(
                $expected === null ? 'short' : ($expected > $plan['safety'] ? 'push' : 'nurse'),
                $plan['mode'],
                $label,
            );
        }
    }

    public function testHoldTargetIsTheSeasonStartWhenHigherThanTheCushion(): void
    {
        $plan = $this->plan(SeasonObjective::HOLD, 1_000_000, seasonStart: 500_000);

        $this->assertSame('start', $plan['target_kind']);
        $this->assertSame(500_000, $plan['target']);
        $this->assertSame(500_000, $plan['season_start']);
        $this->assertGreaterThanOrEqual(500_000, $plan['rows'][array_key_last($plan['rows'])]['balance']);
    }

    public function testHoldStartTargetIsJudgedAtSeasonEnd(): void
    {
        // Race 10 of 17: cash dips below the start mid-season, then income lifts it $3M above by the end.
        $rounds = [];
        for ($race = 10; $race <= 17; $race++) {
            $rounds[] = ['race' => $race, 'track_name' => 'T' . $race, 'base' => ['Engine' => 1.0]];
        }
        $parts = ['Engine' => ['level' => 1, 'start' => 0.0, 'track_base' => 1.0]];

        $plan = $this->planner->plan(
            SeasonObjective::HOLD,
            $rounds,
            $parts,
            self::PRICES,
            1.0,
            19_000_000,
            2_000_000,
            2_500_000,
            20_000_000,
        );

        $balances = array_column($plan['rows'], 'balance');
        $this->assertSame(19_500_000, $balances[0]);
        $this->assertSame(23_000_000, $balances[7]);
        $this->assertSame('start', $plan['target_kind']);
        $this->assertSame(20_000_000, $plan['target']);
        $this->assertSame(2_000_000, $plan['cushion']);
        $this->assertNotSame('short', $plan['mode']);
        $this->assertSame(100, $plan['ctr']);
        $this->assertSame(min(min($balances) - 2_000_000, 23_000_000 - 20_000_000), $plan['envelope']);
        $this->assertSame(3_000_000, $plan['envelope']);
    }

    public function testShortfallIsTheWidestMissOfAnyRowsOwnTarget(): void
    {
        $plan = $this->plan(SeasonObjective::HOLD, 1_000_000, 100_000, 0, 1_500_000);

        $this->assertSame('short', $plan['mode']);
        $rows = $plan['rows'];
        $last = count($rows) - 1;
        $misses = [];
        foreach ($rows as $i => $row) {
            $misses[] = ($i === $last ? $plan['target'] : $plan['cushion']) - $row['balance'];
        }
        $this->assertSame(max($misses), $plan['shortfall']);
    }

    public function testHoldTargetIsTheCushionWhenTheStartIsLowerOrUnknown(): void
    {
        $low = $this->plan(SeasonObjective::HOLD, 1_000_000, seasonStart: 1_000);
        $this->assertSame('cushion', $low['target_kind']);
        $this->assertSame($low['cushion'], $low['target']);

        $unknown = $this->plan(SeasonObjective::HOLD, 1_000_000);
        $this->assertSame('cushion', $unknown['target_kind']);
        $this->assertNull($unknown['season_start']);
    }

    public function testPromoteTargetIsTheCushion(): void
    {
        $plan = $this->plan(SeasonObjective::PROMOTE, 1_000_000, 50_000, 0, 900_000);

        $this->assertSame('cushion', $plan['target_kind']);
        $this->assertSame($plan['cushion'], $plan['target']);
        $this->assertSame(50_000 + 33_333, $plan['cushion']);
    }

    public function testSurviveFallsBackToTheCushionWhenTheStartIsOutOfReach(): void
    {
        $plan = $this->plan(SeasonObjective::SURVIVE, 1_000_000, seasonStart: 5_000_000);

        $this->assertSame('start_out_of_reach', $plan['target_kind']);
        $this->assertSame($plan['cushion'], $plan['target']);
        $this->assertSame(5_000_000, $plan['season_start']);
        $this->assertNotSame('short', $plan['mode']);
    }

    public function testSurviveKeepsTheStartWhenCtrZeroReachesIt(): void
    {
        $plan = $this->plan(SeasonObjective::SURVIVE, 1_000_000, seasonStart: 900_000);

        $this->assertSame('start', $plan['target_kind']);
        $this->assertSame(900_000, $plan['target']);
    }

    public function testEnvelopeIsTheLowestHeadroomNotTheLastBalance(): void
    {
        $plan = $this->plan(SeasonObjective::PROMOTE, 100_000, 0, 50_000);

        $balances = array_column($plan['rows'], 'balance');
        $this->assertSame([150_000, 100_000, 150_000], $balances);
        $this->assertSame(100_000 - 33_333, $plan['envelope']);
    }

    public function testPushSwapsWhenTheBudgetCoversThem(): void
    {
        $plan = $this->plan(SeasonObjective::HOLD, 1_000_000);

        $this->assertSame('push', $plan['mode']);
        $this->assertLessThan(100, $plan['safety']);
        $this->assertGreaterThan($plan['safety'], $plan['ctr']);
        $this->assertSame([['part' => 'Engine', 'cost' => 100_000]], $plan['swaps']);
        $this->assertNull($plan['refused_swap_cost']);
    }

    public function testNurseAndRefusedSwapCostWhenTheBudgetCannotCoverThem(): void
    {
        $plan = $this->plan(SeasonObjective::HOLD, 150_000);

        $this->assertSame('nurse', $plan['mode']);
        $this->assertSame($plan['safety'], $plan['ctr']);
        $this->assertSame([], $plan['swaps']);
        $this->assertSame(100_000, $plan['refused_swap_cost']);
    }

    public function testRefusedSwapCostIsNullWhenNothingCanBeQuoted(): void
    {
        $rounds = $this->rounds();
        $rounds[0]['base'] = null;

        $plan = $this->planner->plan(SeasonObjective::HOLD, $rounds, self::ENGINE, self::PRICES, 1.0, 150_000, 0, 0, null);

        $this->assertSame('nurse', $plan['mode']);
        $this->assertLessThan(100, $plan['safety']);
        $this->assertNull($plan['refused_swap_cost']);
    }

    public function testPushIsNotAdvisedWhenASwapPriceIsUnknown(): void
    {
        $plan = $this->planner->plan(
            SeasonObjective::HOLD,
            $this->rounds(),
            self::ENGINE,
            ['Engine' => null],
            1.0,
            10_000_000,
            0,
            0,
            null,
        );

        $this->assertSame('nurse', $plan['mode']);
        $this->assertSame($plan['safety'], $plan['ctr']);
        $this->assertSame([], $plan['swaps']);
    }

    public function testPushNeedsASwapToPayFor(): void
    {
        // Round 0's wear is unknown, so nothing is replaced in it at any CTR: there is no swap to push on.
        $rounds = $this->rounds();
        $rounds[0]['base'] = null;

        $plan = $this->planner->plan(SeasonObjective::HOLD, $rounds, self::ENGINE, self::PRICES, 1.0, 10_000_000, 0, 0, null);

        $this->assertLessThan(100, $plan['safety']);
        $this->assertSame('nurse', $plan['mode']);
        $this->assertSame($plan['safety'], $plan['ctr']);
    }

    public function testACtrAtWhichAFreshPartWouldReachTheLimitIsNotAdvised(): void
    {
        $parts = ['Engine' => ['level' => 1, 'start' => 50.0, 'track_base' => 20.0]];
        $rounds = $this->rounds();
        foreach ($rounds as $i => $round) {
            $rounds[$i]['base'] = ['Engine' => 20.0];
        }

        $plan = $this->planner->plan(SeasonObjective::HOLD, $rounds, $parts, self::PRICES, 1.0, 10_000_000, 0, 0, null);

        $this->assertSame('push', $plan['mode']);
        $this->assertLessThan(100.0, 20.0 * 1.02 ** $plan['ctr']);
        $this->assertGreaterThanOrEqual(100.0, 20.0 * 1.02 ** ($plan['ctr'] + 1));
    }

    public function testSurviveNeverPushes(): void
    {
        $plan = $this->plan(SeasonObjective::SURVIVE, 50_000_000);

        $this->assertLessThanOrEqual($plan['safety'], $plan['ctr']);
        $this->assertSame('nurse', $plan['mode']);
        $this->assertSame([], $plan['swaps']);
        $this->assertNull($plan['refused_swap_cost']);
    }

    public function testSurviveSafetyUsesTheRiskyLimit(): void
    {
        $survive = $this->plan(SeasonObjective::SURVIVE, 50_000_000);
        $hold = $this->plan(SeasonObjective::HOLD, 50_000_000);

        $this->assertLessThan($hold['safety'], $survive['safety']);
    }

    public function testShortWhenEvenCtrZeroMissesTheTarget(): void
    {
        $plan = $this->plan(SeasonObjective::PROMOTE, 0, 100_000);

        $this->assertSame('short', $plan['mode']);
        $this->assertSame(0, $plan['ctr']);
        $this->assertGreaterThan(0, $plan['shortfall']);
        $this->assertSame(0, $plan['envelope']);
        $this->assertSame([], $plan['swaps']);
        $this->assertSame(
            $plan['target'] - min(array_column($plan['rows'], 'balance')),
            $plan['shortfall'],
        );
    }

    public function testForcedAtZeroPartsAreNotPushSwaps(): void
    {
        $parts = self::ENGINE + ['Chassis' => ['level' => 1, 'start' => 99.0, 'track_base' => 10.0]];
        $plan = $this->plan(SeasonObjective::HOLD, 10_000_000, parts: $parts);

        $this->assertSame('push', $plan['mode']);
        $this->assertSame([['part' => 'Engine', 'cost' => 100_000]], $plan['swaps']);
        $replaced = array_column($plan['rows'][0]['replaced'], 'part');
        $this->assertContains('Chassis', $replaced);
        $this->assertContains('Engine', $replaced);
    }

    public function testForcedAtZeroPartsAreNotRefusedSwaps(): void
    {
        $parts = self::ENGINE + ['Chassis' => ['level' => 1, 'start' => 99.0, 'track_base' => 10.0]];
        $plan = $this->plan(SeasonObjective::HOLD, 400_000, parts: $parts);

        $this->assertSame('nurse', $plan['mode']);
        $this->assertSame(100_000, $plan['refused_swap_cost']);
    }

    public function testLastRaceOfTheSeasonPlansOneRow(): void
    {
        $plan = $this->plan(SeasonObjective::HOLD, 1_000_000, races: 1);

        $this->assertCount(1, $plan['rows']);
        $this->assertGreaterThanOrEqual(0, $plan['envelope']);
        $this->assertSame(4, $plan['race']);
    }

    public function testPlanSaysWhetherItsForecastIsInfeasible(): void
    {
        $this->assertFalse($this->plan(SeasonObjective::HOLD, 10_000_000)['infeasible']);

        // A fresh engine would reach the limit inside one race even at CTR 0 (110 > 100).
        $parts = ['Engine' => ['level' => 1, 'start' => 50.0, 'track_base' => 110.0]];
        $rounds = $this->rounds();
        foreach ($rounds as $i => $round) {
            $rounds[$i]['base'] = ['Engine' => 110.0];
        }
        $plan = $this->planner->plan(SeasonObjective::HOLD, $rounds, $parts, self::PRICES, 1.0, 10_000_000, 0, 0, null);

        $this->assertTrue($plan['infeasible']);
    }

    public function testPlanCarriesVersionAndPartial(): void
    {
        $rounds = $this->rounds();
        $rounds[1]['base'] = null;

        $plan = $this->planner->plan(SeasonObjective::HOLD, $rounds, self::ENGINE, self::PRICES, 1.0, 1_000_000, 0, 0, null);

        $this->assertSame(1, $plan['v']);
        $this->assertSame(['T5 wear unknown'], $plan['partial']);
    }
}
