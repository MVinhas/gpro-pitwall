<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\EngineerInputs;
use App\Engineer\RaceEngineer;
use App\Engineer\SeasonObjective;
use App\Service\CarWearPlannerService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RaceEngineer::class)]
#[CoversClass(EngineerInputs::class)]
final class RaceEngineerTest extends TestCase
{
    private const array KEYS = ['objective', 'cash', 'ctr', 'parts', 'training', 'testing', 'risk', 'boost'];

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
            'objective' => $this->objective(SeasonObjective::HOLD),
            'outlook' => $this->outlook(5_000_000),
            'cash' => 1_000_000,
            'currentCtr' => 30,
            'ctrCeiling' => 45,
            'ctrSurvive' => 35,
            'alwaysRed' => [],
            'pushAdvice' => CarWearPlannerService::ADVICE_NORMAL,
            'wearAdvice' => null,
            'forcedSwapCost' => null,
            'optionalSwaps' => [],
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
            'v' => 1, 'ctr' => 40, 'mode' => 'nurse', 'safety' => 40, 'swaps' => [],
            'refused_swap_cost' => null, 'target' => 2_000_000, 'target_kind' => 'cushion',
            'cushion' => 2_000_000, 'season_start' => null, 'envelope' => 600_000,
            'shortfall' => null, 'rows' => [$row], 'partial' => [], 'race' => 8,
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

