<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\BoostPlanner;
use App\Engineer\SeasonBudget;
use App\Service\CarWearService;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Level 1 wears 1.02^CTR, so a base-10 part wears 10.0 a race at CTR 0 and 72.45 at CTR 100. Over 60 laps one
 * stint adds (3 / 60) x 62.45 = 3.12, two add 6.24 and three add 9.37.
 */
#[CoversClass(BoostPlanner::class)]
final class BoostPlannerTest extends TestCase
{
    private const int LAPS = 60;

    private BoostPlanner $planner;

    protected function setUp(): void
    {
        $wear = new CarWearService(new PDO('sqlite::memory:'), [
            'driver_wear_factors' => [],
            'part_level_factors' => [1 => 1.02],
        ]);
        $this->planner = new BoostPlanner($wear, new SeasonBudget($wear));
    }

    /**
     * @return array<string, array{level: int, start: float, track_base: float}>
     */
    private function engine(float $start): array
    {
        return ['Engine' => ['level' => 1, 'start' => $start, 'track_base' => 10.0]];
    }

    /**
     * @return list<array{race: int, track_name: ?string, base: ?array<string, float>}>
     */
    private function rounds(int $count): array
    {
        $rounds = [];
        for ($i = 0; $i < $count; $i++) {
            $rounds[] = ['race' => 4 + $i, 'track_name' => 'T' . (4 + $i), 'base' => ['Engine' => 10.0, 'Chassis' => 10.0]];
        }

        return $rounds;
    }

    /**
     * @param array<string, mixed> $o
     * @return array<string, mixed>
     */
    private function plan(array $o = []): array
    {
        $o += [
            'most' => true,
            'rounds' => 1,
            'parts' => $this->engine(0.0),
            'prices' => ['Engine' => 450_000, 'Chassis' => 200_000],
            'locked' => false,
            'balances' => null,
            'cushion' => 0,
            'target' => 0,
            'places' => 0.63,
            'gain' => 30_000,
        ];

        return $this->planner->plan(
            $o['most'],
            $this->rounds($o['rounds']),
            $o['parts'],
            $o['prices'],
            1.0,
            0,
            $o['locked'],
            self::LAPS,
            $o['balances'] ?? array_fill(0, $o['rounds'], 5_000_000),
            $o['cushion'],
            $o['target'],
            $o['places'],
            $o['gain'],
        );
    }

    public function testKeepTableAndUnknownRating(): void
    {
        $this->assertSame(1.0, BoostPlanner::keep('Very Easy'));
        $this->assertSame(1.0, BoostPlanner::keep('Easy'));
        $this->assertSame(0.55, BoostPlanner::keep('Normal'));
        $this->assertSame(0.40, BoostPlanner::keep('Hard'));
        $this->assertSame(0.30, BoostPlanner::keep('Very Hard'));
        $this->assertSame(0.55, BoostPlanner::keep(null));
        $this->assertSame(0.55, BoostPlanner::keep('Bogus'));

        $this->assertTrue(BoostPlanner::hardToPass('Hard'));
        $this->assertTrue(BoostPlanner::hardToPass('Very Hard'));
        $this->assertFalse(BoostPlanner::hardToPass('Normal'));
        $this->assertFalse(BoostPlanner::hardToPass(null));
    }

    public function testPlacesPerStint(): void
    {
        $this->assertEqualsWithDelta(0.2, BoostPlanner::placesPerStint(20, 0.01, 12.0, 'Easy'), 1e-9);
        $this->assertEqualsWithDelta(0.08, BoostPlanner::placesPerStint(20, 0.01, 12.0, 'Hard'), 1e-9);
        $this->assertSame(0.0, BoostPlanner::placesPerStint(20, 0.01, 0.0, 'Easy'));
        $this->assertSame(0.0, BoostPlanner::placesPerStint(20, 0.0, 12.0, 'Easy'));
    }

    public function testPromoteTakesAllThreeWhenNothingReachesTheLimit(): void
    {
        $choice = $this->plan();

        $this->assertSame(3, $choice['stints']);
        $this->assertSame(0, $choice['cost']);
        $this->assertSame(90_000, $choice['gain']);
        $this->assertSame(1.9, $choice['places']);
        $this->assertNull($choice['blocked']);
    }

    public function testAPartStopsTheStintThatTakesItToTheLimit(): void
    {
        // 93.0 without boost; 96.12 with one stint; 99.24 with two, which reads as 100 %.
        $choice = $this->plan(['parts' => $this->engine(83.0)]);

        $this->assertSame(1, $choice['stints']);
        $this->assertSame(30_000, $choice['gain']);
        $this->assertSame(
            ['stints' => 2, 'why' => 'part', 'part' => 'Engine', 'gain' => 30_000, 'cost' => 0],
            $choice['blocked'],
        );
    }

    public function testAStuckPartAlreadyPastTheLimitStopsNothing(): void
    {
        $parts = $this->engine(95.0) + ['Chassis' => ['level' => 1, 'start' => 0.0, 'track_base' => 10.0]];

        $choice = $this->plan(['parts' => $parts, 'locked' => true]);

        $this->assertSame(3, $choice['stints']);
        $this->assertNull($choice['blocked']);
    }

    public function testASwappedPartCarriesTheBoostWearFresh(): void
    {
        // Past the limit without boost, so the plan swaps it: the new part ends the race at 19.37.
        $choice = $this->plan(['parts' => $this->engine(95.0)]);

        $this->assertSame(3, $choice['stints']);
        $this->assertSame(0, $choice['cost']);
    }

