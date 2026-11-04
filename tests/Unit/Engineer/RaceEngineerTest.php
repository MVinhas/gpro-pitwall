<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\EngineerInputs;
use App\Engineer\Money;
use App\Engineer\RaceEngineer;
use App\Engineer\SeasonObjective;
use App\Engineer\SwapLevelPicker;
use App\Service\CarWearPlannerService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RaceEngineer::class)]
#[CoversClass(EngineerInputs::class)]
final class RaceEngineerTest extends TestCase
{
    private const array KEYS = ['objective', 'cash', 'parts', 'training', 'testing', 'ctr', 'risk', 'boost'];

    /**
     * @param array<string, mixed> $o
     * @return array<string, mixed>
     */
    private function objective(?string $objective, array $o = []): array
    {
        return $o + [
            'objective' => $objective,
            'label' => $objective === null ? 'Objective unknown' : ucfirst($objective),
            'pinned' => false,
            'proposed' => $objective,
            'warning' => null,
            'early' => false,
            'cash_alarm' => false,
            'over' => false,
            'numbers' => [],
            'reasons' => ['Projected to finish P3'],
        ];
    }

    /**
     * @return array{known: bool, committed: ?int, income: ?int, free_cash: ?int, end_committed: ?int}
     */
    private function outlook(?int $free): array
    {
        return [
            'known' => $free !== null,
            'committed' => $free === null ? null : 1,
            'income' => $free === null ? null : 1,
            'free_cash' => $free,
            'end_committed' => $free,
        ];
    }

    /**
     * @param array<string, mixed> $o
     */
    private function inputs(array $o = []): EngineerInputs
    {
        $o += [
            'objective' => $this->objective(SeasonObjective::PROMOTE),
            'outlook' => $this->outlook(5_000_000),
            'cash' => 1_000_000,
            'currentCtr' => 30,
            'ctrCeiling' => 45,
            'pushAdvice' => CarWearPlannerService::ADVICE_NORMAL,
            'wearAdvice' => null,
            'forcedSwapCost' => null,
            'trainingPick' => ['name' => 'Talent course', 'score' => 1.0, 'cost' => 85_000, 'gains' => []],
            'testingTarget' => [
                'offset' => 1,
                'season' => 'current',
                'race' => 10,
                'track_id' => 5,
                'track_name' => 'Monza',
                'power' => 3,
                'handling' => 4,
                'acceleration' => 5,
            ],
            'testingOpen' => true,
            'testingTrack' => 'Silverstone',
            'testingCost' => 300_000,
            'risk' => ['overtake' => 40, 'defend' => 25, 'phrase' => 'x', 'tip' => null, 'distance_tip' => '', 'settings' => []],
            'boostLitres' => 24,
            'weatherStale' => false,
            'plan' => null,
        ];

        return new EngineerInputs(...$o);
    }

    /**
     * @param array<string, mixed> $o
     * @return array<string, mixed>
     */
    private function plan(array $o = []): array
    {
        $row = [
            'race' => 8, 'track_name' => 'Monza', 'replaced' => [], 'repairs' => 0,
            'running' => 0, 'income' => 0, 'balance' => 2_000_000, 'wear_known' => true,
        ];

        return $o + [
            'v' => 2, 'ctr' => 40, 'ctr_kind' => 'highest', 'mode' => 'nurse', 'safety' => 40, 'swaps' => [],
            'refused_swap_cost' => null, 'target' => 2_000_000, 'target_kind' => 'cushion',
            'cushion' => 2_000_000, 'season_start' => null, 'envelope' => 600_000,
            'shortfall' => null, 'rows' => [$row], 'partial' => [], 'race' => 8, 'infeasible' => false,
            'energy_cap' => 100, 'energy_estimate' => false, 'energy_start' => null, 'value' => null,
        ];
    }

