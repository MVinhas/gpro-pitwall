<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\CtrCeiling;
use App\Engineer\CtrPlanner;
use App\Engineer\CtrPolicy;
use App\Engineer\CtrValue;
use App\Engineer\SeasonBudget;
use App\Engineer\SeasonObjective;
use App\Service\CarWearService;
use App\Service\WearAdvisorService;
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
        bool $carLocked = false,
        ?CtrPolicy $policy = null,
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
            $carLocked,
            $policy,
        );
    }

    private static function policy(
        int $gainPerPoint = 0,
        int $cap = 100,
        bool $safety = false,
        float $placesPerPoint = 0.0,
        bool $paceKnown = true,
    ): CtrPolicy {
        return new CtrPolicy($cap, false, 90, $gainPerPoint, $placesPerPoint, $paceKnown, $safety);
    }

    /**
     * The highest CTR whose forecast passes the budget check alone, scanned the plan's way.
     *
     * @param array<string, mixed> $plan
     */
    private function highestBudgetCandidate(array $plan, int $cash): int
    {
        for ($c = 100; $c >= 0; $c--) {
            $forecast = $this->budget->forecast($this->rounds(), self::ENGINE, self::PRICES, 1.0, $c, 100, $cash, 0, 0);
            $cushion = $plan['cushion'];
            $target = $plan['target'];
            if (
                !($forecast['infeasible'] && $c > 0)
                && CtrPlanner::headroom($forecast['rows'], $cushion, $target) >= 0
            ) {
                return $c;
            }
        }

        return -1;
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

        $this->planner->plan(SeasonObjective::STAY, [], self::ENGINE, self::PRICES, 1.0, 1_000_000, 0, 0, null);
    }

    public function testCushionIsTheSameBeforeAndAfterTheCarUpdate(): void
    {
        $parts = static fn (float $engineStart): array => [
            'Engine' => ['level' => 1, 'start' => $engineStart, 'track_base' => 10.0],
            'Chassis' => ['level' => 1, 'start' => 20.0, 'track_base' => 10.0],
        ];

        $before = $this->plan(SeasonObjective::STAY, 3_000_000, 50_000, 30_000, null, $parts(95.0), 5);
        $after = $this->plan(
            SeasonObjective::STAY,
            3_000_000 - self::PRICES['Engine'],
            50_000,
            30_000,
            null,
            $parts(0.0),
            5,
        );

        $this->assertSame('Engine', $before['rows'][0]['replaced'][0]['part']);
        $this->assertSame([], $after['rows'][0]['replaced']);
        $this->assertSame($before['cushion'], $after['cushion']);
        $this->assertSame($before['ctr'], $after['ctr']);
        $this->assertSame($before['mode'], $after['mode']);
        $this->assertSame($before['envelope'], $after['envelope']);
    }

    public function testSingleRaceCushionIsTheCommittedCost(): void
    {
        $worn = ['Engine' => ['level' => 1, 'start' => 95.0, 'track_base' => 10.0]];

        $plan = $this->plan(SeasonObjective::STAY, 1_000_000, 50_000, 0, null, $worn, 1);

        $this->assertSame(50_000, $plan['cushion']);
    }

    public function testBudgetCeilingIsTheHighestPassingCtr(): void
    {
        $scenarios = [];
        foreach ([0, 40_000, 80_000, 120_000, 150_000, 250_000, 400_000, 1_000_000] as $cash) {
            $scenarios[] = [SeasonObjective::PROMOTE, $cash, 0, 0, null];
        }
        foreach ([300_000, 600_000, 900_000, 2_000_000] as $cash) {
            $scenarios[] = [SeasonObjective::PROMOTE, $cash, 50_000, 30_000, null];
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

            $cushion = $committed + intdiv(array_sum(array_column(array_slice($at($plan['safety']), 1), 'repairs')), 2);
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

    public function testStayTargetIsTheSeasonStartWhenHigherThanTheCushion(): void
    {
        $plan = $this->plan(SeasonObjective::STAY, 1_000_000, seasonStart: 500_000);

        $this->assertSame('start', $plan['target_kind']);
        $this->assertSame(500_000, $plan['target']);
        $this->assertSame(500_000, $plan['season_start']);
        $this->assertGreaterThanOrEqual(500_000, $plan['rows'][array_key_last($plan['rows'])]['balance']);
    }

    public function testStayStartTargetIsJudgedAtSeasonEnd(): void
    {
        // Race 10 of 17: cash dips below the start mid-season, then income lifts it $3M above by the end.
        $rounds = [];
        for ($race = 10; $race <= 17; $race++) {
            $rounds[] = ['race' => $race, 'track_name' => 'T' . $race, 'base' => ['Engine' => 1.0]];
        }
        $parts = ['Engine' => ['level' => 1, 'start' => 0.0, 'track_base' => 1.0]];

        $plan = $this->planner->plan(
            SeasonObjective::STAY,
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
        // No pace gain and no repairs at any CTR: nothing pays for a higher one.
        $this->assertSame(0, $plan['ctr']);
        $this->assertSame('value', $plan['ctr_kind']);
        $this->assertSame(min(min($balances) - 2_000_000, 23_000_000 - 20_000_000), $plan['envelope']);
        $this->assertSame(3_000_000, $plan['envelope']);
    }

    public function testShortfallIsTheWidestMissOfAnyRowsOwnTarget(): void
    {
        $plan = $this->plan(SeasonObjective::STAY, 0, 100_000);

        $this->assertSame('short', $plan['mode']);
        $rows = $plan['rows'];
        $last = count($rows) - 1;
        $misses = [];
        foreach ($rows as $i => $row) {
            $misses[] = ($i === $last ? $plan['target'] : $plan['cushion']) - $row['balance'];
        }
        $this->assertSame(max($misses), $plan['shortfall']);
    }

    public function testStayTargetIsTheCushionWhenTheStartIsLowerOrUnknown(): void
    {
        $low = $this->plan(SeasonObjective::STAY, 1_000_000, seasonStart: 1_000);
        $this->assertSame('cushion', $low['target_kind']);
        $this->assertSame($low['cushion'], $low['target']);

        $unknown = $this->plan(SeasonObjective::STAY, 1_000_000);
        $this->assertSame('cushion', $unknown['target_kind']);
        $this->assertNull($unknown['season_start']);
    }

    public function testPromoteKeepsHalfTheSeasonStartWhenThatIsAboveTheCushion(): void
    {
        $plan = $this->plan(SeasonObjective::PROMOTE, 12_000_000, 50_000, 0, 10_000_000);

        $this->assertLessThan(5_000_000, $plan['cushion']);
        $this->assertSame(5_000_000, $plan['target']);
        $this->assertSame('reserve', $plan['target_kind']);
        $this->assertSame(CtrPlanner::PROMOTE_RESERVE, 0.5);
    }

    public function testPromoteKeepsTheCushionWhenItIsAboveHalfTheStart(): void
    {
        $plan = $this->plan(SeasonObjective::PROMOTE, 1_000_000, 50_000, 0, 100_000);

        $this->assertGreaterThan((int) ceil(100_000 / 2), $plan['cushion']);
        $this->assertSame('cushion', $plan['target_kind']);
        $this->assertSame($plan['cushion'], $plan['target']);
        $this->assertSame(50_000 + 50_000, $plan['cushion']);
    }

    public function testPromoteFallsBackToTheCushionWhenHalfTheStartIsOutOfReach(): void
    {
        $plan = $this->plan(SeasonObjective::PROMOTE, 1_000_000, 50_000, 0, 10_000_000);

        $this->assertSame('reserve_out_of_reach', $plan['target_kind']);
        $this->assertSame($plan['cushion'], $plan['target']);
        $this->assertSame(10_000_000, $plan['season_start']);
        $this->assertNotSame('short', $plan['mode']);
    }

    public function testPromoteWithoutASeasonStartKeepsTheCushion(): void
    {
        $plan = $this->plan(SeasonObjective::PROMOTE, 1_000_000, 50_000);

        $this->assertSame('cushion', $plan['target_kind']);
        $this->assertSame($plan['cushion'], $plan['target']);
    }

    public function testStayFallsBackToTheCushionWhenTheStartIsOutOfReach(): void
    {
        $plan = $this->plan(SeasonObjective::STAY, 1_000_000, seasonStart: 5_000_000);

        $this->assertSame('start_out_of_reach', $plan['target_kind']);
        $this->assertSame($plan['cushion'], $plan['target']);
        $this->assertSame(5_000_000, $plan['season_start']);
        $this->assertNotSame('short', $plan['mode']);
    }

    public function testStayKeepsTheStartWhenCtrZeroReachesIt(): void
    {
        $plan = $this->plan(SeasonObjective::STAY, 1_000_000, seasonStart: 900_000);

        $this->assertSame('start', $plan['target_kind']);
        $this->assertSame(900_000, $plan['target']);
    }

    public function testEnvelopeIsTheLowestHeadroomNotTheLastBalance(): void
    {
        $plan = $this->plan(SeasonObjective::PROMOTE, 100_000, 0, 50_000);

        $balances = array_column($plan['rows'], 'balance');
        $this->assertSame([150_000, 100_000, 150_000], $balances);
        $this->assertSame(100_000 - 50_000, $plan['envelope']);
    }

    public function testPushSwapsWhenTheBudgetCoversThem(): void
    {
        $plan = $this->plan(SeasonObjective::PROMOTE, 1_000_000);

        $this->assertSame('push', $plan['mode']);
        $this->assertLessThan(100, $plan['safety']);
        $this->assertGreaterThan($plan['safety'], $plan['ctr']);
        $this->assertSame([['part' => 'Engine', 'cost' => 100_000]], $plan['swaps']);
        $this->assertNull($plan['refused_swap_cost']);
    }

    public function testNurseAndRefusedSwapCostWhenTheBudgetCannotCoverThem(): void
    {
        $plan = $this->plan(SeasonObjective::PROMOTE, 150_000);

        $this->assertSame('nurse', $plan['mode']);
        $this->assertSame($plan['safety'], $plan['ctr']);
        $this->assertSame([], $plan['swaps']);
        $this->assertSame(100_000, $plan['refused_swap_cost']);
    }

    public function testRefusedSwapCostIsNullWhenNothingCanBeQuoted(): void
    {
        $rounds = $this->rounds();
        $rounds[0]['base'] = null;

        $plan = $this->planner->plan(SeasonObjective::PROMOTE, $rounds, self::ENGINE, self::PRICES, 1.0, 150_000, 0, 0, null);

        $this->assertSame('nurse', $plan['mode']);
        $this->assertLessThan(100, $plan['safety']);
        $this->assertNull($plan['refused_swap_cost']);
    }

    public function testPushIsNotAdvisedWhenASwapPriceIsUnknown(): void
    {
        $plan = $this->planner->plan(
            SeasonObjective::PROMOTE,
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

        $plan = $this->planner->plan(SeasonObjective::PROMOTE, $rounds, self::ENGINE, self::PRICES, 1.0, 10_000_000, 0, 0, null);

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

        $plan = $this->planner->plan(SeasonObjective::PROMOTE, $rounds, $parts, self::PRICES, 1.0, 10_000_000, 0, 0, null);

        $this->assertSame('push', $plan['mode']);
        $this->assertLessThan(100, WearAdvisorService::percent(round(20.0 * 1.02 ** $plan['ctr'], 1)));
        $this->assertGreaterThanOrEqual(100, WearAdvisorService::percent(round(20.0 * 1.02 ** ($plan['ctr'] + 1), 1)));
    }

    public function testStayWithoutAGainNeverPushes(): void
    {
        $plan = $this->plan(SeasonObjective::STAY, 50_000_000);

        $this->assertSame(0, $plan['ctr']);
        $this->assertLessThanOrEqual($plan['safety'], $plan['ctr']);
        $this->assertSame('nurse', $plan['mode']);
        $this->assertSame([], $plan['swaps']);
        $this->assertNull($plan['refused_swap_cost']);
    }

    public function testStayKeepsAPartThatEndsTheRaceInTheRedZone(): void
    {
        $perRace = round((new CarWearService(new PDO('sqlite::memory:'), [
            'driver_wear_factors' => [],
            'part_level_factors' => [1 => 1.02, 2 => 1.03],
        ]))->raceWear(10.0, 1, 1.0, 0), 1);
        $parts = ['Engine' => ['level' => 1, 'start' => 95.0 - $perRace, 'track_base' => 10.0]];

        $plan = $this->plan(SeasonObjective::STAY, 50_000_000, parts: $parts);

        $this->assertSame([], $plan['rows'][0]['replaced']);
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
        $plan = $this->plan(SeasonObjective::PROMOTE, 10_000_000, parts: $parts);

        $this->assertSame('push', $plan['mode']);
        $this->assertSame([['part' => 'Engine', 'cost' => 100_000]], $plan['swaps']);
        $replaced = array_column($plan['rows'][0]['replaced'], 'part');
        $this->assertContains('Chassis', $replaced);
        $this->assertContains('Engine', $replaced);
    }

    public function testForcedAtZeroPartsAreNotRefusedSwaps(): void
    {
        $parts = self::ENGINE + ['Chassis' => ['level' => 1, 'start' => 99.0, 'track_base' => 10.0]];
        $plan = $this->plan(SeasonObjective::PROMOTE, 400_000, parts: $parts);

        $this->assertSame('nurse', $plan['mode']);
        $this->assertSame(100_000, $plan['refused_swap_cost']);
    }

    public function testLastRaceOfTheSeasonPlansOneRow(): void
    {
        $plan = $this->plan(SeasonObjective::STAY, 1_000_000, races: 1);

        $this->assertCount(1, $plan['rows']);
        $this->assertGreaterThanOrEqual(0, $plan['envelope']);
        $this->assertSame(4, $plan['race']);
    }

    public function testPlanSaysWhetherItsForecastIsInfeasible(): void
    {
        $this->assertFalse($this->plan(SeasonObjective::STAY, 10_000_000)['infeasible']);

        // A fresh engine would reach the limit inside one race even at CTR 0 (110 > 100).
        $parts = ['Engine' => ['level' => 1, 'start' => 50.0, 'track_base' => 110.0]];
        $rounds = $this->rounds();
        foreach ($rounds as $i => $round) {
            $rounds[$i]['base'] = ['Engine' => 110.0];
        }
        $plan = $this->planner->plan(SeasonObjective::STAY, $rounds, $parts, self::PRICES, 1.0, 10_000_000, 0, 0, null);

        $this->assertTrue($plan['infeasible']);
    }

    public function testPlanCarriesVersionAndPartial(): void
    {
        $rounds = $this->rounds();
        $rounds[1]['base'] = null;

        $plan = $this->planner->plan(SeasonObjective::STAY, $rounds, self::ENGINE, self::PRICES, 1.0, 1_000_000, 0, 0, null);

        $this->assertSame(2, $plan['v']);
        $this->assertSame(['T5 wear unknown'], $plan['partial']);
    }

    public function testALockedCarWithAStuckPartAdvisesCtrZeroAndNeverPushes(): void
    {
        $parts = [
            'Engine' => ['level' => 1, 'start' => 95.0, 'track_base' => 10.0],
            'Chassis' => ['level' => 1, 'start' => 0.0, 'track_base' => 10.0],
        ];

        $plan = $this->plan(SeasonObjective::PROMOTE, 5_000_000, parts: $parts, carLocked: true);

        $this->assertSame(0, $plan['safety']);
        $this->assertSame(0, $plan['ctr']);
        $this->assertSame('nurse', $plan['mode']);
        $this->assertSame([], $plan['swaps']);
    }

    public function testALockedHealthyCarPlansLikeAnUnlockedOne(): void
    {
        $parts = [
            'Engine' => ['level' => 1, 'start' => 10.0, 'track_base' => 10.0],
            'Chassis' => ['level' => 1, 'start' => 5.0, 'track_base' => 10.0],
        ];

        $this->assertSame(
            $this->plan(SeasonObjective::STAY, 2_000_000, 100_000, 50_000, parts: $parts),
            $this->plan(SeasonObjective::STAY, 2_000_000, 100_000, 50_000, parts: $parts, carLocked: true),
        );
    }

    public function testALockedHealthyCarNeverPushesOnASwapItCannotBuy(): void
    {
        $parts = [
            'Engine' => ['level' => 1, 'start' => 10.0, 'track_base' => 20.0],
            'Chassis' => ['level' => 1, 'start' => 5.0, 'track_base' => 10.0],
        ];
        $rounds = $this->rounds();
        foreach ($rounds as $i => $round) {
            $rounds[$i]['base'] = ['Engine' => 20.0, 'Chassis' => 10.0];
        }
        $plan = fn (bool $locked): array => $this->planner->plan(
            SeasonObjective::PROMOTE,
            $rounds,
            $parts,
            self::PRICES,
            1.0,
            10_000_000,
            0,
            0,
            null,
            $locked,
        );

        $this->assertSame('push', $plan(false)['mode']);
        $this->assertGreaterThan($plan(false)['safety'], $plan(false)['ctr']);

        $locked = $plan(true);

        $this->assertSame('nurse', $locked['mode']);
        $this->assertLessThanOrEqual($locked['safety'], $locked['ctr']);
        $this->assertSame([], $locked['swaps']);
    }

    public function test_stay_picks_lowest_clearly_better_ctr(): void
    {
        // $2,000 a point never pays for a swap ($100,000 from CTR 25, $300,000 above the safety ceiling).
        $plan = $this->plan(SeasonObjective::STAY, 1_000_000, policy: self::policy(2_000));

        $this->assertSame(80, $plan['safety']);
        $this->assertLessThanOrEqual($plan['safety'], $plan['ctr']);
        $this->assertSame(0, $plan['ctr']);
        $this->assertSame('value', $plan['ctr_kind']);
        $this->assertSame('nurse', $plan['mode']);
        $this->assertNull($plan['refused_swap_cost']);
        $this->assertSame(0, $plan['value']['gain']);
        $this->assertSame(0, $plan['value']['cost']);
        // CTR 80, the safety ceiling, is the best step up: $160,000 of pace less the $100,000 swap, under the bar.
        $this->assertSame(
            ['ctr' => 80, 'gain_more' => 160_000, 'cost_more' => 100_000, 'net_more' => 60_000],
            $plan['value']['next'],
        );
    }

    public function test_stay_moves_up_when_gain_clearly_beats_repairs(): void
    {
        $plan = $this->plan(SeasonObjective::STAY, 1_000_000, policy: self::policy(20_000, placesPerPoint: 0.04));

        $this->assertGreaterThan(0, $plan['ctr']);
        $this->assertSame('value', $plan['ctr_kind']);
        $this->assertSame($plan['ctr'] * 20_000, $plan['value']['gain']);
        $this->assertGreaterThan(CtrValue::MARGIN, $plan['value']['gain'] - $plan['value']['cost'], 'CTR 0 is worth 0');
        $this->assertSame(round($plan['ctr'] * 0.04, 1), $plan['value']['places']);
        $this->assertTrue($plan['value']['pace_known']);
        $this->assertSame($plan['ctr'] > $plan['safety'] ? 'push' : 'nurse', $plan['mode']);
    }

    public function test_stay_safety_takes_highest_candidate(): void
    {
        // $120,000 covers no swap: every CTR from 25 up leaves a row under the cushion.
        $plan = $this->plan(SeasonObjective::STAY, 120_000, policy: self::policy(safety: true));

        $this->assertSame($this->highestBudgetCandidate($plan, 120_000), $plan['ctr']);
        $this->assertSame(24, $plan['ctr']);
        $this->assertSame('safety', $plan['ctr_kind']);
        $this->assertSame('nurse', $plan['mode']);
    }

    public function test_stay_safety_with_room_can_push(): void
    {
        $plan = $this->plan(SeasonObjective::STAY, 1_000_000, policy: self::policy(safety: true));

        $this->assertSame($this->highestBudgetCandidate($plan, 1_000_000), $plan['ctr']);
        $this->assertSame(100, $plan['ctr']);
        $this->assertSame('push', $plan['mode']);
        $this->assertNull($plan['refused_swap_cost']);
    }

    public function test_energy_cap_binds_stay(): void
    {
        $plan = $this->plan(SeasonObjective::STAY, 1_000_000, policy: self::policy(1_000_000, cap: 5));

        $this->assertSame(5, $plan['ctr']);
        $this->assertSame('energy', $plan['ctr_kind']);
        $this->assertSame(5, $plan['energy_cap']);
        $this->assertFalse($plan['energy_estimate']);
        $this->assertSame(90, $plan['energy_start']);
        $this->assertNull($plan['value']['next']);
    }

    public function test_safety_beats_energy_when_the_stay_pick_lands_on_the_cap(): void
    {
        $plan = $this->plan(SeasonObjective::STAY, 1_000_000, policy: self::policy(cap: 30, safety: true));

        $this->assertSame(30, $plan['ctr']);
        $this->assertSame('safety', $plan['ctr_kind']);
        $this->assertSame(30, $plan['energy_cap']);
    }

    /**
     * One race where the Engine's swap starts at CTR 36 ($1.2M) and the Chassis's at CTR 41 ($5M more, past the
     * $3M of cash): the budget allows CTR 0 to 40.
     *
     * @return array<string, mixed>
     */
    private function twoStepPlan(CtrPolicy $policy): array
    {
        return $this->planner->plan(
            SeasonObjective::STAY,
            [['race' => 17, 'track_name' => 'T17', 'base' => ['Engine' => 10.0, 'Chassis' => 10.0]]],
            [
                'Engine' => ['level' => 1, 'start' => 79.0, 'track_base' => 10.0],
                'Chassis' => ['level' => 1, 'start' => 76.7, 'track_base' => 10.0],
            ],
            ['Engine' => 1_200_000, 'Chassis' => 5_000_000],
            1.0,
            3_000_000,
            0,
            0,
            null,
            false,
            $policy,
        );
    }

    public function test_a_stay_value_pick_never_hints_above_the_energy_cap(): void
    {
        // Uncapped, CTR 40 nets $50k over CTR 35: the hint every lower cap must not quote.
        $uncapped = $this->twoStepPlan(self::policy(250_000));
        $this->assertSame(35, $uncapped['ctr']);
        $this->assertSame(40, $uncapped['value']['next']['ctr']);

        $plan = $this->twoStepPlan(self::policy(250_000, cap: 38));

        $this->assertSame(35, $plan['ctr']);
        $this->assertSame('value', $plan['ctr_kind']);
        $this->assertLessThanOrEqual($plan['energy_cap'], $plan['value']['next']['ctr']);
        $this->assertSame(
            ['ctr' => 38, 'gain_more' => 750_000, 'cost_more' => 1_200_000, 'net_more' => -450_000],
            $plan['value']['next'],
        );
    }

    public function test_a_stay_safety_pick_at_the_cap_stays_safety(): void
    {
        $this->assertSame(40, $this->twoStepPlan(self::policy(safety: true))['ctr']);

        $plan = $this->twoStepPlan(self::policy(cap: 38, safety: true));

        $this->assertSame(38, $plan['ctr']);
        $this->assertSame('safety', $plan['ctr_kind']);
        $this->assertNull($plan['value']['next']);
    }

    public function test_a_stay_value_pick_on_the_cap_is_energy_only_when_the_cap_held_it_down(): void
    {
        // Uncapped the value rule picks 35 itself: a cap of exactly 35 did not hold anything down.
        $atPick = $this->twoStepPlan(self::policy(250_000, cap: 35));
        $this->assertSame(35, $atPick['ctr']);
        $this->assertSame('value', $atPick['ctr_kind']);
        $this->assertNull($atPick['value']['next']);

        // A cap of 30 sits under the uncapped pick, and the capped pick lands on it.
        $held = $this->twoStepPlan(self::policy(250_000, cap: 30));
        $this->assertSame(30, $held['ctr']);
        $this->assertSame('energy', $held['ctr_kind']);
    }

    public function test_energy_cap_binds_promote(): void
    {
        $this->assertSame(100, $this->plan(SeasonObjective::PROMOTE, 1_000_000)['ctr']);

        $plan = $this->plan(SeasonObjective::PROMOTE, 1_000_000, policy: self::policy(cap: 3));

        $this->assertSame(3, $plan['ctr']);
        $this->assertSame('energy', $plan['ctr_kind']);
        $this->assertSame(3, $plan['energy_cap']);
        $this->assertSame('nurse', $plan['mode']);
        $this->assertNull($plan['value']);
        // Pushing past the safety ceiling would break the cap, so no refused push is quoted.
        $this->assertNull($plan['refused_swap_cost']);
    }

    public function test_a_stay_pick_below_the_cap_keeps_its_own_kind(): void
    {
        $uncapped = $this->plan(SeasonObjective::STAY, 1_000_000, policy: self::policy(20_000));
        $cap = $uncapped['ctr'] - 1;

        $plan = $this->plan(SeasonObjective::STAY, 1_000_000, policy: self::policy(20_000, cap: $cap));

        $this->assertLessThan($cap, $plan['ctr'], 'the value rule steps by more than one point');
        $this->assertSame('value', $plan['ctr_kind']);
        $this->assertSame($cap, $plan['energy_cap']);
    }

    public function test_energy_cap_above_the_promote_advice_does_not_bind(): void
    {
        $plan = $this->plan(SeasonObjective::PROMOTE, 120_000, policy: self::policy(cap: 50));

        $this->assertSame(24, $plan['ctr']);
        $this->assertSame('highest', $plan['ctr_kind']);
    }

    public function test_promote_unchanged_without_cap(): void
    {
        $chassis = self::ENGINE + ['Chassis' => ['level' => 1, 'start' => 99.0, 'track_base' => 10.0]];
        $stuck = [
            'Engine' => ['level' => 1, 'start' => 95.0, 'track_base' => 10.0],
            'Chassis' => ['level' => 1, 'start' => 0.0, 'track_base' => 10.0],
        ];
        // Snapshot of the advice before the energy cap existed: cash, committed, income, start, parts, locked,
        // then the CTR and mode it gave.
        $scenarios = [
            [0, 0, 0, null, null, false, 0, 'short'],
            [40_000, 0, 0, null, null, false, 0, 'short'],
            [80_000, 0, 0, null, null, false, 24, 'nurse'],
            [120_000, 0, 0, null, null, false, 24, 'nurse'],
            [150_000, 0, 0, null, null, false, 80, 'nurse'],
            [250_000, 0, 0, null, null, false, 80, 'nurse'],
            [400_000, 0, 0, null, null, false, 100, 'push'],
            [1_000_000, 0, 0, null, null, false, 100, 'push'],
            [300_000, 50_000, 30_000, null, null, false, 80, 'nurse'],
            [600_000, 50_000, 30_000, null, null, false, 100, 'push'],
            [900_000, 50_000, 30_000, null, null, false, 100, 'push'],
            [2_000_000, 50_000, 30_000, null, null, false, 100, 'push'],
            [12_000_000, 50_000, 0, 10_000_000, null, false, 100, 'push'],
            [1_000_000, 50_000, 0, 100_000, null, false, 100, 'push'],
            [1_000_000, 50_000, 0, 10_000_000, null, false, 100, 'push'],
            [100_000, 0, 50_000, null, null, false, 80, 'nurse'],
            [0, 100_000, 0, null, null, false, 0, 'short'],
            [10_000_000, 0, 0, null, $chassis, false, 100, 'push'],
            [400_000, 0, 0, null, $chassis, false, 24, 'nurse'],
            [5_000_000, 0, 0, null, $stuck, true, 0, 'nurse'],
        ];

        foreach ($scenarios as $i => [$cash, $committed, $income, $start, $parts, $locked, $ctr, $mode]) {
            $plan = $this->plan(SeasonObjective::PROMOTE, $cash, $committed, $income, $start, $parts, carLocked: $locked);

            $this->assertSame($ctr, $plan['ctr'], "scenario $i");
            $this->assertSame($mode, $plan['mode'], "scenario $i");
            $this->assertSame('highest', $plan['ctr_kind'], "scenario $i");
            $this->assertSame(100, $plan['energy_cap'], "scenario $i");
            $this->assertNull($plan['value'], "scenario $i");
        }
    }

    public function test_budget_still_rules_stay(): void
    {
        // $1M a point makes every CTR clearly better than the one below, but from CTR 25 a swap breaks the cushion.
        $plan = $this->plan(SeasonObjective::STAY, 120_000, policy: self::policy(1_000_000));

        $this->assertSame($this->highestBudgetCandidate($plan, 120_000), $plan['ctr']);
        $this->assertSame(24, $plan['ctr']);
        $this->assertSame('value', $plan['ctr_kind']);
        $this->assertGreaterThanOrEqual(0, $plan['envelope']);
        $this->assertNull($plan['value']['next']);
    }

    public function test_stay_short_when_no_candidate_passes(): void
    {
        $plan = $this->plan(SeasonObjective::STAY, 0, 100_000, policy: self::policy(1_000_000));

        $this->assertSame('short', $plan['mode']);
        $this->assertSame(0, $plan['ctr']);
        $this->assertSame('highest', $plan['ctr_kind']);
        $this->assertNull($plan['value']);
        $this->assertGreaterThan(0, $plan['shortfall']);
    }

    public function test_no_pace_data_advises_zero_with_its_own_reason(): void
    {
        $plan = $this->plan(SeasonObjective::STAY, 1_000_000, policy: self::policy(0, paceKnown: false));

        $this->assertSame(0, $plan['ctr']);
        $this->assertSame('value', $plan['ctr_kind']);
        $this->assertFalse($plan['value']['pace_known']);
        $this->assertSame(0.0, $plan['value']['places']);
    }

    public function test_plan_is_v2(): void
    {
        $plan = $this->plan(SeasonObjective::STAY, 1_000_000);

        $this->assertSame(2, $plan['v']);
        $this->assertSame('value', $plan['ctr_kind']);
        $this->assertSame(100, $plan['energy_cap']);
        $this->assertFalse($plan['energy_estimate']);
        $this->assertNull($plan['energy_start']);
        $this->assertFalse($plan['value']['pace_known']);
    }

    public function testStayNeverBuysAReplacementItCannotPrice(): void
    {
        $prices = ['Engine' => null];
        $unpriced = static fn (array $partial): array => array_values(array_filter(
            $partial,
            static fn (string $entry): bool => str_ends_with($entry, ' price unknown'),
        ));
        $atZero = $unpriced($this->budget->forecast($this->rounds(), self::ENGINE, $prices, 1.0, 0, 100, 1_000_000, 0, 0)['partial']);
        $highestPriced = 0;
        for ($c = 100; $c > 0; $c--) {
            $forecast = $this->budget->forecast($this->rounds(), self::ENGINE, $prices, 1.0, $c, 100, 1_000_000, 0, 0);
            if (array_diff($unpriced($forecast['partial']), $atZero) === []) {
                $highestPriced = $c;
                break;
            }
        }

        $plan = $this->planner->plan(
            SeasonObjective::STAY,
            $this->rounds(),
            self::ENGINE,
            $prices,
            1.0,
            1_000_000,
            0,
            0,
            null,
            false,
            self::policy(20_000),
        );

        $this->assertSame(24, $highestPriced);
        $this->assertLessThanOrEqual($highestPriced, $plan['ctr']);
        $this->assertSame([], array_diff($unpriced($plan['partial']), $atZero));
        $this->assertSame(0, $plan['value']['cost']);
        $this->assertSame([], array_merge(...array_column($plan['rows'], 'replaced')));
        $this->assertNull($plan['value']['next']);
    }
}
