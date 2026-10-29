<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\SeasonBudget;
use App\Engineer\SeasonObjective;
use App\Engineer\StrengthFloor;
use App\Engineer\SwapLevelPicker;
use App\Engineer\SwapSeason;
use App\Service\CarWearService;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A two-part car whose Cooling (level 3, 95% worn) must be swapped before race 8. At CTR 0 every part wears 10 a
 * race, so a fresh part never needs another swap and the plan's only repair is the 300k Cooling at race 8. Cash
 * is 10M with no running costs or income, and the plan's targets sit $budget below it: a candidate is affordable
 * when its price fits $budget. One Cooling level is worth 0.75 + 1.0 + 1.5 = 3.25 PHA over races 8 to 10.
 */
#[CoversClass(SwapLevelPicker::class)]
#[CoversClass(SwapSeason::class)]
final class SwapLevelPickerTest extends TestCase
{
    private const int CASH = 10_000_000;

    private const array PAID_PRICE = [2 => 200_000, 3 => 300_000, 4 => 400_000, 5 => 500_000];

    private SeasonBudget $budget;

    protected function setUp(): void
    {
        $wear = new CarWearService(new PDO('sqlite::memory:'), [
            'driver_wear_factors' => [],
            'part_level_factors' => [],
        ]);
        $this->budget = new SeasonBudget($wear);
    }

    private function picker(): SwapLevelPicker
    {
        return new SwapLevelPicker(
            $this->budget,
            ['Cooling' => ['power' => 0, 'handling' => 2, 'acceleration' => 1]],
        );
    }

    /**
     * @return list<array{race: int, track_name: ?string, base: ?array<string, float>}>
     */
    private function rounds(int $count = 3): array
    {
        $rounds = [];
        for ($i = 0; $i < $count; $i++) {
            $rounds[] = ['race' => 8 + $i, 'track_name' => null, 'base' => ['Cooling' => 10.0, 'Engine' => 10.0]];
        }

        return $rounds;
    }

    /**
     * @return array<int, array{power: int, handling: int, acceleration: int}>
     */
    private function demands(): array
    {
        return [
            8 => ['power' => 10, 'handling' => 5, 'acceleration' => 5],
            9 => ['power' => 5, 'handling' => 5, 'acceleration' => 10],
            10 => ['power' => 0, 'handling' => 10, 'acceleration' => 10],
        ];
    }

    /**
     * @param array<int, ?array{power: int, handling: int, acceleration: int}>|null $demands
     */
    private function season(
        string $objective,
        StrengthFloor $floor,
        int $budget,
        int $rounds = 3,
        ?array $demands = null,
    ): SwapSeason {
        $parts = [
            'Cooling' => ['level' => 3, 'start' => 95],
            'Engine' => ['level' => 3, 'start' => 0],
        ];
        $prices = ['Cooling' => 300_000, 'Engine' => 300_000];
        $roundList = $this->rounds($rounds);
        $forecast = $this->budget->forecast($roundList, $parts, $prices, 1.0, 0, 100, self::CASH, 0, 0);

        return new SwapSeason(
            $objective,
            [
                'ctr' => 0,
                'rows' => $forecast['rows'],
                'cushion' => self::CASH - $budget,
                'target' => self::CASH - $budget,
            ],
            $roundList,
            $demands ?? $this->demands(),
            $parts,
            $prices,
            1.0,
            self::CASH,
            0,
            0,
            $floor,
        );
    }

    /**
     * @return array{level: int, cost: int, is_free: bool, start: float, fit: float}
     */
    private function freeDowngrade(float $start = 40.0): array
    {
        return ['level' => 2, 'cost' => 0, 'is_free' => true, 'start' => $start, 'fit' => 0.5];
    }

    /**
     * @return array{level: int, cost: int, is_free: bool, start: float, fit: float}
     */
    private function paid(int $level, ?int $cost = null, float $fit = 0.5): array
    {
        return [
            'level' => $level,
            'cost' => $cost ?? self::PAID_PRICE[$level],
            'is_free' => false,
            'start' => 0.0,
            'fit' => $fit,
        ];
    }

    /**
     * @param list<array{level: int, cost: int, is_free: bool, start: float, fit: float}> $candidates
     * @param array<int, ?int> $paidPrice
     * @return array{level: int, kind: string, cost: int, season_pha: float, net_cost: int, affordable: bool,
     *   reason: string}|null
     */
    private function pick(SwapSeason $season, array $candidates, array $paidPrice = self::PAID_PRICE): ?array
    {
        return $this->picker()->pick($season, 'Cooling', 3, $candidates, $paidPrice);
    }

