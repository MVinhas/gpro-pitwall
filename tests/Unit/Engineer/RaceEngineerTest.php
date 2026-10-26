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
            'testingCost' => 300_000,
            'risk' => ['overtake' => 40, 'defend' => 25, 'phrase' => 'x', 'tip' => null, 'distance_tip' => '', 'settings' => []],
            'boostLitres' => 24,
            'weatherStale' => false,
        ];

        return new EngineerInputs(...$o);
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

    public function testObjectiveWarningIsAmberAndReplacesTheReason(): void
    {
        $warned = $this->objective(SeasonObjective::HOLD, ['warning' => 'Negative balance relegates']);

        $line = $this->line($this->inputs(['objective' => $warned]), 'objective');

        $this->assertSame('amber', $line['tone']);
        $this->assertSame('Negative balance relegates', $line['reason']);
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

    public function testPromoteOnANormalRaceNeverRaisesCtr(): void
    {
        $line = $this->line($this->inputs(['objective' => $this->objective(SeasonObjective::PROMOTE)]), 'ctr');

        $this->assertSame('CTR 30', $line['answer']);
    }

    public function testHoldNeverRaisesCtr(): void
    {
        $in = $this->inputs(['pushAdvice' => CarWearPlannerService::ADVICE_PUSH]);

        $this->assertSame('CTR 30', $this->line($in, 'ctr')['answer']);
    }

    public function testHoldLowersCtrToTheCeiling(): void
    {
        $line = $this->line($this->inputs(['currentCtr' => 60]), 'ctr');

        $this->assertSame('CTR 45', $line['answer']);
        $this->assertSame('/cockpit?cockpit_risk=45', $line['action']['href'] ?? null);
    }

    public function testNoObjectiveFollowsTheHoldRule(): void
    {
        $in = $this->inputs([
            'objective' => $this->objective(null),
            'pushAdvice' => CarWearPlannerService::ADVICE_PUSH,
        ]);

        $this->assertSame('CTR 30', $this->line($in, 'ctr')['answer']);
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

        $this->assertSame('Skip — keep it for repairs', $line['answer']);
        $this->assertSame('Free cash does not cover it', $line['reason']);
    }

    public function testSurviveSkipsTrainingWhenRepairsNeedTheMoney(): void
    {
        $in = $this->inputs([
            'objective' => $this->objective(SeasonObjective::SURVIVE),
            'outlook' => $this->outlook(400_000),
            'forcedSwapCost' => 410_000,
        ]);

        $this->assertSame('Skip — keep it for repairs', $this->line($in, 'training')['answer']);
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
        $this->assertSame('Skip — keep it for repairs', $short['answer']);
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
        $this->assertSame('Skip — keep it for repairs', $short['answer']);
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

        $this->assertSame('Test at Monza', $enough['answer']);
        $this->assertSame('Skip — keep it for repairs', $short['answer']);
        $this->assertSame('Skip — keep it for repairs', $survive['answer']);
    }

    public function testTestingIsRecommendedWhenAffordable(): void
    {
        $line = $this->line($this->inputs(), 'testing');

        $this->assertSame('Test at Monza', $line['answer']);
        $this->assertSame('$300k', $line['figure']);
        $this->assertSame('#cockpit-testing', $line['anchor']);
    }

    public function testTestingClosedIsNothingToDo(): void
    {
        $this->assertSame('Nothing to do', $this->line($this->inputs(['testingOpen' => false]), 'testing')['answer']);
        $this->assertSame('Nothing to do', $this->line($this->inputs(['testingTarget' => null]), 'testing')['answer']);
    }

    public function testTestingSkippedWhenUnaffordable(): void
    {
        $line = $this->line($this->inputs(['outlook' => $this->outlook(100_000)]), 'testing');

        $this->assertSame('Skip — keep it for repairs', $line['answer']);
    }

    public function testUnknownTestingCost(): void
    {
        $hold = $this->line($this->inputs(['testingCost' => null]), 'testing');
        $survive = $this->line($this->inputs([
            'testingCost' => null,
            'objective' => $this->objective(SeasonObjective::SURVIVE),
        ]), 'testing');

        $this->assertSame('Test — cost unknown', $hold['answer']);
        $this->assertSame('Skip — keep it for repairs', $survive['answer']);
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
