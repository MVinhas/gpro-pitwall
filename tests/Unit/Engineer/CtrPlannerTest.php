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
        foreach ([0, 40_000, 80_000, 120_000, 150_000, 250_000, 400_000, 1_000_000] as $cash) {
            $plan = $this->plan(SeasonObjective::PROMOTE, $cash);
            $rounds = $this->rounds();

            $atSafety = $this->budget->forecast($rounds, self::ENGINE, self::PRICES, 1.0, $plan['safety'], 100, $cash, 0, 0);
            $target = intdiv(array_sum(array_column($atSafety['rows'], 'repairs')), 3);
            $this->assertSame($target, $plan['target']);

            $expected = null;
            for ($c = 100; $c >= 0; $c--) {
                $rows = $this->budget->forecast($rounds, self::ENGINE, self::PRICES, 1.0, $c, 100, $cash, 0, 0)['rows'];
                if (min(array_column($rows, 'balance')) >= $target) {
                    $expected = $c;
                    break;
                }
            }

            $this->assertSame($expected ?? 0, $plan['ctr'], "cash $cash");
            $this->assertSame($expected === null ? 'short' : ($expected > $plan['safety'] ? 'push' : 'nurse'), $plan['mode']);
        }
    }

    public function testHoldTargetIsTheSeasonStartWhenHigherThanTheCushion(): void
    {
        $plan = $this->plan(SeasonObjective::HOLD, 1_000_000, seasonStart: 500_000);

        $this->assertSame('start', $plan['target_kind']);
        $this->assertSame(500_000, $plan['target']);
        $this->assertSame(500_000, $plan['season_start']);
        $this->assertGreaterThanOrEqual(500_000, min(array_column($plan['rows'], 'balance')));
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

    public function testPlanCarriesVersionAndPartial(): void
    {
        $rounds = $this->rounds();
        $rounds[1]['base'] = null;

        $plan = $this->planner->plan(SeasonObjective::HOLD, $rounds, self::ENGINE, self::PRICES, 1.0, 1_000_000, 0, 0, null);

        $this->assertSame(1, $plan['v']);
        $this->assertSame(['T5 wear unknown'], $plan['partial']);
    }
}