    public function testWithMoneyToSpareTheUpgradeWithMostSeasonPhaWins(): void
    {
        $pick = $this->pick(
            $this->season(SeasonObjective::HOLD, new StrengthFloor(5, 40, 26), 10_000_000),
            [$this->freeDowngrade(), $this->paid(2), $this->paid(3), $this->paid(4), $this->paid(5)],
        );

        $this->assertSame([
            'level' => 5,
            'kind' => 'upgrade',
            'cost' => 500_000,
            'season_pha' => 6.5,
            'net_cost' => 200_000,
            'affordable' => true,
            'reason' => 'most_pha',
        ], $pick);
    }

    public function testAnUnaffordableUpgradeFallsBackToTheSidegrade(): void
    {
        $pick = $this->pick(
            $this->season(SeasonObjective::HOLD, new StrengthFloor(5, 40, 26), 350_000),
            [$this->paid(4), $this->freeDowngrade(), $this->paid(3)],
        );

        $this->assertNotNull($pick);
        $this->assertSame('sidegrade', $pick['kind']);
        $this->assertSame(3, $pick['level']);
        $this->assertSame(0, $pick['net_cost']);
        $this->assertSame('most_pha', $pick['reason']);
    }

    public function testPromoteNeverTakesAPaidDowngrade(): void
    {
        $pick = $this->pick(
            $this->season(SeasonObjective::PROMOTE, new StrengthFloor(5, 40, 26), 250_000),
            [$this->paid(4), $this->paid(2), $this->paid(3)],
        );

        $this->assertNotNull($pick);
        $this->assertSame('sidegrade', $pick['kind']);
        $this->assertFalse($pick['affordable']);
        $this->assertSame('budget_short', $pick['reason']);
    }

    public function testPromoteTakesAFreeDowngradeOnlyInsideTheTopHalfBuffer(): void
    {
        $candidates = [$this->paid(4), $this->paid(3), $this->freeDowngrade()];

        $inside = $this->pick($this->season(SeasonObjective::PROMOTE, new StrengthFloor(16, 40, 26), 100_000), $candidates);
        $this->assertNotNull($inside);
        $this->assertSame('free_downgrade', $inside['kind']);
        $this->assertSame(2, $inside['level']);
        $this->assertSame(0, $inside['cost']);
        $this->assertSame(-300_000, $inside['net_cost']);
        $this->assertSame(-3.3, $inside['season_pha']);
        $this->assertSame('budget_short', $inside['reason']);

        $outside = $this->pick($this->season(SeasonObjective::PROMOTE, new StrengthFloor(17, 40, 26), 100_000), $candidates);
        $this->assertNotNull($outside);
        $this->assertSame('sidegrade', $outside['kind']);
        $this->assertSame('budget_short', $outside['reason']);
    }

    public function testPromoteWithMoneyToSpareNeverTakesTheFreeDowngrade(): void
    {
        $pick = $this->pick(
            $this->season(SeasonObjective::PROMOTE, new StrengthFloor(5, 40, 26), 300_000, 1, [8 => null]),
            [$this->freeDowngrade(), $this->paid(3)],
        );

        $this->assertNotNull($pick);
        $this->assertSame('sidegrade', $pick['kind']);
        $this->assertSame('most_pha', $pick['reason']);
    }

    public function testPromoteTopThreeKeepsTheSameLevelUnlessTheUpgradePaysForItself(): void
    {
        $season = $this->season(SeasonObjective::PROMOTE, new StrengthFloor(2, 40, 26), 10_000_000);

        $keep = $this->pick($season, [$this->paid(5), $this->paid(4), $this->paid(3)]);
        $this->assertNotNull($keep);
        $this->assertSame('sidegrade', $keep['kind']);
        $this->assertSame('top3_keep', $keep['reason']);

        $pays = $this->pick($season, [$this->paid(5), $this->paid(4, 300_000), $this->paid(3)]);
        $this->assertNotNull($pays);
        $this->assertSame('upgrade', $pays['kind']);
        $this->assertSame(4, $pays['level']);
        $this->assertSame(0, $pays['net_cost']);
        $this->assertSame('top3_pays', $pays['reason']);
    }

    public function testPromoteTopThreeWithoutAnAffordableSidegradeTakesTheMostPha(): void
    {
        $pick = $this->pick(
            $this->season(SeasonObjective::PROMOTE, new StrengthFloor(2, 40, 26), 500_000),
            [$this->paid(5), $this->paid(4)],
        );

        $this->assertNotNull($pick);
        $this->assertSame(5, $pick['level']);
        $this->assertSame('most_pha', $pick['reason']);
    }