    public function testWearRoundsUpToTheLimit(): void
    {
        // 95.93 without boost; one stint ends at 99.05, which reads as 100 %.
        $choice = $this->plan(['parts' => $this->engine(85.93)]);

        $this->assertSame(0, $choice['stints']);
        $this->assertSame(0.0, $choice['places']);
        $this->assertSame('part', $choice['blocked']['why'] ?? null);
        $this->assertSame(1, $choice['blocked']['stints'] ?? null);
    }

    public function testStayTakesFreeStintsOnTheLastRace(): void
    {
        $choice = $this->plan(['most' => false, 'parts' => $this->engine(64.0), 'gain' => 0, 'places' => 0.0]);

        $this->assertSame(3, $choice['stints']);
        $this->assertSame(0, $choice['cost']);
        $this->assertNull($choice['blocked']);
    }

    public function testStayRefusesAStintThatAddsAPurchase(): void
    {
        // Three races end at 94.0 without boost and 97.12 with one stint; two stints end at 100.24, a new Engine.
        $choice = $this->plan(['most' => false, 'rounds' => 3, 'parts' => $this->engine(64.0)]);

        $this->assertSame(1, $choice['stints']);
        $this->assertSame(0, $choice['cost']);
        $this->assertSame(
            ['stints' => 2, 'why' => 'value', 'part' => null, 'gain' => 30_000, 'cost' => 450_000],
            $choice['blocked'],
        );
    }

    public function testStayStepsUpOnlyAboveTheMargin(): void
    {
        // From one free stint, three stints add 2 x gain for the $450k Engine: $100,000 net at $275,000 a stint.
        $at = $this->plan(['most' => false, 'rounds' => 3, 'parts' => $this->engine(64.0), 'gain' => 275_000]);
        $above = $this->plan(['most' => false, 'rounds' => 3, 'parts' => $this->engine(64.0), 'gain' => 275_001]);

        $this->assertSame(1, $at['stints']);
        $this->assertSame('value', $at['blocked']['why'] ?? null);
        $this->assertSame(3, $above['stints']);
        $this->assertSame(450_000, $above['cost']);
        $this->assertSame(825_003, $above['gain']);
        $this->assertNull($above['blocked']);
    }

    public function testMostThatPassTakesAPaidStintTheBudgetCarries(): void
    {
        $choice = $this->plan(['rounds' => 3, 'parts' => $this->engine(64.0)]);

        $this->assertSame(3, $choice['stints']);
        $this->assertSame(450_000, $choice['cost']);
        $this->assertNull($choice['blocked']);
    }

    public function testCashStopsAStintTheEndTargetCannotCarry(): void
    {
        $choice = $this->plan([
            'rounds' => 3,
            'parts' => $this->engine(64.0),
            'balances' => [1_000_000, 1_000_000, 500_000],
            'target' => 100_000,
        ]);

        $this->assertSame(1, $choice['stints']);
        $this->assertSame(
            ['stints' => 2, 'why' => 'cash', 'part' => null, 'gain' => 30_000, 'cost' => 450_000],
            $choice['blocked'],
        );
    }

    public function testCashStopsAPurchaseBroughtForwardIntoAThinRace(): void
    {
        // Four races: the Engine is bought before the last one anyway. Two stints bring it forward a race,
        // into the one whose balance cannot carry it. The season's repairs are the same either way.
        $choice = $this->plan([
            'rounds' => 4,
            'parts' => $this->engine(64.0),
            'balances' => [1_000_000, 1_000_000, 400_000, 1_000_000],
        ]);

        $this->assertSame(1, $choice['stints']);
        $this->assertSame('cash', $choice['blocked']['why'] ?? null);
        $this->assertSame(450_000, $choice['blocked']['cost'] ?? null);
    }

    public function testAPurchaseBroughtForwardIsFreeWhenTheCashCarriesIt(): void
    {
        $choice = $this->plan(['most' => false, 'rounds' => 4, 'parts' => $this->engine(64.0)]);

        $this->assertSame(3, $choice['stints']);
        $this->assertSame(0, $choice['cost']);
    }

    public function testAPlanAlreadyShortKeepsFreeStints(): void
    {
        $choice = $this->plan(['rounds' => 3, 'balances' => [-1, -1, -1]]);

        $this->assertSame(3, $choice['stints']);
    }

    public function testAnUnpricedPurchaseIsRuledOut(): void
    {
        $choice = $this->plan(['rounds' => 3, 'parts' => $this->engine(64.0), 'prices' => ['Engine' => null]]);

        $this->assertSame(1, $choice['stints']);
        $this->assertSame(
            ['stints' => 2, 'why' => 'unpriced', 'part' => 'Engine', 'gain' => 30_000, 'cost' => 0],
            $choice['blocked'],
        );
    }

    public function testWithoutPlanAppliesOnlyThePartLimit(): void
    {
        $limited = $this->planner->withoutPlan($this->engine(83.0), 1.0, 0, self::LAPS, 0.63);
        $free = $this->planner->withoutPlan($this->engine(0.0), 1.0, 0, self::LAPS, 0.63);

        $this->assertSame(1, $limited['stints']);
        $this->assertSame(0, $limited['gain']);
        $this->assertSame(0, $limited['cost']);
        $this->assertSame(0.6, $limited['places']);
        $this->assertSame('part', $limited['blocked']['why'] ?? null);
        $this->assertSame('Engine', $limited['blocked']['part'] ?? null);
        $this->assertSame(3, $free['stints']);
        $this->assertNull($free['blocked']);
    }
}