    /**
     * @param list<array{part: string, cost: int}> $replaced
     * @return list<array<string, mixed>>
     */
    private function rowsReplacing(array $replaced): array
    {
        return [[
            'race' => 8, 'track_name' => 'Monza', 'replaced' => $replaced,
            'repairs' => array_sum(array_column($replaced, 'cost')),
            'running' => 0, 'income' => 0, 'balance' => 2_000_000, 'wear_known' => true,
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function line(EngineerInputs $in, string $key): array
    {
        foreach (RaceEngineer::brief($in) as $line) {
            if ($line['key'] === $key) {
                return $line;
            }
        }
        $this->fail("No $key line");
    }

    /**
     * @return array<string, mixed>
     */
    private function wearAdvice(): array
    {
        $engine = ['part' => 'Engine', 'level' => 3, 'start' => 80, 'est' => 25.0, 'end' => 105.0];

        return ['swap' => [$engine], 'risky' => [], 'watch' => [], 'headline' => 'x'];
    }

    public function testLinesComeInFixedOrder(): void
    {
        $keys = array_column(RaceEngineer::brief($this->inputs()), 'key');

        $this->assertSame(self::KEYS, $keys);
    }

    public function testEveryLineCarriesItsStep(): void
    {
        $staff = ['rank' => 27, 'of' => 30, 'own' => 41, 'band' => 'top', 'band_average' => 63, 'nudge' => true];
        $in = $this->withUpgrades([$this->upgrade()], [
            'tyreAdvice' => $this->tyreAdvice(),
            'staffStanding' => $staff,
        ]);

        $lines = RaceEngineer::brief($in);

        $this->assertSame(
            ['objective', 'tyres', 'cash', 'staff', 'parts', 'upgrades', 'training', 'testing', 'ctr', 'risk', 'boost'],
            array_column($lines, 'key'),
        );
        foreach ($lines as $line) {
            $this->assertSame(RaceEngineer::STEPS[$line['key']], $line['step'], $line['key']);
        }
        $this->assertSame(
            ['season', 'season', 'season', 'season', 'car', 'car', 'prep', 'prep', 'race', 'race', 'race'],
            array_column($lines, 'step'),
        );
    }

    public function testStaleLineIsASeasonLine(): void
    {
        $this->assertSame('season', RaceEngineer::stale()['step']);
    }

    public function testUpdatedCarSaysSoAndDropsUpgrades(): void
    {
        $in = $this->withUpgrades([$this->upgrade()], ['carUpdated' => true]);

        $parts = $this->line($in, 'parts');

        $this->assertSame('Car updated', $parts['answer']);
        $this->assertSame('One update per race; the plan reads the car as it is now', $parts['reason']);
        $this->assertSame('ink', $parts['tone']);
        $this->assertNull($parts['figure']);
        $this->assertSame('/cockpit#cockpit-wear', $parts['anchor']);
        $this->assertNotContains('upgrades', array_column(RaceEngineer::brief($in), 'key'));
    }

    public function testUpdatedCarWithTwoSmokingPartsIsAmberOnPartsAndCtr(): void
    {
        $in = $this->planned(['ctr' => 0], ['carUpdated' => true, 'stuckSmoke' => ['Engine', 'Brakes']]);

        $parts = $this->line($in, 'parts');
        $ctr = $this->line($in, 'ctr');

        $this->assertSame('Car updated', $parts['answer']);
        $this->assertSame('amber', $parts['tone']);
        $this->assertSame(
            'Engine, Brakes reach 100% this race: expect smoke and lost time, and a retirement if it comes early; '
                . 'no more changes until the next race',
            $parts['reason'],
        );
        $this->assertSame(
            'Engine, Brakes reach 100% this race and the car is already updated: CTR 0 keeps their wear lowest',
            $ctr['reason'],
        );
    }

    public function testUpdatedCarWithOneSmokingPartSaysItsWear(): void
    {
        $in = $this->planned(['ctr' => 0], ['carUpdated' => true, 'stuckSmoke' => ['Engine']]);

        $this->assertSame('amber', $this->line($in, 'parts')['tone']);
        $this->assertSame(
            'Engine reaches 100% this race and the car is already updated: CTR 0 keeps its wear lowest',
            $this->line($in, 'ctr')['reason'],
        );
    }

    public function testAShortPlanKeepsItsShortfallReasonOnCtr(): void
    {
        $in = $this->planned(
            ['ctr' => 0, 'mode' => 'short', 'shortfall' => 400_000],
            ['carUpdated' => true, 'stuckSmoke' => ['Engine']],
        );

        $this->assertSame('Even at CTR 0 the forecast misses the target by $400k', $this->line($in, 'ctr')['reason']);
    }

    public function testStuckPartsAtANonZeroCtrKeepTheOrdinaryCtrReason(): void
    {
        $in = $this->planned(['ctr' => 40], ['carUpdated' => true, 'stuckSmoke' => ['Engine']]);

        $this->assertSame('Race rated Normal: the highest CTR where no part breaks', $this->line($in, 'ctr')['reason']);
    }

    public function testUpdatedCarWithoutAPlanStillSaysCarUpdated(): void
    {
        $in = $this->inputs(['plan' => null, 'carUpdated' => true]);

        $this->assertSame('Car updated', $this->line($in, 'parts')['answer']);
        $this->assertSame('ink', $this->line($in, 'parts')['tone']);
    }

    public function testWithoutAPlanStuckPartsExplainTheZeroCtr(): void
    {
        $in = $this->inputs([
            'plan' => null,
            'ctrCeiling' => 0,
            'carUpdated' => true,
            'stuckSmoke' => ['Engine'],
        ]);

        $this->assertSame(
            'Engine reaches 100% this race and the car is already updated: CTR 0 keeps its wear lowest',
            $this->line($in, 'ctr')['reason'],
        );
        $this->assertSame('amber', $this->line($in, 'parts')['tone']);
    }

    public function testDoneTestingAndTrainingSpendNothing(): void
    {
        $training = ['name' => 'Talent course', 'score' => 1.0, 'cost' => 500_000, 'gains' => []];
        $open = ['trainingPick' => $training, 'testingCost' => RaceEngineer::TESTING_SESSION_COST];
        $plan = ['envelope' => 2_000_000];

        $done = $this->planned($plan, $open + ['tested' => true, 'driverTrained' => true]);
        $todo = $this->planned($plan, $open);

        $testing = $this->line($done, 'testing');
        $trained = $this->line($done, 'training');

        $this->assertSame('Testing done this race', $testing['answer']);
        $this->assertNull($testing['figure']);
        $this->assertSame('ink', $testing['tone']);
        $this->assertSame('/cockpit#cockpit-testing', $testing['anchor']);
        $this->assertSame('Driver trained this race', $trained['answer']);
        $this->assertNull($trained['figure']);
        $this->assertSame('ink', $trained['tone']);
        $this->assertSame('/cockpit#cockpit-training', $trained['anchor']);
        $this->assertSame(2_000_000, RaceEngineer::upgradeBudget($done));
        $this->assertSame(
            2_000_000 - 500_000 - RaceEngineer::TESTING_SESSION_COST,
            RaceEngineer::upgradeBudget($todo),
        );
    }

    public function testDoneLinesShowEvenWhenNothingWasOffered(): void
    {
        $in = $this->inputs(['trainingPick' => null, 'testingOpen' => false, 'tested' => true, 'driverTrained' => true]);

        $this->assertSame('Driver trained this race', $this->line($in, 'training')['answer']);
        $this->assertSame('Testing done this race', $this->line($in, 'testing')['answer']);
    }

    /**
     * @param array<string, mixed> $o
     * @return array<string, mixed>
     */
    private function tyreAdvice(array $o = []): array
    {
        return $o + [
            'pick' => 'Badyear', 'cost' => 4_800_000, 'best' => 'Badyear', 'reason' => 'best', 'wet_edge' => false,
            'talent' => 150, 'baseline' => 193, 'finances_known' => true, 'rows' => [],
        ];
    }

    public function testTheTyreLineFollowsTheObjective(): void
    {
        $lines = RaceEngineer::brief($this->inputs(['tyreAdvice' => $this->tyreAdvice(), 'division' => 'Pro']));

        $this->assertSame('tyres', $lines[1]['key']);
        $this->assertSame('Sign Badyear', $lines[1]['answer']);
        $this->assertSame('$4.8M a race', $lines[1]['figure']);
        $this->assertSame('/engineer#engineer-tyres', $lines[1]['anchor']);
        $this->assertSame('ink', $lines[1]['tone']);
        $this->assertSame('cash', $lines[2]['key']);
    }

    public function testNoAdviceMeansNoTyreLine(): void
    {
        $this->assertNotContains('tyres', array_column(RaceEngineer::brief($this->inputs()), 'key'));
    }

    public function testASeasonOverBriefHasNoTyreLine(): void
    {
        $over = $this->objective(null, ['over' => true, 'reasons' => []]);

        $lines = RaceEngineer::brief($this->inputs(['objective' => $over, 'tyreAdvice' => $this->tyreAdvice()]));

        $this->assertSame(['objective'], array_column($lines, 'key'));
    }

    public function testTheBestBalanceReason(): void
    {
        $line = $this->line($this->inputs(['tyreAdvice' => $this->tyreAdvice(), 'division' => 'Pro']), 'tyres');

        $this->assertSame('Best balance for your calendar', $line['reason']);
    }

    public function testABandPickNamesTheBestAndWhatItSaves(): void
    {
        $advice = $this->tyreAdvice([
            'pick' => 'Dunnolop', 'cost' => 3_800_000, 'best' => 'Badyear', 'reason' => 'band',
            'rows' => [
                ['name' => 'Badyear', 'cost' => 4_800_000],
                ['name' => 'Dunnolop', 'cost' => 3_800_000],
            ],
        ]);

        $line = $this->line($this->inputs(['tyreAdvice' => $advice, 'division' => 'Pro']), 'tyres');

        $this->assertSame('Sign Dunnolop', $line['answer']);
        $this->assertSame('$3.8M a race', $line['figure']);
        $this->assertSame('Within 10% of Badyear, and $1M a race cheaper', $line['reason']);
    }

    public function testAFinancesShortPickSaysItIsTheCheapestAllowed(): void
    {
        $advice = $this->tyreAdvice(['reason' => 'budget_short', 'wet_edge' => true]);

        $line = $this->line($this->inputs(['tyreAdvice' => $advice, 'division' => 'Pro']), 'tyres');

        $this->assertSame('Budget short: cheapest supplier your division allows', $line['reason']);
    }

    public function testTheWetEdgeIsExplained(): void
    {
        $advice = $this->tyreAdvice(['wet_edge' => true, 'talent' => 200, 'baseline' => 193]);

        $line = $this->line($this->inputs(['tyreAdvice' => $advice, 'division' => 'Pro']), 'tyres');

        $this->assertSame(
            "Best balance for your calendar; your driver's talent (200) is at or above the Pro average (193), "
            . 'so rain counts more',
            $line['reason'],
        );
    }

    public function testUnknownFinancesAreSaidSo(): void
    {
        $advice = $this->tyreAdvice(['finances_known' => false]);

        $line = $this->line($this->inputs(['tyreAdvice' => $advice, 'division' => 'Pro']), 'tyres');

        $this->assertSame('Best balance for your calendar; finances unknown, picked on performance', $line['reason']);
    }

    public function testTheTyreAdviceSurvivesThePlanAndPickCopies(): void
    {
        $in = $this->inputs(['tyreAdvice' => $this->tyreAdvice(), 'division' => 'Pro']);

        $copy = $in->withPlan($this->plan())->withSwapPicks([]);

        $this->assertSame($in->tyreAdvice, $copy->tyreAdvice);
        $this->assertSame('Pro', $copy->division);
    }

    public function testSeasonOverReturnsOnlyTheObjective(): void
    {
        $over = $this->objective(null, ['over' => true, 'label' => 'Season over — promoted, P2', 'reasons' => []]);

        $lines = RaceEngineer::brief($this->inputs(['objective' => $over]));

        $this->assertCount(1, $lines);
        $this->assertSame('objective', $lines[0]['key']);
        $this->assertSame('Season over — promoted, P2', $lines[0]['answer']);
    }

    public function testObjectiveLineCarriesLabelAndFirstReason(): void
    {
        $line = $this->line($this->inputs(), 'objective');

        $this->assertSame('Promote', $line['answer']);
        $this->assertSame('Projected to finish P3', $line['reason']);
        $this->assertSame('ink', $line['tone']);
        $this->assertNull($line['anchor']);
    }

    public function testObjectiveWarningIsAmberButNotRepeatedAsTheReason(): void
    {
        $warned = $this->objective(SeasonObjective::STAY, ['warning' => 'Negative balance relegates']);

        $line = $this->line($this->inputs(['objective' => $warned]), 'objective');

        $this->assertSame('amber', $line['tone']);
        $this->assertSame('Projected to finish P3', $line['reason']);
    }

    public function testObjectiveWarningAloneLeavesNoReason(): void
    {
        $warned = $this->objective(SeasonObjective::STAY, ['warning' => 'Negative balance relegates', 'reasons' => []]);

        $this->assertSame('', $this->line($this->inputs(['objective' => $warned]), 'objective')['reason']);
    }

    public function testObjectiveFigureCarriesTheSpecNumbers(): void
    {
        $numbers = ['pos' => 5, 'gap' => 6, 'line_pos' => 3, 'races_left' => 9];

        $line = $this->line($this->inputs(['objective' => $this->objective(SeasonObjective::PROMOTE, ['numbers' => $numbers])]), 'objective');

        $this->assertSame('P5 · 6 pts behind P3 · 9 races left', $line['figure']);
    }

    public function testObjectiveFigureOmitsMissingParts(): void
    {
        $inside = ['pos' => 2, 'gap' => 0, 'line_pos' => 3, 'races_left' => 1];
        $blank = ['pos' => null, 'gap' => null, 'line_pos' => null, 'races_left' => null];

        $a = $this->line($this->inputs(['objective' => $this->objective(SeasonObjective::STAY, ['numbers' => $inside])]), 'objective');
        $b = $this->line($this->inputs(['objective' => $this->objective(SeasonObjective::STAY, ['numbers' => $blank])]), 'objective');
        $c = $this->line($this->inputs(['objective' => $this->objective(SeasonObjective::STAY, ['numbers' => ['pos' => 4, 'gap' => null, 'line_pos' => null, 'races_left' => 12]])]), 'objective');

        $this->assertSame('P2 · 1 race left', $a['figure']);
        $this->assertNull($b['figure']);
        $this->assertSame('P4 · 12 races left', $c['figure']);
    }

    public function testNoProposalExplainsWhyInsteadOfRepeatingTheHeader(): void
    {
        $unknown = $this->objective(null, ['reasons' => []]);

        $line = $this->line($this->inputs(['objective' => $unknown]), 'objective');

        $this->assertSame('Objective unknown', $line['answer']);
        $this->assertSame("Your position in the group isn't known yet; treated as Stay", $line['reason']);
    }

    public function testPinWithoutAProposalStillSaysWhyTheEngineerHasNone(): void
    {
        $pinned = SeasonObjective::inForce($this->objective(null, ['reasons' => []]), SeasonObjective::STAY);

        $line = $this->line($this->inputs(['objective' => $pinned]), 'objective');

        $this->assertSame('Your call: Stay (engineer has no proposal)', $line['answer']);
        $this->assertSame("Your position in the group isn't known yet", $line['reason']);
    }

    public function testUnknownCostsLine(): void
    {
        $line = $this->line($this->inputs(['outlook' => $this->outlook(null)]), 'cash');

        $this->assertSame('Costs unknown — sync once after a race', $line['answer']);
        $this->assertSame('ink', $line['tone']);
    }

    public function testNegativeFreeCashIsRed(): void
    {
        $line = $this->line($this->inputs(['outlook' => $this->outlook(-200_000)]), 'cash');

        $this->assertSame('red', $line['tone']);
        $this->assertSame('-$200k', $line['figure']);
    }

    public function testFreeCashBelowForcedRepairsIsAmber(): void
    {
        $line = $this->line($this->inputs(['outlook' => $this->outlook(300_000), 'forcedSwapCost' => 410_000]), 'cash');

        $this->assertSame('amber', $line['tone']);
    }

    public function testFreeCashThatCoversRepairsIsInk(): void
    {
        $line = $this->line($this->inputs(['forcedSwapCost' => 410_000]), 'cash');

        $this->assertSame('ink', $line['tone']);
        $this->assertSame('$5M', $line['figure']);
    }

    public function testPromotePushesToTheCeilingOnAPushRace(): void
    {
        $line = $this->line($this->inputs([
            'objective' => $this->objective(SeasonObjective::PROMOTE),
            'pushAdvice' => CarWearPlannerService::ADVICE_PUSH,
        ]), 'ctr');

        $this->assertSame('CTR 45', $line['answer']);
        $this->assertSame(['label' => 'Use 45', 'href' => '/engineer?cockpit_risk=45'], $line['action']);
        $this->assertStringContainsString('Push', $line['reason']);
        $this->assertStringContainsString('no part breaks', $line['reason']);
        $this->assertSame('/cockpit#cockpit-wear', $line['anchor']);
    }

    public function testPromoteOnANormalRaceAdvisesTheCeilingNotTheCurrentCtr(): void
    {
        $line = $this->line($this->inputs(['objective' => $this->objective(SeasonObjective::PROMOTE)]), 'ctr');

        $this->assertSame('CTR 45', $line['answer']);
    }

    public function testPromoteAdvisesTheCeilingEvenWhenThePlanIsNotRaisingCtr(): void
    {
        $in = $this->inputs(['pushAdvice' => CarWearPlannerService::ADVICE_PUSH]);

        $this->assertSame('CTR 45', $this->line($in, 'ctr')['answer']);
    }

    public function testPickingZeroNeverMakesTheEngineerSuggestZero(): void
    {
        $line = $this->line($this->inputs(['currentCtr' => 0]), 'ctr');

        $this->assertSame('CTR 45', $line['answer']);
        $this->assertSame(['label' => 'Use 45', 'href' => '/engineer?cockpit_risk=45'], $line['action']);
        $this->assertSame('On 0 now', $line['figure']);
    }

    public function testPromoteLowersCtrToTheCeiling(): void
    {
        $line = $this->line($this->inputs(['currentCtr' => 60]), 'ctr');

        $this->assertSame('CTR 45', $line['answer']);
        $this->assertSame('/engineer?cockpit_risk=45', $line['action']['href'] ?? null);
    }

    public function testWithoutAPlanTheReasonSaysSeasonCostsAreUnknown(): void
    {
        $line = $this->line($this->inputs(), 'ctr');

        $this->assertSame('Race rated Normal: the highest CTR where no part breaks; season costs unknown', $line['reason']);
    }

    public function testNoObjectiveFollowsTheStayRule(): void
    {
        $in = $this->inputs([
            'objective' => $this->objective(null),
            'pushAdvice' => CarWearPlannerService::ADVICE_PUSH,
        ]);

        $this->assertSame('CTR 45', $this->line($in, 'ctr')['answer']);
    }

    public function testStayUsesTheSameCeilingAsTheDefault(): void
    {
        $in = $this->inputs([
            'objective' => $this->objective(SeasonObjective::STAY),
            'currentCtr' => 60,
        ]);

        $line = $this->line($in, 'ctr');

        $this->assertSame('CTR 45', $line['answer']);
        $this->assertSame('Race rated Normal: the highest CTR where no part breaks; season costs unknown', $line['reason']);
    }

    public function testNoActionWhenTargetEqualsCurrent(): void
    {
        $line = $this->line($this->inputs(['currentCtr' => 45, 'ctrCeiling' => 45]), 'ctr');

        $this->assertNull($line['action']);
    }

    public function testCtrOmittedWithoutCeiling(): void
    {
        $keys = array_column(RaceEngineer::brief($this->inputs(['ctrCeiling' => null])), 'key');

        $this->assertNotContains('ctr', $keys);
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $o
     */
    private function ctrReason(array $plan, array $o = []): string
    {
        return $this->line($this->planned($plan, $o), 'ctr')['reason'];
    }

    /**
     * @param ?array{ctr: int, gain_more: int, cost_more: int} $next
     * @return array<string, mixed>
     */
    private function valuePlan(int $ctr, ?array $next, bool $paceKnown = true): array
    {
        return [
            'ctr' => $ctr,
            'ctr_kind' => 'value',
            'value' => [
                'gain' => $ctr * 60_000,
                'cost' => 300_000,
                'places' => round($ctr * 0.07, 1),
                'pace_known' => $paceKnown,
                'next' => $next,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stayAt(int $projected, int $relegation): array
    {
        return ['objective' => $this->objective(SeasonObjective::STAY, ['numbers' => [
            'pos' => $projected, 'pts' => 10, 'line_pos' => 3, 'gap' => 30, 'races_left' => 8,
            'points_on_offer' => 80, 'projected_pos' => $projected, 'end_cash' => null, 'relegation_pos' => $relegation,
        ]])];
    }

    public function testAnEnergyCapWithAKnownStartSaysTheStart(): void
    {
        $this->assertSame(
            "Capped at 30: your driver's energy (about 82% at the start) would run out above that",
            $this->ctrReason(['ctr' => 30, 'ctr_kind' => 'energy', 'energy_cap' => 30, 'energy_start' => 82]),
        );
    }

    public function testAnEstimatedEnergyCapNamesTheDivision(): void
    {
        $this->assertSame(
            "Capped at 35: a rough Amateur estimate of what your driver's energy allows",
            $this->ctrReason(
                ['ctr' => 35, 'ctr_kind' => 'energy', 'energy_cap' => 35, 'energy_estimate' => true, 'energy_start' => 80],
                ['division' => 'Amateur'],
            ),
        );
    }

    public function testAnEstimatedEnergyCapWithoutADivision(): void
    {
        $this->assertSame(
            "Capped at 35: a rough estimate of what your driver's energy allows",
            $this->ctrReason(['ctr' => 35, 'ctr_kind' => 'energy', 'energy_cap' => 35, 'energy_estimate' => true]),
        );
    }

    public function testAValueZeroWithoutPaceSaysTheLowestWearWins(): void
    {
        $this->assertSame(
            'No pace figures for the remaining tracks: the lowest wear wins',
            $this->ctrReason($this->valuePlan(0, null, false)),
        );
    }

    public function testAValueZeroWithANextCtrQuotesItsGainAndRepairs(): void
    {
        $this->assertSame(
            'A higher CTR earns less than its repairs cost (CTR 5: +$150k, repairs +$400k)',
            $this->ctrReason($this->valuePlan(0, ['ctr' => 5, 'gain_more' => 150_000, 'cost_more' => 400_000])),
        );
    }

    public function testAValueZeroWithoutANextCtr(): void
    {
        $this->assertSame('A higher CTR earns less than its repairs cost', $this->ctrReason($this->valuePlan(0, null)));
    }

    public function testAValuePickQuotesItsPlacesAndTheNextCtr(): void
    {
        $this->assertSame(
            'About +1.4 places a race (+$1.2M this season); CTR 25 would earn $300k more but cost $500k more in repairs',
            $this->ctrReason($this->valuePlan(20, ['ctr' => 25, 'gain_more' => 300_000, 'cost_more' => 500_000])),
        );
    }

    public function testAValuePickWithoutANextCtrSaysNoneFits(): void
    {
        $this->assertSame(
            'About +1.4 places a race (+$1.2M this season); no higher CTR fits the budget',
            $this->ctrReason($this->valuePlan(20, null)),
        );
    }

    public function testSafetyAboveTheZoneCountsThePlaces(): void
    {
        $this->assertSame(
            'P23 projected, 3 places above the relegation zone: the highest CTR the budget and energy allow',
            $this->ctrReason(['ctr' => 40, 'ctr_kind' => 'safety'], $this->stayAt(23, 26)),
        );
    }

    public function testSafetyInsideTheZoneSaysSo(): void
    {
        $this->assertSame(
            'P27 projected, inside the relegation zone: the highest CTR the budget and energy allow',
            $this->ctrReason(['ctr' => 40, 'ctr_kind' => 'safety'], $this->stayAt(27, 26)),
        );
        $this->assertSame(
            'P26 projected, inside the relegation zone: the highest CTR the budget and energy allow',
            $this->ctrReason(['ctr' => 40, 'ctr_kind' => 'safety'], $this->stayAt(26, 26)),
        );
    }

    public function testShortBeatsEnergyReason(): void
    {
        $this->assertSame(
            'Even at CTR 0 the forecast misses the target by $400k',
            $this->ctrReason([
                'ctr' => 0, 'mode' => 'short', 'shortfall' => 400_000,
                'ctr_kind' => 'energy', 'energy_cap' => 0, 'energy_start' => 30,
            ]),
        );
    }

    public function testNoPlanPathCapsByEnergy(): void
    {
        $line = $this->line($this->inputs([
            'ctrCeiling' => 40,
            'energyCap' => 25,
            'energyEstimate' => true,
            'division' => 'Rookie',
            'currentCtr' => 30,
        ]), 'ctr');

        $this->assertSame('CTR 25', $line['answer']);
        $this->assertSame("Capped at 25: a rough Rookie estimate of what your driver's energy allows", $line['reason']);
        $this->assertSame(['label' => 'Use 25', 'href' => '/engineer?cockpit_risk=25'], $line['action']);
    }

    public function testNoPlanPathKeepsTheWearCeilingBelowTheEnergyCap(): void
    {
        $line = $this->line($this->inputs(['ctrCeiling' => 40, 'energyCap' => 50, 'energyStart' => 90]), 'ctr');

        $this->assertSame('CTR 40', $line['answer']);
        $this->assertSame('Race rated Normal: the highest CTR where no part breaks; season costs unknown', $line['reason']);
    }

    public function testNothingToSwap(): void
    {
        $line = $this->line($this->inputs(), 'parts');

        $this->assertSame('Nothing to swap', $line['answer']);
        $this->assertSame('ink', $line['tone']);
        $this->assertSame('/cockpit#cockpit-wear', $line['anchor']);
    }

    public function testForcedSwapAmberAndRedWhenUnaffordable(): void
    {
        $base = ['wearAdvice' => $this->wearAdvice(), 'forcedSwapCost' => 410_000];

        $amber = $this->line($this->inputs($base), 'parts');
        $red = $this->line($this->inputs($base + ['outlook' => $this->outlook(100_000)]), 'parts');
        $unknown = $this->line($this->inputs($base + ['outlook' => $this->outlook(null)]), 'parts');

        $this->assertSame('Swap Engine', $amber['answer']);
        $this->assertSame('$410k', $amber['figure']);
        $this->assertSame('amber', $amber['tone']);
        $this->assertSame('red', $red['tone']);
        $this->assertSame('amber', $unknown['tone']);
    }

    public function testForcedSwapIsRedWhenTheBalanceCannotPayItAndTheOutlookIsUnknown(): void
    {
        $base = ['wearAdvice' => $this->wearAdvice(), 'forcedSwapCost' => 410_000, 'outlook' => $this->outlook(null)];

        $short = $this->line($this->inputs($base + ['cash' => 409_999]), 'parts');
        $enough = $this->line($this->inputs($base + ['cash' => 410_000]), 'parts');

        $this->assertSame('red', $short['tone']);
        $this->assertSame('amber', $enough['tone']);
    }

    public function testTrainingIsRecommendedWhenFreeCashCoversIt(): void
    {
        $line = $this->line($this->inputs(), 'training');

        $this->assertSame('Train Talent course', $line['answer']);
        $this->assertSame('$85k', $line['figure']);
        $this->assertSame('/cockpit#cockpit-training', $line['anchor']);
    }

    public function testNoTrainingPickIsNothingToDo(): void
    {
        $this->assertSame('Nothing to do', $this->line($this->inputs(['trainingPick' => null]), 'training')['answer']);
    }

    public function testPromoteSkipsTrainingWhenFreeCashDoesNotCoverIt(): void
    {
        $line = $this->line($this->inputs(['outlook' => $this->outlook(50_000)]), 'training');

        $this->assertSame('Skip Talent course training', $line['answer']);
        $this->assertSame('Free cash does not cover it', $line['reason']);
    }

    public function testStaySkipsTrainingWhenRepairsNeedTheMoney(): void
    {
        $in = $this->inputs([
            'objective' => $this->objective(SeasonObjective::STAY),
            'outlook' => $this->outlook(400_000),
            'forcedSwapCost' => 410_000,
        ]);

        $this->assertSame('Skip Talent course training', $this->line($in, 'training')['answer']);
    }

    public function testStayTrainsWhenRepairsAreCoveredToo(): void
    {
        $in = $this->inputs([
            'objective' => $this->objective(SeasonObjective::STAY),
            'outlook' => $this->outlook(500_000),
            'forcedSwapCost' => 410_000,
        ]);

        $this->assertSame('Train Talent course', $this->line($in, 'training')['answer']);
    }

    public function testUnknownOutlookJudgesTrainingAgainstTheBalance(): void
    {
        $unknown = $this->outlook(null);

        $enough = $this->line($this->inputs(['outlook' => $unknown, 'cash' => 90_000]), 'training');
        $short = $this->line($this->inputs(['outlook' => $unknown, 'cash' => 80_000]), 'training');

        $this->assertSame('Train Talent course', $enough['answer']);
        $this->assertSame('Your balance covers it; season costs unknown', $enough['reason']);
        $this->assertSame('Skip Talent course training', $short['answer']);
        $this->assertSame('Your balance does not cover it; season costs unknown', $short['reason']);
    }

    public function testKnownOutlookIgnoresTheBalance(): void
    {
        $in = $this->inputs(['outlook' => $this->outlook(5_000_000), 'cash' => 0]);

        $line = $this->line($in, 'training');

        $this->assertSame('Train Talent course', $line['answer']);
        $this->assertSame('Free cash covers it', $line['reason']);
    }

    public function testStayWithUnknownOutlookNeedsTheBalanceToCoverRepairsToo(): void
    {
        $base = [
            'outlook' => $this->outlook(null),
            'objective' => $this->objective(SeasonObjective::STAY),
            'forcedSwapCost' => 410_000,
        ];

        $enough = $this->line($this->inputs($base + ['cash' => 495_000]), 'training');
        $short = $this->line($this->inputs($base + ['cash' => 494_999]), 'training');

        $this->assertSame('Train Talent course', $enough['answer']);
        $this->assertSame('Your balance covers it and the forced repairs; season costs unknown', $enough['reason']);
        $this->assertSame('Skip Talent course training', $short['answer']);
        $this->assertSame('Stay: your balance must cover repairs first; season costs unknown', $short['reason']);
    }

    public function testUnknownOutlookCashLineSaysWhatSpendingIsCheckedAgainst(): void
    {
        $line = $this->line($this->inputs(['outlook' => $this->outlook(null)]), 'cash');

        $this->assertSame('Spending is checked against your balance until costs are known', $line['reason']);
    }

    public function testUnknownOutlookJudgesTestingAgainstTheBalance(): void
    {
        $unknown = $this->outlook(null);

        $enough = $this->line($this->inputs(['outlook' => $unknown, 'cash' => 300_000]), 'testing');
        $short = $this->line($this->inputs(['outlook' => $unknown, 'cash' => 299_999]), 'testing');
        $stay = $this->line($this->inputs([
            'outlook' => $unknown,
            'objective' => $this->objective(SeasonObjective::STAY),
            'forcedSwapCost' => 100_000,
            'cash' => 350_000,
        ]), 'testing');

        $this->assertSame('Test at Silverstone', $enough['answer']);
        $this->assertSame('Skip testing at Silverstone', $short['answer']);
        $this->assertSame('Skip testing at Silverstone', $stay['answer']);
    }

    public function testTestingIsRecommendedWhenAffordable(): void
    {
        $line = $this->line($this->inputs(), 'testing');

        $this->assertSame('Test at Silverstone', $line['answer']);
        $this->assertSame('Free cash covers it; tunes for Monza (race 10)', $line['reason']);
        $this->assertSame('$300k', $line['figure']);
        $this->assertSame('/cockpit#cockpit-testing', $line['anchor']);
    }

    public function testTestingFallsBackWhenTheTrackIsUnknown(): void
    {
        $noTrack = $this->line($this->inputs(['testingTrack' => null]), 'testing');
        $noName = $this->line($this->inputs([
            'testingTarget' => ['offset' => 1, 'season' => 'current', 'race' => 10, 'track_id' => null, 'track_name' => null, 'power' => 1, 'handling' => 1, 'acceleration' => 1],
        ]), 'testing');

        $this->assertSame('Test this week', $noTrack['answer']);
        $this->assertSame('Test at Silverstone', $noName['answer']);
        $this->assertSame('Free cash covers it; tunes for race 10', $noName['reason']);
    }

    public function testTestingClosedIsNothingToDo(): void
    {
        $this->assertSame('Nothing to do', $this->line($this->inputs(['testingOpen' => false]), 'testing')['answer']);
        $this->assertSame('Nothing to do', $this->line($this->inputs(['testingTarget' => null]), 'testing')['answer']);
    }

    public function testASkippedSessionAsksToStopCountingItsTrainingLaps(): void
    {
        $short = $this->outlook(100_000);
        $counted = $this->line($this->inputs(['outlook' => $short, 'testingLaps' => 100]), 'testing');
        $none = $this->line($this->inputs(['outlook' => $short, 'testingLaps' => 0]), 'testing');
        $tested = $this->line($this->inputs(['testingLaps' => 100]), 'testing');

        $this->assertSame('Skip testing at Silverstone', $counted['answer']);
        $this->assertStringEndsWith(
            '; set training laps to 0 on the Cockpit (it counts 100 laps of wear)',
            $counted['reason'],
        );
        $this->assertSame('/cockpit#cockpit-wear', $counted['anchor']);
        $this->assertStringNotContainsString('training laps', $none['reason']);
        $this->assertSame('/cockpit#cockpit-testing', $none['anchor']);
        $this->assertSame('Test at Silverstone', $tested['answer']);
        $this->assertStringNotContainsString('training laps', $tested['reason']);
    }

    public function testTestingSkippedWhenUnaffordable(): void
    {
        $line = $this->line($this->inputs(['outlook' => $this->outlook(100_000)]), 'testing');

        $this->assertSame('Skip testing at Silverstone', $line['answer']);
    }

    public function testRiskPassesTheAdvisorNumbersThrough(): void
    {
        $line = $this->line($this->inputs(), 'risk');

        $this->assertSame('Overtake 40 · Defend 25', $line['answer']);
        $this->assertNull($line['anchor']);
    }

    public function testStayLowersOvertake(): void
    {
        $in = $this->inputs(['objective' => $this->objective(SeasonObjective::STAY)]);

        $this->assertSame('Overtake 25 · Defend 25', $this->line($in, 'risk')['answer']);
    }

    public function testStayOvertakeNeverGoesBelowZero(): void
    {
        $risk = ['overtake' => 10, 'defend' => 5, 'phrase' => '', 'tip' => null, 'distance_tip' => '', 'settings' => []];
        $in = $this->inputs(['objective' => $this->objective(SeasonObjective::STAY), 'risk' => $risk]);

        $this->assertSame('Overtake 0 · Defend 5', $this->line($in, 'risk')['answer']);
    }

    public function testRiskOmittedWithoutAdvice(): void
    {
        $keys = array_column(RaceEngineer::brief($this->inputs(['risk' => null])), 'key');

        $this->assertNotContains('risk', $keys);
    }

    public function testStaleWeatherHoldsRiskAndBoost(): void
    {
        $in = $this->inputs(['weatherStale' => true]);

        $this->assertSame('After you save the race setup', $this->line($in, 'risk')['answer']);
        $this->assertSame('After you save the race setup', $this->line($in, 'boost')['answer']);
    }

    public function testBoostShowsTheExtraFuelAndLinksToStrategy(): void
    {
        $line = $this->line($this->inputs(), 'boost');

        $this->assertSame('Boost: 3 sets · +24 L', $line['answer']);
        $this->assertSame(9, RaceEngineer::BOOST_SETS * RaceEngineer::BOOST_LAPS_PER_SET);
        $this->assertSame(['label' => 'Laps in Strategy', 'href' => '/strategy'], $line['action']);
        $this->assertNull($line['anchor']);
    }

    public function testBoostOmittedWithoutLitres(): void
    {
        $keys = array_column(RaceEngineer::brief($this->inputs(['boostLitres' => null])), 'key');

        $this->assertNotContains('boost', $keys);
    }

    public function testStayDropsBoost(): void
    {
        $in = $this->inputs(['objective' => $this->objective(SeasonObjective::STAY)]);

        $line = $this->line($in, 'boost');

        $this->assertSame('No boost — save the fuel', $line['answer']);
        $this->assertNull($line['action']);
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $o
     */
    private function planned(array $plan = [], array $o = []): EngineerInputs
    {
        return $this->inputs($o + ['plan' => $this->plan($plan)]);
    }

    public function testCashSaysSafeToSpendAboveTheSeasonStart(): void
    {
        $line = $this->line($this->planned(['target_kind' => 'start', 'target' => 3_000_000, 'season_start' => 3_000_000]), 'cash');

        $this->assertSame('Safe to spend', $line['answer']);
        $this->assertSame('$600k', $line['figure']);
        $this->assertSame('ink', $line['tone']);
        $this->assertSame('Ends the season above your $3M start', $line['reason']);
        $this->assertSame('/season#season-budget', $line['anchor']);
    }

    public function testCashCushionReasonUnderPromote(): void
    {
        $line = $this->line($this->planned(o: ['objective' => $this->objective(SeasonObjective::PROMOTE)]), 'cash');

        $this->assertSame('Keeps one race of costs in hand', $line['reason']);
    }

    public function testCashCushionReasonUnderStaySaysTheStartIsUnknown(): void
    {
        $stay = $this->line($this->planned(o: ['objective' => $this->objective(SeasonObjective::STAY)]), 'cash');

        $this->assertSame('Keeps one race of costs in hand (season start unknown)', $stay['reason']);
    }

    public function testCashCushionReasonWhenTheStartIsBelowTheCushion(): void
    {
        $line = $this->line($this->planned(['season_start' => 1_000_000], ['objective' => $this->objective(SeasonObjective::STAY)]), 'cash');

        $this->assertSame('Keeps one race of costs in hand (above your $1M start)', $line['reason']);
    }

    public function testCashStartOutOfReachNamesTheStart(): void
    {
        $line = $this->line($this->planned(['target_kind' => 'start_out_of_reach', 'season_start' => 12_500_000]), 'cash');

        $this->assertSame('Keeps one race of costs in hand (your $12.5M start is out of reach)', $line['reason']);
    }

    public function testCashReserveOutOfReachNamesTheStart(): void
    {
        $line = $this->line($this->planned([
            'target_kind' => 'reserve_out_of_reach', 'season_start' => 10_000_000,
        ], ['objective' => $this->objective(SeasonObjective::PROMOTE)]), 'cash');

        $this->assertSame('Keeps one race of costs in hand (half your $10M start is out of reach)', $line['reason']);
    }

    public function testThePromoteReserveIsNamedInTheCashLine(): void
    {
        $line = $this->line($this->planned([
            'target_kind' => 'reserve', 'target' => 5_000_000, 'season_start' => 10_000_000,
        ], ['objective' => $this->objective(SeasonObjective::PROMOTE)]), 'cash');

        $this->assertSame('Ends the season above $5M, half your $10M start', $line['reason']);
    }

    public function testCashNothingToSpareWhenTheEnvelopeIsZero(): void
    {
        $line = $this->line($this->planned(['envelope' => 0]), 'cash');

        $this->assertSame('Nothing to spare', $line['answer']);
        $this->assertSame('The forecast only just meets the target', $line['reason']);
        $this->assertSame('amber', $line['tone']);
    }

    public function testCashOverBudgetWhenTheModeIsShort(): void
    {
        $line = $this->line($this->planned(['mode' => 'short', 'envelope' => 0, 'shortfall' => 450_000, 'ctr' => 0]), 'cash');

        $this->assertSame('Over budget', $line['answer']);
        $this->assertSame('The forecast misses the target by $450k', $line['reason']);
        $this->assertSame('red', $line['tone']);
        $this->assertSame('/season#season-budget', $line['anchor']);
    }

    public function testCashAppendsThePartialItems(): void
    {
        $line = $this->line($this->planned(['partial' => ['Gearbox price', 'race 12 wear']]), 'cash');

        $this->assertStringEndsWith('; forecast partial: Gearbox price, race 12 wear', $line['reason']);
    }

    public function testCashWithoutAPlanKeepsTheP123aLine(): void
    {
        $line = $this->line($this->inputs(), 'cash');

        $this->assertSame('Cash covers the season', $line['answer']);
        $this->assertSame('$5M', $line['figure']);
    }

    public function testCardAnchorsPointAtTheCockpit(): void
    {
        $lines = RaceEngineer::brief($this->planned());
        $anchors = [];
        foreach ($lines as $line) {
            if ($line['anchor'] === null) {
                continue;
            }
            $anchors[$line['key']] = $line['anchor'];
            $this->assertStringStartsWith('/', $line['anchor']);
        }
        $this->assertSame('/cockpit#cockpit-wear', $anchors['parts'] ?? null);
    }

    public function testCtrAnswersWithThePlanCtr(): void
    {
        $line = $this->line($this->planned(['ctr' => 52, 'safety' => 40]), 'ctr');

        $this->assertSame('CTR 52', $line['answer']);
        $this->assertSame('On 30 now', $line['figure']);
        $this->assertSame(['label' => 'Use 52', 'href' => '/engineer?cockpit_risk=52'], $line['action']);
        $this->assertSame('/cockpit#cockpit-wear', $line['anchor']);
    }

    public function testCtrHasNoActionWhenItAlreadyMatches(): void
    {
        $this->assertNull($this->line($this->planned(['ctr' => 30]), 'ctr')['action']);
    }

    public function testCtrAnswerIsTheSameWhateverTheCurrentCtr(): void
    {
        $seen = [];
        foreach ([0, 30, 60, 100] as $current) {
            $line = $this->line($this->planned(['ctr' => 38], ['currentCtr' => $current]), 'ctr');
            $seen[] = [$line['answer'], $line['reason']];
            $this->assertSame('On ' . $current . ' now', $line['figure']);
        }

        $this->assertCount(1, array_unique($seen, SORT_REGULAR));
        $this->assertSame('CTR 38', $seen[0][0]);
    }

    public function testCtrShortSaysEvenAtZero(): void
    {
        $line = $this->line($this->planned(['mode' => 'short', 'ctr' => 0, 'shortfall' => 450_000]), 'ctr');

        $this->assertSame('CTR 0', $line['answer']);
        $this->assertSame('Even at CTR 0 the forecast misses the target by $450k', $line['reason']);
    }

    public function testCtrPushNamesTheSwapsAndTheirCost(): void
    {
        $swaps = [['part' => 'Gearbox', 'cost' => 200_000], ['part' => 'Brakes', 'cost' => 100_000]];
        $line = $this->line($this->planned(['mode' => 'push', 'ctr' => 70, 'safety' => 55, 'swaps' => $swaps]), 'ctr');

        $this->assertSame('Race rated Normal: swap Gearbox + Brakes ($300k); the budget covers the swaps', $line['reason']);
    }

    public function testStayAtTheSafetyCeilingReadsLikeThePlainAdvice(): void
    {
        $base = ['objective' => $this->objective(SeasonObjective::STAY)];
        $budget = $this->line($this->planned(['ctr' => 20, 'safety' => 35], $base), 'ctr');
        $capped = $this->line($this->planned(['ctr' => 35, 'safety' => 35], $base), 'ctr');

        $this->assertSame('Race rated Normal: the highest CTR the budget covers to season end', $budget['reason']);
        $this->assertSame('Race rated Normal: the highest CTR where no part breaks', $capped['reason']);
    }

    public function testCtrBelowSafetyBlamesTheBudgetEvenWithARefusedCost(): void
    {
        $line = $this->line($this->planned(['ctr' => 25, 'safety' => 40, 'refused_swap_cost' => 250_000]), 'ctr');

        $this->assertSame('Race rated Normal: the highest CTR the budget covers to season end', $line['reason']);
    }

    public function testCtrNurseNamesTheRefusedSwaps(): void
    {
        $line = $this->line($this->planned(['ctr' => 40, 'safety' => 40, 'refused_swap_cost' => 250_000]), 'ctr');

        $this->assertSame("Race rated Normal: pushing past 40 needs \$250k of swaps the budget can't cover", $line['reason']);
    }

    public function testCtrBelowSafetyBlamesTheBudget(): void
    {
        $line = $this->line($this->planned(['ctr' => 25, 'safety' => 40]), 'ctr');

        $this->assertSame('CTR 25', $line['answer']);
        $this->assertSame('Race rated Normal: the highest CTR the budget covers to season end', $line['reason']);
    }

    public function testCtrNurseAtSafetyIsTheNoPartBreaksReason(): void
    {
        $line = $this->line($this->planned(['ctr' => 40, 'safety' => 40]), 'ctr');

        $this->assertSame('Race rated Normal: the highest CTR where no part breaks', $line['reason']);
    }

    public function testCtrReasonOmitsTheRatingWhenThereIsNone(): void
    {
        $line = $this->line($this->planned(['ctr' => 40, 'safety' => 40], ['pushAdvice' => null]), 'ctr');

        $this->assertSame('the highest CTR where no part breaks', $line['reason']);
    }

    public function testPartsPushSwapsTheForecastParts(): void
    {
        $replaced = [['part' => 'Gearbox', 'cost' => 200_000], ['part' => 'Brakes', 'cost' => 100_000]];
        $plan = ['mode' => 'push', 'ctr' => 70, 'swaps' => $replaced, 'rows' => $this->rowsReplacing($replaced)];

        $line = $this->line($this->planned($plan), 'parts');

        $this->assertSame('Swap Gearbox, Brakes', $line['answer']);
        $this->assertSame('$300k', $line['figure']);
        $this->assertSame('Lets you push to CTR 70', $line['reason']);
    }

    public function testPartsForcedComeFromThePlanNotTheCurrentCtr(): void
    {
        $replaced = [['part' => 'Engine', 'cost' => 410_000]];
        $in = $this->planned(['rows' => $this->rowsReplacing($replaced)], ['wearAdvice' => null, 'forcedSwapCost' => null]);

        $line = $this->line($in, 'parts');

        $this->assertSame('Swap Engine', $line['answer']);
        $this->assertSame('$410k', $line['figure']);
        $this->assertSame('Would break this race if kept', $line['reason']);
        $this->assertSame('amber', $line['tone']);
    }

    public function testPartsForcedIsAmberEvenWhenTheEnvelopeIsSmaller(): void
    {
        $replaced = [['part' => 'Engine', 'cost' => 410_000]];

        $line = $this->line($this->planned(['envelope' => 0, 'rows' => $this->rowsReplacing($replaced)]), 'parts');

        $this->assertSame('amber', $line['tone']);
    }

    public function testPartsNothingToSwapUnderAPlan(): void
    {
        $this->assertSame('Nothing to swap', $this->line($this->planned(), 'parts')['answer']);
    }

    public function testPartsIgnoreTheOldAdviceWhenThereIsAPlan(): void
    {
        $in = $this->planned([], ['wearAdvice' => $this->wearAdvice(), 'forcedSwapCost' => 410_000]);

        $this->assertSame('Nothing to swap', $this->line($in, 'parts')['answer']);
    }

    public function testBudgetTrainingIsCoveredAndStayKeepsNoRepairReserve(): void
    {
        $in = $this->planned(['envelope' => 100_000], [
            'objective' => $this->objective(SeasonObjective::STAY),
            'forcedSwapCost' => 410_000,
        ]);

        $line = $this->line($in, 'training');

        $this->assertSame('Train Talent course', $line['answer']);
        $this->assertSame('The budget covers it', $line['reason']);
    }

    public function testBudgetTrainingSkippedWhenTheEnvelopeIsTooSmall(): void
    {
        $line = $this->line($this->planned(['envelope' => 50_000]), 'training');

        $this->assertSame('Skip Talent course training', $line['answer']);
        $this->assertSame('Only $50k left in the budget', $line['reason']);
    }

    public function testASkipNamesWhatItSkipsAndItsPrice(): void
    {
        $in = $this->inputs(['outlook' => $this->outlook(50_000)]);
        $training = $this->line($in, 'training');
        $testing = $this->line($in, 'testing');
        $noTrack = $this->line($this->inputs(['outlook' => $this->outlook(50_000), 'testingTrack' => null]), 'testing');

        $this->assertSame(['Skip Talent course training', '$85k'], [$training['answer'], $training['figure']]);
        $this->assertSame(['Skip testing at Silverstone', '$300k'], [$testing['answer'], $testing['figure']]);
        $this->assertSame('Skip testing this week', $noTrack['answer']);
    }

    public function testAnEmptyEnvelopeSaysNothingIsLeft(): void
    {
        $line = $this->line($this->planned(['envelope' => 0]), 'training');

        $this->assertSame('Skip Talent course training', $line['answer']);
        $this->assertSame('Nothing left in the budget', $line['reason']);
    }

    public function testBudgetTestingIsCoveredWithItsTrack(): void
    {
        $line = $this->line($this->planned(['envelope' => 1_000_000]), 'testing');

        $this->assertSame('Test at Silverstone', $line['answer']);
        $this->assertSame('The budget covers it; tunes for Monza (race 10)', $line['reason']);
    }

    public function testDiscretionaryLinesDrawFromTheEnvelopeInOrder(): void
    {
        $in = $this->planned(['envelope' => 600_000], [
            'trainingPick' => ['name' => 'Talent course', 'score' => 1.0, 'cost' => 550_000, 'gains' => []],
            'testingCost' => 120_000,
        ]);

        $training = $this->line($in, 'training');
        $testing = $this->line($in, 'testing');

        $this->assertSame('Train Talent course', $training['answer']);
        $this->assertSame('Skip testing at Silverstone', $testing['answer']);
        $this->assertSame('Only $50k left in the budget', $testing['reason']);
    }

    public function testASkippedTrainingLeavesItsMoneyForTesting(): void
    {
        $in = $this->planned(['envelope' => 200_000], [
            'trainingPick' => ['name' => 'Talent course', 'score' => 1.0, 'cost' => 250_000, 'gains' => []],
            'testingCost' => 120_000,
        ]);

        $this->assertSame('Skip Talent course training', $this->line($in, 'training')['answer']);
        $this->assertSame('Test at Silverstone', $this->line($in, 'testing')['answer']);
    }

    public function testPushPartsFigureCountsForcedSwapsWhileTheCtrReasonCountsOnlyThePushSwaps(): void
    {
        // The Chassis is past the limit at any CTR, so it is no push swap; the parts line still pays for it.
        $replaced = [['part' => 'Chassis', 'cost' => 300_000], ['part' => 'Engine', 'cost' => 100_000]];
        $in = $this->planned([
            'mode' => 'push', 'ctr' => 90, 'safety' => 60,
            'swaps' => [['part' => 'Engine', 'cost' => 100_000]], 'rows' => $this->rowsReplacing($replaced),
        ]);

        $this->assertSame('$400k', $this->line($in, 'parts')['figure']);
        $this->assertSame(
            'Race rated Normal: swap Engine ($100k); the budget covers the swaps',
            $this->line($in, 'ctr')['reason'],
        );
    }

    /**
     * @return array{part: string, level: int, kind: string, cost: int, net_cost: int, reason: string}
     */
    private function pick(
        string $part,
        int $level,
        string $kind,
        int $cost,
        string $reason = 'most_pha',
        int $net = 0,
    ): array {
        return [
            'part' => $part, 'level' => $level, 'kind' => $kind, 'cost' => $cost, 'net_cost' => $net,
            'reason' => $reason,
        ];
    }

    /**
     * @param list<array<string, mixed>> $picks
     * @param array<string, mixed> $o
     */
    private function withPicks(array $picks, array $o = []): EngineerInputs
    {
        $replaced = [['part' => 'Brakes', 'cost' => 100_000], ['part' => 'Cooling', 'cost' => 200_000]];

        return $this->planned(['rows' => $this->rowsReplacing($replaced)], $o + ['swapPicks' => $picks]);
    }

    public function testAForcedPickTakesItsNetCostOutOfEveryLineThatSpends(): void
    {
        // A 600k envelope; the Cooling upgrade nets 500k over the same-level part the plan already pays for.
        $in = $this->withPicks([$this->pick('Cooling', 7, 'upgrade', 900_000, net: 500_000)], [
            'objective' => $this->objective(SeasonObjective::PROMOTE),
        ]);

        $this->assertSame('$100k', $this->line($in, 'cash')['figure']);
        $this->assertSame('Swap Brakes, Cooling → L7 (upgrade)', $this->line($in, 'parts')['answer']);
        $this->assertSame('Train Talent course', $this->line($in, 'training')['answer']);
        $this->assertSame('Only $15k left in the budget', $this->line($in, 'testing')['reason']);
        $this->assertSame(15_000, RaceEngineer::upgradeBudget($in));
    }

    public function testAPickThatSavesMoneyAddsNothingToTheEnvelope(): void
    {
        $in = $this->withPicks([$this->pick('Cooling', 5, 'sidegrade', 200_000, net: -300_000)]);

        $this->assertSame('$600k', $this->line($in, 'cash')['figure']);
    }

    public function testThePartsLineNamesEachSwapsLevel(): void
    {
        $line = $this->line($this->withPicks([
            $this->pick('Brakes', 4, 'free_downgrade', 0),
            $this->pick('Cooling', 7, 'upgrade', 900_000),
        ]), 'parts');

        $this->assertSame('Swap Brakes → L4 (free downgrade), Cooling → L7 (upgrade)', $line['answer']);
        $this->assertSame(Money::short(900_000), $line['figure']);
        $this->assertSame('Would break this race if kept; most PHA the season budget allows', $line['reason']);
        $this->assertSame('amber', $line['tone']);
    }

    public function testEveryKindHasItsWord(): void
    {
        $line = $this->line($this->withPicks([
            $this->pick('Brakes', 4, 'downgrade', 50_000),
            $this->pick('Cooling', 5, 'sidegrade', 200_000),
        ]), 'parts');

        $this->assertSame('Swap Brakes → L4 (downgrade), Cooling → L5 (same level)', $line['answer']);
        $this->assertSame('$250k', $line['figure']);
    }

    public function testABudgetShortPickSaysSo(): void
    {
        $line = $this->line($this->withPicks([
            $this->pick('Brakes', 5, 'sidegrade', 100_000, 'budget_short'),
            $this->pick('Cooling', 7, 'upgrade', 900_000),
        ]), 'parts');

        $this->assertSame('Budget short: cheapest option that keeps your car in place', $line['reason']);
        $this->assertSame('amber', $line['tone']);
    }

    public function testAPartWithoutAPickKeepsItsNameAndPrice(): void
    {
        $line = $this->line($this->withPicks([$this->pick('Cooling', 7, 'upgrade', 900_000)]), 'parts');

        $this->assertSame('Swap Brakes, Cooling → L7 (upgrade)', $line['answer']);
        $this->assertSame('$1M', $line['figure']);
    }

    public function testWithoutPicksThePartsLineIsUnchanged(): void
    {
        $replaced = [['part' => 'Engine', 'cost' => 410_000]];
        $line = $this->line($this->planned(['rows' => $this->rowsReplacing($replaced)]), 'parts');

        $this->assertSame('Swap Engine', $line['answer']);
        $this->assertSame('$410k', $line['figure']);
        $this->assertSame('Would break this race if kept', $line['reason']);
        $this->assertSame('amber', $line['tone']);
    }

    /**
     * @return array<string, mixed>
     */
    private function upgrade(string $part = 'Engine', int $to = 4, int $net = 800_000, bool $affordable = true): array
    {
        return [
            'part' => $part, 'from' => $to - 1, 'to' => $to, 'price' => $net + 100_000, 'net_cost' => $net,
            'gain' => 1.5, 'affordable' => $affordable,
        ];
    }

    /**
     * @param list<array<string, mixed>> $upgrades
     * @param array<string, mixed> $o
     */
    private function withUpgrades(array $upgrades, array $o = [], array $plan = []): EngineerInputs
    {
        return $this->planned($plan + ['upgrades' => $upgrades], $o);
    }

    public function testUpgradePickNamesPartLevelAndNetCost(): void
    {
        $in = $this->withUpgrades(
            [$this->upgrade('Brakes', 5, 400_000, false), $this->upgrade('Engine', 4, 800_000)],
            plan: ['rows' => $this->plan()['rows'] + [1 => [], 2 => []]],
        );
        $line = $this->line($in, 'upgrades');

        $this->assertSame('Upgrade Engine to L4', $line['answer']);
        $this->assertSame('$800k net', $line['figure']);
        $this->assertSame('Most PHA per $ over the 3 races left', $line['reason']);
        $this->assertSame('/season#season-upgrades', $line['anchor']);
        $this->assertSame('ink', $line['tone']);
        $this->assertNull($line['action']);
    }

    public function testUpgradePickAddsTheGroupCarLevel(): void
    {
        $in = $this->withUpgrades([$this->upgrade()], ['carStanding' => ['level' => 14, 'rank' => 22, 'of' => 30]]);

        $this->assertSame(
            'Most PHA per $ over the 1 race left; car level 14, 22nd of 30 in your group',
            $this->line($in, 'upgrades')['reason'],
        );
    }

    public function testUpgradeWithNoExtraCost(): void
    {
        $line = $this->line($this->withUpgrades([$this->upgrade(net: 0)]), 'upgrades');

        $this->assertSame('no extra cost', $line['figure']);
    }

    public function testNoUpgradeFitsTheBudget(): void
    {
        $in = $this->withUpgrades([$this->upgrade('Gearbox', 3, 1_200_000, false)], plan: ['envelope' => 900_000]);
        $line = $this->line($in, 'upgrades');

        $this->assertSame('No upgrade fits the budget', $line['answer']);
        $this->assertNull($line['figure']);
        $this->assertSame('Best: Gearbox L3 for a net $1.2M; $515k left', $line['reason']);
        $this->assertSame('/season#season-upgrades', $line['anchor']);
    }

    public function testANetThatFitsButAPriceThatDoesNotNamesThePriceUpFront(): void
    {
        $best = ['price' => 1_300_000] + $this->upgrade('Gearbox', 3, 300_000, false);
        $in = $this->withUpgrades([$best], plan: ['envelope' => 900_000]);
        $line = $this->line($in, 'upgrades');

        $this->assertSame('No upgrade fits the budget', $line['answer']);
        $this->assertSame('Best: Gearbox L3 needs $1.3M up front; $515k left', $line['reason']);
    }

    public function testNoUpgradeFitsAnEmptyBudget(): void
    {
        $in = $this->withUpgrades([$this->upgrade('Gearbox', 3, 1_200_000, false)], plan: ['envelope' => 0]);

        $this->assertSame(
            'Best: Gearbox L3 for a net $1.2M; nothing left',
            $this->line($in, 'upgrades')['reason'],
        );
    }

    public function testNothingWorthBuying(): void
    {
        $line = $this->line($this->withUpgrades([]), 'upgrades');

        $this->assertSame('Nothing worth buying', $line['answer']);
        $this->assertSame('No level above yours adds PHA for the remaining tracks', $line['reason']);
    }

    public function testAnInfeasiblePlanForecastSaysThePlanRunsShort(): void
    {
        $line = $this->line($this->withUpgrades([], plan: ['infeasible' => true]), 'upgrades');

        $this->assertSame('No upgrade fits the budget', $line['answer']);
        $this->assertSame('The season plan already runs short', $line['reason']);
        $this->assertNull($line['figure']);
        $this->assertSame('/season#season-upgrades', $line['anchor']);
    }

    public function testUpgradesLineOmittedWithoutAPlanOrRanking(): void
    {
        $keys = static fn(EngineerInputs $in): array => array_column(RaceEngineer::brief($in), 'key');

        $this->assertNotContains('upgrades', $keys($this->inputs()));
        $this->assertNotContains('upgrades', $keys($this->planned()));
        $this->assertContains('upgrades', $keys($this->withUpgrades([])));
    }

    public function testUpgradeBudgetDrawsAfterTesting(): void
    {
        $training = ['name' => 'Talent course', 'score' => 1.0, 'cost' => 550_000, 'gains' => []];

        $covered = $this->withUpgrades([], ['trainingPick' => $training, 'testingCost' => 1_000_000], ['envelope' => 2_000_000]);
        $skipped = $this->withUpgrades([], ['trainingPick' => $training, 'testingCost' => 2_000_000], ['envelope' => 2_000_000]);
        $closed = $this->withUpgrades([], ['trainingPick' => $training, 'testingOpen' => false], ['envelope' => 2_000_000]);

        $this->assertSame(450_000, RaceEngineer::upgradeBudget($covered));
        $this->assertSame(1_450_000, RaceEngineer::upgradeBudget($skipped));
        $this->assertSame(1_450_000, RaceEngineer::upgradeBudget($closed));
        $this->assertSame(0, RaceEngineer::upgradeBudget($this->inputs()));
    }

    public function testUpgradeBudgetTakesTestingOnlyWhenTheTestingLineSpendsIt(): void
    {
        $training = ['name' => 'Talent course', 'score' => 1.0, 'cost' => 550_000, 'gains' => []];
        $states = [
            'fits' => ['testingCost' => 1_000_000],
            'too dear' => ['testingCost' => 1_450_001],
            'exact' => ['testingCost' => 1_450_000],
            'closed' => ['testingOpen' => false],
            'no target' => ['testingTarget' => null],
            'free' => ['testingCost' => 0],
        ];

        foreach ($states as $state => $o) {
            $in = $this->withUpgrades([], $o + ['trainingPick' => $training], ['envelope' => 2_000_000]);
            $testing = $this->line($in, 'testing');
            $spends = str_starts_with($testing['answer'], 'Test at');

            $this->assertSame(
                1_450_000 - ($spends ? $in->testingCost : 0),
                RaceEngineer::upgradeBudget($in),
                $state,
            );
        }
    }

    public function testUpgradeBudgetNeverGoesBelowZero(): void
    {
        $in = $this->withUpgrades([], ['trainingPick' => null, 'testingCost' => 100_000], ['envelope' => -50_000]);

        $this->assertSame(0, RaceEngineer::upgradeBudget($in));
    }

    public function testStaffNudgeForPromote(): void
    {
        $staff = ['rank' => 27, 'of' => 30, 'own' => 41, 'band' => 'top', 'band_average' => 63, 'nudge' => true];
        $line = $this->line($this->inputs(['staffStanding' => $staff]), 'staff');

        $this->assertSame('Staff and facilities trail your group', $line['answer']);
        $this->assertSame('Staff OA 27th of 30; the top third averages 63, you have 41', $line['reason']);
        $this->assertSame('amber', $line['tone']);
        $this->assertNull($line['figure']);
        $this->assertNull($line['action']);
    }

    public function testStaffNudgeForStayNamesTheMiddleThird(): void
    {
        $staff = ['rank' => 21, 'of' => 30, 'own' => 50, 'band' => 'middle', 'band_average' => 58, 'nudge' => true];

        $this->assertSame(
            'Staff OA 21st of 30; the middle third averages 58, you have 50',
            $this->line($this->inputs(['staffStanding' => $staff]), 'staff')['reason'],
        );
    }

    public function testNoStaffLineWithoutANudge(): void
    {
        $staff = ['rank' => 3, 'of' => 30, 'own' => 70, 'band' => 'top', 'band_average' => 63, 'nudge' => false];
        $keys = static fn(EngineerInputs $in): array => array_column(RaceEngineer::brief($in), 'key');

        $this->assertNotContains('staff', $keys($this->inputs(['staffStanding' => $staff])));
        $this->assertNotContains('staff', $keys($this->inputs()));
    }

    public function testUpgradesFollowThePartsAndStaffComesBeforeThem(): void
    {
        $staff = ['rank' => 27, 'of' => 30, 'own' => 41, 'band' => 'top', 'band_average' => 63, 'nudge' => true];
        $in = $this->withUpgrades([$this->upgrade()], ['staffStanding' => $staff]);

        $this->assertSame(
            ['objective', 'cash', 'staff', 'parts', 'upgrades', 'training', 'testing', 'ctr', 'risk', 'boost'],
            array_column(RaceEngineer::brief($in), 'key'),
        );
    }

    public function testNoLineClaimsAGain(): void
    {
        foreach ([SeasonObjective::PROMOTE, SeasonObjective::STAY] as $objective) {
            $in = $this->inputs([
                'objective' => $this->objective($objective),
                'pushAdvice' => CarWearPlannerService::ADVICE_PUSH,
                'wearAdvice' => $this->wearAdvice(),
                'forcedSwapCost' => 410_000,
                'currentCtr' => 60,
            ]);

            foreach (RaceEngineer::brief($in) as $line) {
                $text = $line['answer'] . ' ' . $line['reason'];
                $this->assertDoesNotMatchRegularExpression('/gain|\+\s*\d*\s*places?/i', $text, $line['key']);
            }
        }
    }
}