    public function testHoldMayTakeAPaidDowngradeInsideTheTopHalf(): void
    {
        $candidates = [$this->paid(4), $this->paid(3), $this->paid(2)];

        $inside = $this->pick($this->season(SeasonObjective::HOLD, new StrengthFloor(16, 40, 26), 250_000), $candidates);
        $this->assertNotNull($inside);
        $this->assertSame('downgrade', $inside['kind']);
        $this->assertSame(2, $inside['level']);
        $this->assertTrue($inside['affordable']);
        $this->assertSame('most_pha', $inside['reason']);

        $outside = $this->pick($this->season(SeasonObjective::HOLD, new StrengthFloor(17, 40, 26), 250_000), $candidates);
        $this->assertNotNull($outside);
        $this->assertSame('sidegrade', $outside['kind']);
        $this->assertSame('budget_short', $outside['reason']);
    }

    public function testSurviveDowngradesOnlyAboveTheRelegationBuffer(): void
    {
        // 40 managers, first red row 26: a buffer of 4 keeps rank 21 and drops rank 22.
        $candidates = [$this->paid(4), $this->paid(3), $this->paid(2)];

        $above = $this->pick($this->season(SeasonObjective::SURVIVE, new StrengthFloor(21, 40, 26), 250_000), $candidates);
        $this->assertNotNull($above);
        $this->assertSame('downgrade', $above['kind']);

        $near = $this->pick($this->season(SeasonObjective::SURVIVE, new StrengthFloor(22, 40, 26), 250_000), $candidates);
        $this->assertNotNull($near);
        $this->assertSame('sidegrade', $near['kind']);
        $this->assertSame('budget_short', $near['reason']);

        $noLine = $this->pick($this->season(SeasonObjective::SURVIVE, new StrengthFloor(5, 40, null), 250_000), $candidates);
        $this->assertNotNull($noLine);
        $this->assertSame('sidegrade', $noLine['kind']);
    }

    public function testNoRankRefusesDowngradesAndTheTopThreeRule(): void
    {
        $floor = new StrengthFloor(null, null, null);

        $promote = $this->pick(
            $this->season(SeasonObjective::PROMOTE, $floor, 10_000_000),
            [$this->paid(3), $this->paid(4)],
        );
        $this->assertNotNull($promote);
        $this->assertSame('upgrade', $promote['kind']);
        $this->assertSame('most_pha', $promote['reason']);

        $short = $this->pick(
            $this->season(SeasonObjective::PROMOTE, $floor, 100_000),
            [$this->freeDowngrade(), $this->paid(3)],
        );
        $this->assertNotNull($short);
        $this->assertSame('sidegrade', $short['kind']);

        foreach ([SeasonObjective::HOLD, SeasonObjective::SURVIVE] as $objective) {
            $pick = $this->pick(
                $this->season($objective, $floor, 250_000),
                [$this->paid(2), $this->paid(3)],
            );
            $this->assertNotNull($pick);
            $this->assertSame('sidegrade', $pick['kind'], $objective);
            $this->assertSame('budget_short', $pick['reason'], $objective);
        }
    }

    public function testEqualSeasonPhaPrefersTheCheaperNetThenTheBetterFit(): void
    {
        // Barely any handling demand: L4 adds 0.06 PHA, L5 0.12, within 0.5 of each other.
        $light = ['power' => 100, 'handling' => 1, 'acceleration' => 0];
        $season = $this->season(
            SeasonObjective::HOLD,
            new StrengthFloor(5, 40, 26),
            10_000_000,
            demands: [8 => $light, 9 => $light, 10 => $light],
        );

        $cheaper = $this->pick($season, [$this->paid(5), $this->paid(4)]);
        $this->assertNotNull($cheaper);
        $this->assertSame(4, $cheaper['level']);

        $betterFit = $this->pick($season, [$this->paid(4, 400_000, 0.2), $this->paid(5, 400_000, 0.8)]);
        $this->assertNotNull($betterFit);
        $this->assertSame(5, $betterFit['level']);

        $otherFit = $this->pick($season, [$this->paid(4, 400_000, 0.8), $this->paid(5, 400_000, 0.2)]);
        $this->assertNotNull($otherFit);
        $this->assertSame(4, $otherFit['level']);
    }