        $this->assertSame('Hold', $line['answer']);
        $this->assertSame('Projected to finish P3', $line['reason']);
        $this->assertSame('ink', $line['tone']);
        $this->assertNull($line['anchor']);
    }

    public function testObjectiveWarningIsAmberButNotRepeatedAsTheReason(): void
    {
        $warned = $this->objective(SeasonObjective::HOLD, ['warning' => 'Negative balance relegates']);

        $line = $this->line($this->inputs(['objective' => $warned]), 'objective');

        $this->assertSame('amber', $line['tone']);
        $this->assertSame('Projected to finish P3', $line['reason']);
    }

    public function testObjectiveWarningAloneLeavesNoReason(): void
    {
        $warned = $this->objective(SeasonObjective::HOLD, ['warning' => 'Negative balance relegates', 'reasons' => []]);

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

        $a = $this->line($this->inputs(['objective' => $this->objective(SeasonObjective::HOLD, ['numbers' => $inside])]), 'objective');
        $b = $this->line($this->inputs(['objective' => $this->objective(SeasonObjective::HOLD, ['numbers' => $blank])]), 'objective');
        $c = $this->line($this->inputs(['objective' => $this->objective(SeasonObjective::HOLD, ['numbers' => ['pos' => 4, 'gap' => null, 'line_pos' => null, 'races_left' => 12]])]), 'objective');

        $this->assertSame('P2 · 1 race left', $a['figure']);
        $this->assertNull($b['figure']);
        $this->assertSame('P4 · 12 races left', $c['figure']);
    }

    public function testNoProposalExplainsWhyInsteadOfRepeatingTheHeader(): void
    {
        $unknown = $this->objective(null, ['reasons' => []]);

        $line = $this->line($this->inputs(['objective' => $unknown]), 'objective');

        $this->assertSame('Objective unknown', $line['answer']);
        $this->assertSame("Your position in the group isn't known yet; treated as Hold", $line['reason']);
    }

    public function testPinWithoutAProposalStillSaysWhyTheEngineerHasNone(): void
    {
        $pinned = SeasonObjective::inForce($this->objective(null, ['reasons' => []]), SeasonObjective::SURVIVE);

        $line = $this->line($this->inputs(['objective' => $pinned]), 'objective');

        $this->assertSame('Your call: Survive (engineer has no proposal)', $line['answer']);
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
        $this->assertSame(['label' => 'Use 45', 'href' => '/cockpit?cockpit_risk=45'], $line['action']);
        $this->assertStringContainsString('Push', $line['reason']);
        $this->assertStringContainsString('no part breaks', $line['reason']);
        $this->assertSame('#cockpit-wear', $line['anchor']);
    }

    public function testPromoteOnANormalRaceAdvisesTheCeilingNotTheCurrentCtr(): void
    {
        $line = $this->line($this->inputs(['objective' => $this->objective(SeasonObjective::PROMOTE)]), 'ctr');

        $this->assertSame('CTR 45', $line['answer']);
    }

    public function testHoldAdvisesTheCeilingEvenWhenThePlanIsNotRaisingCtr(): void
    {
        $in = $this->inputs(['pushAdvice' => CarWearPlannerService::ADVICE_PUSH]);

        $this->assertSame('CTR 45', $this->line($in, 'ctr')['answer']);
    }

    public function testPickingZeroNeverMakesTheEngineerSuggestZero(): void
    {
        $line = $this->line($this->inputs(['currentCtr' => 0]), 'ctr');

        $this->assertSame('CTR 45', $line['answer']);
        $this->assertSame(['label' => 'Use 45', 'href' => '/cockpit?cockpit_risk=45'], $line['action']);
        $this->assertSame('On 0 now', $line['figure']);
    }

    public function testHoldLowersCtrToTheCeiling(): void
    {
        $line = $this->line($this->inputs(['currentCtr' => 60]), 'ctr');

        $this->assertSame('CTR 45', $line['answer']);
        $this->assertSame('/cockpit?cockpit_risk=45', $line['action']['href'] ?? null);
    }

    public function testWithoutAPlanTheReasonSaysSeasonCostsAreUnknown(): void
    {
        $line = $this->line($this->inputs(), 'ctr');

        $this->assertSame('Race rated Normal: the highest CTR where no part breaks; season costs unknown', $line['reason']);
    }

    public function testNoObjectiveFollowsTheHoldRule(): void
    {
        $in = $this->inputs([
            'objective' => $this->objective(null),
            'pushAdvice' => CarWearPlannerService::ADVICE_PUSH,
        ]);

        $this->assertSame('CTR 45', $this->line($in, 'ctr')['answer']);
    }

    public function testSurviveUsesTheRiskyCeiling(): void
    {
        $in = $this->inputs([
            'objective' => $this->objective(SeasonObjective::SURVIVE),
            'currentCtr' => 60,
            'ctrSurvive' => 35,
        ]);

        $line = $this->line($in, 'ctr');

        $this->assertSame('CTR 35', $line['answer']);
        $this->assertStringContainsString('out of the red', $line['reason']);
    }

    public function testSurviveNamesPartsThatStayRedAtAnyCtr(): void
    {
        $in = $this->inputs([
            'objective' => $this->objective(SeasonObjective::SURVIVE),
            'currentCtr' => 60,
            'ctrSurvive' => 35,
            'alwaysRed' => ['Engine', 'Brakes'],
        ]);

        $reason = $this->line($in, 'ctr')['reason'];

        $this->assertSame('Engine, Brakes stay in the red at any CTR; capped where nothing breaks', $reason);
        $this->assertStringNotContainsString('keeps parts out of the red', $reason);
    }

    public function testSurviveNamesASinglePartInTheSingular(): void
    {
        $in = $this->inputs([
            'objective' => $this->objective(SeasonObjective::SURVIVE),
            'alwaysRed' => ['Engine'],
        ]);

        $this->assertStringContainsString('Engine stays in the red at any CTR; capped where nothing breaks', $this->line($in, 'ctr')['reason']);
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

    public function testNothingToSwap(): void
    {
        $line = $this->line($this->inputs(), 'parts');

        $this->assertSame('Nothing to swap', $line['answer']);
        $this->assertSame('ink', $line['tone']);
        $this->assertSame('#cockpit-wear', $line['anchor']);
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

    public function testOptionalSwapReasonsNameTheBalanceWhenTheOutlookIsUnknown(): void
    {
        $unknown = $this->outlook(null);
        $promote = $this->objective(SeasonObjective::PROMOTE);
        $optional = [['part' => 'Gearbox', 'cost' => 200_000]];

        $alone = $this->line($this->inputs([
            'objective' => $promote, 'outlook' => $unknown, 'optionalSwaps' => $optional,
        ]), 'parts');
        $mixed = $this->line($this->inputs([
            'objective' => $promote, 'outlook' => $unknown, 'optionalSwaps' => $optional,
            'wearAdvice' => $this->wearAdvice(), 'forcedSwapCost' => 410_000,
        ]), 'parts');
        $known = $this->line($this->inputs(['objective' => $promote, 'optionalSwaps' => $optional]), 'parts');

        $this->assertSame('In the red zone, and your balance covers it; season costs unknown', $alone['reason']);
        $this->assertStringContainsString('and your balance covers them; season costs unknown', $mixed['reason']);
        $this->assertStringNotContainsString('free cash', strtolower($mixed['reason'] . $alone['reason']));
        $this->assertSame('In the red zone, and free cash covers it', $known['reason']);
    }

    public function testOptionalSwapsOnlyUnderPromote(): void
    {
        $optional = [['part' => 'Gearbox', 'cost' => 200_000]];
        $base = ['optionalSwaps' => $optional];

        $hold = $this->line($this->inputs($base), 'parts');
        $promote = $this->line($this->inputs($base + ['objective' => $this->objective(SeasonObjective::PROMOTE)]), 'parts');

        $this->assertSame('Nothing to swap', $hold['answer']);
        $this->assertStringContainsString('Gearbox', $promote['answer']);
        $this->assertSame('$200k', $promote['figure']);
        $this->assertSame('ink', $promote['tone']);
    }

    public function testOptionalSwapsNeedFreeCashBeyondTheForcedOnes(): void
    {
        $in = $this->inputs([
            'objective' => $this->objective(SeasonObjective::PROMOTE),
            'wearAdvice' => $this->wearAdvice(),
            'forcedSwapCost' => 410_000,
            'optionalSwaps' => [['part' => 'Gearbox', 'cost' => 200_000], ['part' => 'Brakes', 'cost' => 100_000]],
            'outlook' => $this->outlook(650_000),
        ]);

        $line = $this->line($in, 'parts');

        $this->assertStringContainsString('Gearbox', $line['answer']);
        $this->assertStringNotContainsString('Brakes', $line['answer']);
        $this->assertSame('$610k', $line['figure']);
        $this->assertSame('amber', $line['tone']);
    }

    public function testOptionalSwapsUseTheBalanceWhenTheOutlookIsUnknown(): void
    {
        $base = [
            'objective' => $this->objective(SeasonObjective::PROMOTE),
            'outlook' => $this->outlook(null),
            'wearAdvice' => $this->wearAdvice(),
            'forcedSwapCost' => 410_000,
            'optionalSwaps' => [['part' => 'Gearbox', 'cost' => 200_000]],
        ];

        $enough = $this->line($this->inputs($base + ['cash' => 610_000]), 'parts');
        $short = $this->line($this->inputs($base + ['cash' => 609_999]), 'parts');

        $this->assertStringContainsString('Gearbox', $enough['answer']);
        $this->assertSame('Swap Engine', $short['answer']);
    }

    public function testTrainingIsRecommendedWhenFreeCashCoversIt(): void
    {
        $line = $this->line($this->inputs(), 'training');

        $this->assertSame('Train Talent course', $line['answer']);
        $this->assertSame('$85k', $line['figure']);
        $this->assertSame('#cockpit-training', $line['anchor']);
    }

    public function testNoTrainingPickIsNothingToDo(): void
    {
        $this->assertSame('Nothing to do', $this->line($this->inputs(['trainingPick' => null]), 'training')['answer']);
    }

    public function testHoldSkipsTrainingWhenFreeCashDoesNotCoverIt(): void
    {
        $line = $this->line($this->inputs(['outlook' => $this->outlook(50_000)]), 'training');

        $this->assertSame('Skip Talent course training', $line['answer']);
        $this->assertSame('Free cash does not cover it', $line['reason']);
    }

    public function testSurviveSkipsTrainingWhenRepairsNeedTheMoney(): void
    {
        $in = $this->inputs([
            'objective' => $this->objective(SeasonObjective::SURVIVE),
            'outlook' => $this->outlook(400_000),
            'forcedSwapCost' => 410_000,
        ]);

        $this->assertSame('Skip Talent course training', $this->line($in, 'training')['answer']);
    }

    public function testSurviveTrainsWhenRepairsAreCoveredToo(): void
    {
        $in = $this->inputs([
            'objective' => $this->objective(SeasonObjective::SURVIVE),
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

    public function testSurviveWithUnknownOutlookNeedsTheBalanceToCoverRepairsToo(): void
    {
        $base = [
            'outlook' => $this->outlook(null),
            'objective' => $this->objective(SeasonObjective::SURVIVE),
            'forcedSwapCost' => 410_000,
        ];

        $enough = $this->line($this->inputs($base + ['cash' => 495_000]), 'training');
        $short = $this->line($this->inputs($base + ['cash' => 494_999]), 'training');

        $this->assertSame('Train Talent course', $enough['answer']);
        $this->assertSame('Your balance covers it and the forced repairs; season costs unknown', $enough['reason']);
        $this->assertSame('Skip Talent course training', $short['answer']);
        $this->assertSame('Survive: your balance must cover repairs first; season costs unknown', $short['reason']);
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
        $survive = $this->line($this->inputs([
            'outlook' => $unknown,
            'objective' => $this->objective(SeasonObjective::SURVIVE),
            'forcedSwapCost' => 100_000,
            'cash' => 350_000,
        ]), 'testing');

        $this->assertSame('Test at Silverstone', $enough['answer']);
        $this->assertSame('Skip testing at Silverstone', $short['answer']);
        $this->assertSame('Skip testing at Silverstone', $survive['answer']);
    }

    public function testTestingIsRecommendedWhenAffordable(): void
    {
        $line = $this->line($this->inputs(), 'testing');

        $this->assertSame('Test at Silverstone', $line['answer']);
        $this->assertSame('Free cash covers it; tunes for Monza (race 10)', $line['reason']);
        $this->assertSame('$300k', $line['figure']);
        $this->assertSame('#cockpit-testing', $line['anchor']);
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

    public function testUnknownTestingCostStillNamesTheTarget(): void
    {
        $line = $this->line($this->inputs(['testingCost' => null]), 'testing');

        $this->assertSame('Test at Silverstone — cost unknown', $line['answer']);
        $this->assertSame('No earlier session this season to price it; tunes for Monza (race 10)', $line['reason']);
    }

    public function testTestingClosedIsNothingToDo(): void
    {
        $this->assertSame('Nothing to do', $this->line($this->inputs(['testingOpen' => false]), 'testing')['answer']);
        $this->assertSame('Nothing to do', $this->line($this->inputs(['testingTarget' => null]), 'testing')['answer']);
    }

    public function testTestingSkippedWhenUnaffordable(): void
    {
        $line = $this->line($this->inputs(['outlook' => $this->outlook(100_000)]), 'testing');

        $this->assertSame('Skip testing at Silverstone', $line['answer']);
    }

    public function testUnknownTestingCost(): void
    {
        $hold = $this->line($this->inputs(['testingCost' => null]), 'testing');
        $survive = $this->line($this->inputs([
            'testingCost' => null,
            'objective' => $this->objective(SeasonObjective::SURVIVE),
        ]), 'testing');

        $this->assertSame('Test at Silverstone — cost unknown', $hold['answer']);
        $this->assertSame('Skip testing at Silverstone', $survive['answer']);
    }

    public function testRiskPassesTheAdvisorNumbersThrough(): void
    {
        $line = $this->line($this->inputs(), 'risk');

        $this->assertSame('Overtake 40 · Defend 25', $line['answer']);
        $this->assertNull($line['anchor']);
    }

    public function testSurviveLowersOvertake(): void
    {
        $in = $this->inputs(['objective' => $this->objective(SeasonObjective::SURVIVE)]);

        $this->assertSame('Overtake 25 · Defend 25', $this->line($in, 'risk')['answer']);
    }

    public function testSurviveOvertakeNeverGoesBelowZero(): void
    {
        $risk = ['overtake' => 10, 'defend' => 5, 'phrase' => '', 'tip' => null, 'distance_tip' => '', 'settings' => []];
        $in = $this->inputs(['objective' => $this->objective(SeasonObjective::SURVIVE), 'risk' => $risk]);

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

    public function testSurviveDropsBoost(): void
    {
        $in = $this->inputs(['objective' => $this->objective(SeasonObjective::SURVIVE)]);

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

    public function testCashCushionReasonUnderHoldAndSurviveSaysTheStartIsUnknown(): void
    {
        $hold = $this->line($this->planned(), 'cash');
        $survive = $this->line($this->planned(o: ['objective' => $this->objective(SeasonObjective::SURVIVE)]), 'cash');

        $this->assertSame('Keeps one race of costs in hand (season start unknown)', $hold['reason']);
        $this->assertSame($hold['reason'], $survive['reason']);
    }

    public function testCashCushionReasonWhenTheStartIsBelowTheCushion(): void
    {
        $line = $this->line($this->planned(['season_start' => 1_000_000]), 'cash');

        $this->assertSame('Keeps one race of costs in hand (above your $1M start)', $line['reason']);
    }

    public function testCashStartOutOfReachNamesTheStart(): void
    {
        $line = $this->line($this->planned(['target_kind' => 'start_out_of_reach', 'season_start' => 12_500_000]), 'cash');

        $this->assertSame('Keeps one race of costs in hand (your $12.5M start is out of reach)', $line['reason']);
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

    public function testCtrAnswersWithThePlanCtr(): void
    {
        $line = $this->line($this->planned(['ctr' => 52, 'safety' => 40]), 'ctr');

        $this->assertSame('CTR 52', $line['answer']);
        $this->assertSame('On 30 now', $line['figure']);
        $this->assertSame(['label' => 'Use 52', 'href' => '/cockpit?cockpit_risk=52'], $line['action']);
        $this->assertSame('#cockpit-wear', $line['anchor']);
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

    public function testCtrSurviveReasonsComeBeforeTheNurseOnes(): void
    {
        $base = ['objective' => $this->objective(SeasonObjective::SURVIVE)];
        $budget = $this->line($this->planned(['ctr' => 20, 'safety' => 35], $base), 'ctr');
        $capped = $this->line($this->planned(['ctr' => 35, 'safety' => 35, 'refused_swap_cost' => 250_000], $base), 'ctr');

        $this->assertSame('Race rated Normal: the highest CTR the budget covers to season end', $budget['reason']);
        $this->assertSame('Race rated Normal: capped at the highest CTR that keeps parts out of the red', $capped['reason']);
    }

    public function testCtrUnderASurvivePlanDoesNotCallTheReplacedPartsStuckInTheRed(): void
    {
        $in = $this->planned(['ctr' => 35, 'safety' => 35], [
            'objective' => $this->objective(SeasonObjective::SURVIVE),
            'alwaysRed' => ['Engine'],
        ]);

        $this->assertSame(
            'Race rated Normal: capped at the highest CTR that keeps parts out of the red',
            $this->line($in, 'ctr')['reason'],
        );
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

    public function testBudgetOptionalSwapsDrawFromTheEnvelope(): void
    {
        $in = $this->planned(['envelope' => 250_000], [
            'objective' => $this->objective(SeasonObjective::PROMOTE),
            'optionalSwaps' => [['part' => 'Gearbox', 'cost' => 200_000], ['part' => 'Brakes', 'cost' => 100_000]],
        ]);

        $line = $this->line($in, 'parts');

        $this->assertSame('Swap Gearbox (optional)', $line['answer']);
        $this->assertSame('In the red zone, and the budget covers it', $line['reason']);
        $this->assertSame('$200k', $line['figure']);
    }

    public function testBudgetTrainingIsCoveredAndSurviveKeepsNoRepairReserve(): void
    {
        $in = $this->planned(['envelope' => 100_000], [
            'objective' => $this->objective(SeasonObjective::SURVIVE),
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

    public function testDiscretionaryOptionalSwapsComeBeforeTraining(): void
    {
        $in = $this->planned(['envelope' => 250_000], [
            'objective' => $this->objective(SeasonObjective::PROMOTE),
            'optionalSwaps' => [['part' => 'Gearbox', 'cost' => 200_000]],
        ]);

        $training = $this->line($in, 'training');

        $this->assertSame('Skip Talent course training', $training['answer']);
        $this->assertSame('Only $50k left in the budget', $training['reason']);
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

    public function testAPushSwapOfferedAgainAsOptionalIsNotChargedTwice(): void
    {
        $replaced = [['part' => 'Engine', 'cost' => 100_000]];
        $in = $this->planned(
            ['mode' => 'push', 'ctr' => 100, 'safety' => 81, 'swaps' => $replaced, 'envelope' => 750_000,
             'rows' => $this->rowsReplacing($replaced)],
            [
                'objective' => $this->objective(SeasonObjective::PROMOTE),
                'optionalSwaps' => [['part' => 'Engine', 'cost' => 100_000]],
                'trainingPick' => ['name' => 'Talent course', 'score' => 1.0, 'cost' => 700_000, 'gains' => []],
            ],
        );

        $training = $this->line($in, 'training');

        $this->assertSame('Train Talent course', $training['answer']);
        $this->assertSame('The budget covers it', $training['reason']);
    }

    public function testSurvivePlanRedZoneSwapIsNotCalledABreak(): void
    {
        $replaced = [['part' => 'Engine', 'cost' => 100_000]];
        $in = $this->planned(['ctr' => 12, 'safety' => 12, 'rows' => $this->rowsReplacing($replaced)], [
            'objective' => $this->objective(SeasonObjective::SURVIVE),
            'alwaysRed' => ['Engine'],
            'redZoneSwaps' => ['Engine'],
        ]);

        $parts = $this->line($in, 'parts');
        $ctr = $this->line($in, 'ctr');

        $this->assertSame('Swap Engine', $parts['answer']);
        $this->assertSame('Would finish in the red if kept', $parts['reason']);
        $this->assertStringNotContainsString('stays in the red', $ctr['reason']);
    }

    public function testAPlanSwapMixingBreaksAndRedZoneNamesEach(): void
    {
        $replaced = [['part' => 'Engine', 'cost' => 100_000], ['part' => 'Gearbox', 'cost' => 50_000]];
        $in = $this->planned(['ctr' => 12, 'safety' => 12, 'rows' => $this->rowsReplacing($replaced)], [
            'objective' => $this->objective(SeasonObjective::SURVIVE),
            'redZoneSwaps' => ['Gearbox'],
        ]);

        $this->assertSame(
            'Engine would break this race if kept; Gearbox would finish in the red',
            $this->line($in, 'parts')['reason'],
        );
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
        $this->assertSame('$800k', $line['figure']);
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

    public function testStaffNudgeForHoldNamesTheMiddleThird(): void
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

    public function testUpgradesComeAfterTestingAndBeforeRisk(): void
    {
        $staff = ['rank' => 27, 'of' => 30, 'own' => 41, 'band' => 'top', 'band_average' => 63, 'nudge' => true];
        $in = $this->withUpgrades([$this->upgrade()], ['staffStanding' => $staff]);

        $this->assertSame(
            ['objective', 'cash', 'ctr', 'parts', 'training', 'testing', 'upgrades', 'staff', 'risk', 'boost'],
            array_column(RaceEngineer::brief($in), 'key'),
        );
    }

    public function testNoLineClaimsAGain(): void
    {
        foreach ([SeasonObjective::PROMOTE, SeasonObjective::HOLD, SeasonObjective::SURVIVE] as $objective) {
            $in = $this->inputs([
                'objective' => $this->objective($objective),
                'pushAdvice' => CarWearPlannerService::ADVICE_PUSH,
                'wearAdvice' => $this->wearAdvice(),
                'forcedSwapCost' => 410_000,
                'optionalSwaps' => [['part' => 'Gearbox', 'cost' => 1]],
                'currentCtr' => 60,
            ]);

            foreach (RaceEngineer::brief($in) as $line) {
                $text = $line['answer'] . ' ' . $line['reason'];
                $this->assertDoesNotMatchRegularExpression('/gain|\+\s*\d*\s*places?/i', $text, $line['key']);
            }
        }
    }
}