    public function testACandidateWithoutAKnownPaidPriceIsDropped(): void
    {
        $season = $this->season(SeasonObjective::PROMOTE, new StrengthFloor(5, 40, 26), 100_000);
        $paidPrice = [2 => null] + self::PAID_PRICE;

        $pick = $this->pick($season, [$this->freeDowngrade(), $this->paid(3)], $paidPrice);
        $this->assertNotNull($pick);
        $this->assertSame('sidegrade', $pick['kind']);

        $this->assertNull($this->pick($season, [$this->freeDowngrade()], $paidPrice));
        $this->assertNull($this->pick($season, [$this->freeDowngrade()], [3 => 300_000]));
    }

    public function testAnInfeasibleCandidateIsDropped(): void
    {
        // Survive swaps at 90% and race 8 wears Cooling 95: even a fresh part crosses the limit within it.
        $rounds = [['race' => 8, 'track_name' => null, 'base' => ['Cooling' => 95.0, 'Engine' => 10.0]]];
        $season = new SwapSeason(
            SeasonObjective::SURVIVE,
            ['ctr' => 0, 'rows' => [], 'cushion' => 0, 'target' => 0],
            $rounds,
            $this->demands(),
            ['Cooling' => ['level' => 3, 'start' => 95], 'Engine' => ['level' => 3, 'start' => 0]],
            ['Cooling' => 300_000, 'Engine' => 300_000],
            1.0,
            self::CASH,
            0,
            0,
            new StrengthFloor(5, 40, 26),
        );

        $this->assertNull($this->pick($season, [$this->freeDowngrade(85.0), $this->paid(3)]));
    }

    public function testACandidateThatEndsThisRacePastThePlansLimitIsNotPicked(): void
    {
        // Survive swaps at 90%: a free spare 85% worn ends race 8 at 95, one 70% worn at 80.
        $season = $this->season(SeasonObjective::SURVIVE, new StrengthFloor(5, 40, 26), 250_000);

        $past = $this->pick($season, [$this->paid(3), $this->freeDowngrade(85.0)]);
        $this->assertNotNull($past);
        $this->assertSame('sidegrade', $past['kind']);
        $this->assertSame('budget_short', $past['reason']);

        $within = $this->pick($season, [$this->paid(3), $this->freeDowngrade(70.0)]);
        $this->assertNotNull($within);
        $this->assertSame('free_downgrade', $within['kind']);
    }

    public function testOneRaceLeftStillPicks(): void
    {
        $pick = $this->pick(
            $this->season(SeasonObjective::HOLD, new StrengthFloor(5, 40, 26), 10_000_000, 1),
            [$this->paid(3), $this->paid(4)],
        );

        $this->assertNotNull($pick);
        $this->assertSame(4, $pick['level']);
        $this->assertSame(0.8, $pick['season_pha']);
    }

    public function testBudgetShortTakesTheFreeOptionFirst(): void
    {
        // Nothing fits: the targets sit 50k above cash. The free downgrade (85% worn) is replaced for 200k in
        // race 9, the same 250k short as buying the paid downgrade now; free still goes first.
        $pick = $this->pick(
            $this->season(SeasonObjective::HOLD, new StrengthFloor(5, 40, 26), -50_000),
            [$this->paid(4), $this->paid(2), $this->paid(3), $this->freeDowngrade(85.0)],
        );

        $this->assertNotNull($pick);
        $this->assertSame('free_downgrade', $pick['kind']);
        $this->assertFalse($pick['affordable']);
        $this->assertSame('budget_short', $pick['reason']);
    }

    public function testCommittingLowersTheCashAndEveryPlannedBalance(): void
    {
        $season = $this->season(SeasonObjective::HOLD, new StrengthFloor(5, 40, 26), 450_000);

        $committed = $season->committing(200_000);

        $this->assertSame(self::CASH - 200_000, $committed->cash);
        $this->assertSame(
            array_map(static fn (int $b): int => $b - 200_000, array_column($season->plan['rows'], 'balance')),
            array_column($committed->plan['rows'], 'balance'),
        );
        $this->assertSame(self::CASH, $season->cash);
        $this->assertSame('sidegrade', $this->pick($committed, [$this->paid(3), $this->paid(4)])['kind'] ?? null);
        $this->assertSame('upgrade', $this->pick($season, [$this->paid(3), $this->paid(4)])['kind'] ?? null);
    }

    public function testEveryReasonHasItsWording(): void
    {
        $this->assertSame([
            'most_pha' => 'Most PHA the season budget allows',
            'top3_keep' => 'Top-3 car: same level, no extra spend',
            'top3_pays' => 'Top-3 car: this upgrade pays for itself',
            'budget_short' => 'Budget short: cheapest option that keeps your car in place',
        ], SwapLevelPicker::REASONS);
    }
}
