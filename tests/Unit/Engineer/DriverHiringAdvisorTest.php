<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\DriverHiringAdvisor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DriverHiringAdvisor::class)]
final class DriverHiringAdvisorTest extends TestCase
{
    /** @param array<string, mixed> $o  @return array<string, mixed> */
    private function row(array $o = []): array
    {
        return $o + ['ID' => 1, 'NAME' => 'A Driver', 'OA' => 100, 'AGE' => 25, 'SAL' => 400_000, 'FEE' => 200_000,
            'OFF' => 0, 'RET' => 0, 'FAV' => [], 'rating' => 80.0];
    }

    public function testCandidatesReadTheMarketKeys(): void
    {
        $c = DriverHiringAdvisor::candidates([$this->row(['ID' => 7, 'FAV' => [3, 9, 9]])], [9, 12]);

        self::assertSame([['id' => 7, 'name' => 'A Driver', 'rating' => 80.0, 'oa' => 100, 'age' => 25,
            'salary' => 400_000, 'fee' => 200_000, 'offers' => 0, 'retiring' => false, 'favourites' => 1]], $c);
    }

    public function testMalformedRowsAreSkippedNotFatal(): void
    {
        $rows = [
            $this->row(['ID' => 0]),
            $this->row(['ID' => 2, 'SAL' => 'n/a']),
            $this->row(['ID' => 3, 'rating' => 49.9]),
            ['ID' => 4, 'NAME' => 'Bare', 'OA' => 90, 'AGE' => 22, 'SAL' => 300_000, 'rating' => 70.0],
        ];

        $c = DriverHiringAdvisor::candidates($rows, []);

        self::assertSame([4], array_column($c, 'id'));
        self::assertSame([0, 0, 0], [$c[0]['fee'], $c[0]['offers'], $c[0]['favourites']]);
    }

    public function testRedLinesApplyInOrderAndCountTheFirstMatch(): void
    {
        $c = DriverHiringAdvisor::candidates([
            $this->row(['ID' => 1, 'RET' => 1, 'AGE' => 40]),
            $this->row(['ID' => 2, 'OA' => 111]),
            $this->row(['ID' => 3, 'AGE' => 35]),
            $this->row(['ID' => 4, 'OFF' => 2]),
            $this->row(['ID' => 5, 'SAL' => 800_001]),
            $this->row(['ID' => 6, 'SAL' => 800_000, 'OA' => 110, 'AGE' => 34]),
        ], []);

        $r = DriverHiringAdvisor::redLines($c, 110, 800_000);

        self::assertSame([6], array_column($r['open'], 'id'));
        self::assertSame(['retiring' => 1, 'cap' => 1, 'age' => 1, 'offers' => 1, 'salary_average' => 1,
            'salary_plan' => 0, 'switch_cost' => 0], $r['out']);
    }

    public function testNoCapAndNoGroupAverageSkipThoseLines(): void
    {
        $c = DriverHiringAdvisor::candidates([$this->row(['OA' => 250, 'SAL' => 9_000_000])], []);

        self::assertCount(1, DriverHiringAdvisor::redLines($c, null, null)['open']);
    }

    public function testRankIsByRatingThenFavouritesInsideTheTieBand(): void
    {
        $c = DriverHiringAdvisor::candidates([
            $this->row(['ID' => 1, 'rating' => 80.0]),
            $this->row(['ID' => 2, 'rating' => 79.2, 'FAV' => [5]]),
            $this->row(['ID' => 3, 'rating' => 78.9, 'FAV' => [5, 6]]),
            $this->row(['ID' => 4, 'rating' => 90.0]),
            $this->row(['ID' => 5, 'rating' => 80.0, 'SAL' => 300_000]),
        ], [5, 6]);

        self::assertSame([4, 2, 5, 1, 3], array_column(DriverHiringAdvisor::rank($c), 'id'));
    }

    public function testTheTieBandIsReadOnRatingsToOneDecimal(): void
    {
        // 64.4 - 63.4 is 1.0000000000000071 in floats: still a tie, so the favourite tracks decide.
        $c = DriverHiringAdvisor::candidates([
            $this->row(['ID' => 1, 'rating' => 64.4]),
            $this->row(['ID' => 2, 'rating' => 63.4, 'FAV' => [5]]),
        ], [5]);

        self::assertSame([2, 1], array_column(DriverHiringAdvisor::rank($c), 'id'));
    }

    /** @return array{rows: list<array{balance: int}>, cushion: int, target: int, envelope: int, spare: int} */
    private function money(int $balance, int $envelope, int $spare, int $races = 10): array
    {
        return ['rows' => array_fill(0, $races, ['balance' => $balance]), 'cushion' => 0, 'target' => 0,
            'envelope' => $envelope, 'spare' => $spare];
    }

    /** @param array<string, mixed> $o */
    private function candidate(array $o = []): array
    {
        return DriverHiringAdvisor::candidates([$this->row($o)], [])[0];
    }

    public function testOneOffIsOfferFeeAndHalfTheOldSalaryForRacesLeft(): void
    {
        $pick = DriverHiringAdvisor::price($this->candidate(), 300_000, 4, null, null, false, 70.0);

        self::assertIsArray($pick);
        self::assertSame(500_000 + 200_000 + 600_000, $pick['one_off']);
        self::assertSame(10.0, $pick['gain']);
        self::assertSame('unknown', $pick['fits']);
    }

    public function testWithoutAPlanOnlyCashGuardsTheOneOff(): void
    {
        self::assertSame('switch_cost', DriverHiringAdvisor::price($this->candidate(), 0, 0, null, 699_999, false, null));
        self::assertIsArray(DriverHiringAdvisor::price($this->candidate(), 0, 0, null, 700_000, false, null));
    }

    public function testDropCountsSalaryEveryRaceAndTheOneOffOnce(): void
    {
        $money = $this->money(5_000_000, 5_000_000, 5_000_000);

        self::assertSame(100_000 * 10 + 700_000, DriverHiringAdvisor::drop($money, 100_000, 700_000));
        self::assertSame(100_000 * 7, DriverHiringAdvisor::drop($money, 100_000, 0, 3));
        self::assertLessThanOrEqual(0, DriverHiringAdvisor::drop($money, -50_000, 0));
    }

    public function testSalaryThePlanCannotCarryIsOutUnderBothObjectives(): void
    {
        $money = $this->money(900_000, 900_000, 900_000);
        $dear = $this->candidate(['SAL' => 400_000]);

        self::assertSame('salary_plan', DriverHiringAdvisor::price($dear, 300_000, 0, $money, null, true, null));
        self::assertSame('salary_plan', DriverHiringAdvisor::price($dear, 300_000, 0, $money, null, false, null));
    }

    public function testPromoteMayCutOtherSpendingForTheOneOffStayMayNot(): void
    {
        $money = $this->money(2_000_000, 2_000_000, 300_000);
        $same = $this->candidate(['SAL' => 300_000]);

        $promote = DriverHiringAdvisor::price($same, 300_000, 0, $money, null, true, null);
        self::assertIsArray($promote);
        self::assertSame('cuts', $promote['fits']);
        self::assertSame(700_000 - 300_000, $promote['cut']);
        self::assertSame('switch_cost', DriverHiringAdvisor::price($same, 300_000, 0, $money, null, false, null));
    }

    public function testACheaperDriverInsideTheSpareFitsThePlan(): void
    {
        $money = $this->money(2_000_000, 2_000_000, 800_000);
        $pick = DriverHiringAdvisor::price($this->candidate(['SAL' => 250_000]), 300_000, 0, $money, null, false, null);

        self::assertIsArray($pick);
        self::assertSame('plan', $pick['fits']);
        self::assertSame(0, $pick['cut']);
    }

    /** @param array<string, mixed> $o  @return array<string, mixed> */
    private function current(array $o = []): array
    {
        return $o + ['name' => 'My Driver', 'rating' => 75.0, 'oa' => 100, 'age' => 27, 'salary' => 330_000,
            'races_left' => 12, 'retiring' => false, 'renew_fee' => null, 'renew_salary' => null,
            'extension_blocked' => false];
    }

    /** @param ?array<string, mixed> $current  @param ?list<array<string, mixed>> $rated  @param array<string, mixed> $o */
    private function advice(?array $current, ?array $rated, array $o = []): array
    {
        $o += ['marketKnown' => true, 'calendar' => [], 'cap' => 110, 'groupAverage' => 800_000, 'money' => null,
            'cash' => 50_000_000, 'promote' => false, 'seasonRacesLeft' => 10];

        return DriverHiringAdvisor::advise($current, $rated, $o['marketKnown'], $o['calendar'], $o['cap'],
            $o['groupAverage'], $o['money'], $o['cash'], $o['promote'], $o['seasonRacesLeft']);
    }

    public function testNoDriverIsHireWithTheTopThree(): void
    {
        $a = $this->advice(null, [
            $this->row(['ID' => 1, 'rating' => 90.0]),
            $this->row(['ID' => 2, 'rating' => 85.0]),
            $this->row(['ID' => 3, 'rating' => 80.0]),
            $this->row(['ID' => 4, 'rating' => 70.0]),
        ]);

        self::assertSame(['hire', 'no_driver'], [$a['verdict'], $a['reason']]);
        self::assertSame([1, 2, 3], array_column($a['shortlist'], 'id'));
        self::assertSame([null, null, null], array_column($a['shortlist'], 'gain'));
        self::assertNull($a['current']);
        self::assertNull($a['extension']);
        self::assertTrue($a['ranked']);
    }

    public function testOverTheCapWithFiveRacesLeftIsLeaving(): void
    {
        $a = $this->advice($this->current(['oa' => 111, 'races_left' => 5]), []);

        self::assertSame(['replace', 'leaving_cap'], [$a['verdict'], $a['reason']]);
        self::assertTrue($a['over_cap']);
    }

    public function testOverTheCapWithSixRacesLeftIsKeptWithTheNote(): void
    {
        $a = $this->advice($this->current(['oa' => 111, 'races_left' => 6]), []);

        self::assertSame(['keep', 'over_cap'], [$a['verdict'], $a['reason']]);
        self::assertTrue($a['over_cap']);
        self::assertNull($a['extension']);
    }

    public function testExactlyOnTheCapCanBeExtended(): void
    {
        $a = $this->advice($this->current(['oa' => 110, 'races_left' => 5]), []);

        self::assertSame(['extend', 'contract'], [$a['verdict'], $a['reason']]);
        self::assertFalse($a['over_cap']);
    }

    public function testRetiringLeavesByTheSeasonEndNotOnlyByContract(): void
    {
        $a = $this->advice($this->current(['retiring' => true, 'races_left' => 12]), [], ['seasonRacesLeft' => 5]);

        self::assertSame(['replace', 'leaving_retiring'], [$a['verdict'], $a['reason']]);
    }

    public function testRetiringLaterIsKept(): void
    {
        $a = $this->advice($this->current(['retiring' => true, 'races_left' => 12]), [], ['seasonRacesLeft' => 6]);

        self::assertSame(['keep', 'retiring_later'], [$a['verdict'], $a['reason']]);
        self::assertNull($a['extension']);
    }

    public function testAGainOfFiveReplacesAGainJustUnderDoesNot(): void
    {
        $better = $this->advice($this->current(), [$this->row(['rating' => 80.0])]);
        $close = $this->advice($this->current(), [$this->row(['rating' => 79.9])]);

        self::assertSame(['replace', 'better'], [$better['verdict'], $better['reason']]);
        self::assertSame(5.0, $better['shortlist'][0]['gain']);
        self::assertSame(['keep', 'none'], [$close['verdict'], $close['reason']]);
        self::assertCount(1, $close['shortlist']);
    }

    public function testTheBestGainInTheShortlistDecidesNotThePicks(): void
    {
        $a = $this->advice($this->current(), [
            $this->row(['ID' => 1, 'rating' => 80.0]),
            $this->row(['ID' => 2, 'rating' => 79.2, 'FAV' => [5]]),
        ], ['calendar' => [5]]);

        self::assertSame(['replace', 'better'], [$a['verdict'], $a['reason']]);
        self::assertSame([2, 1], array_column($a['shortlist'], 'id'));
        self::assertSame(4.2, $a['shortlist'][0]['gain']);
    }

    /** @return list<array<string, mixed>> rated +6.0, 0.0 and -4.4 against current() */
    private function betterSameAndWorse(): array
    {
        return [
            $this->row(['ID' => 1, 'rating' => 81.0]),
            $this->row(['ID' => 2, 'rating' => 75.0]),
            $this->row(['ID' => 3, 'rating' => 70.6]),
        ];
    }

    public function testABetterDriverVerdictListsOnlyBetterDrivers(): void
    {
        $a = $this->advice($this->current(), $this->betterSameAndWorse());

        self::assertSame(['replace', 'better'], [$a['verdict'], $a['reason']]);
        self::assertSame([1], array_column($a['shortlist'], 'id'));
    }

    public function testALeavingDriverGetsTheBestThreeBetterOrNot(): void
    {
        $a = $this->advice($this->current(['oa' => 111, 'races_left' => 5]), $this->betterSameAndWorse());

        self::assertSame(['replace', 'leaving_cap'], [$a['verdict'], $a['reason']]);
        self::assertSame([1, 2, 3], array_column($a['shortlist'], 'id'));
    }

    public function testABetterDriverWinsOverAnExtension(): void
    {
        $a = $this->advice($this->current(['races_left' => 4]), [$this->row(['rating' => 80.0])]);

        self::assertSame(['replace', 'better'], [$a['verdict'], $a['reason']]);
        self::assertFalse($a['urgent']);
    }

    public function testLeavingOverTheCapWinsOverABetterDriver(): void
    {
        $a = $this->advice($this->current(['oa' => 111, 'races_left' => 5]), [$this->row(['rating' => 90.0])]);

        self::assertSame(['replace', 'leaving_cap'], [$a['verdict'], $a['reason']]);
    }

    public function testExtendFromFiveRacesUrgentFromTwo(): void
    {
        $five = $this->advice($this->current(['races_left' => 5]), []);
        $three = $this->advice($this->current(['races_left' => 3]), []);
        $two = $this->advice($this->current(['races_left' => 2]), []);
        $six = $this->advice($this->current(['races_left' => 6]), []);

        self::assertSame(['extend', false], [$five['verdict'], $five['urgent']]);
        self::assertSame(['extend', false], [$three['verdict'], $three['urgent']]);
        self::assertSame(['extend', true], [$two['verdict'], $two['urgent']]);
        self::assertSame(['keep', false], [$six['verdict'], $six['urgent']]);
    }

    public function testExtensionUsesGprosExactPrice(): void
    {
        $a = $this->advice($this->current(['renew_fee' => 673_200, 'renew_salary' => 369_600, 'races_left' => 3]), []);

        self::assertSame(['fee' => 673_200, 'salary' => 369_600, 'exact' => true, 'fits' => true], $a['extension']);
    }

    public function testExtensionFallsBackToTheMultiples(): void
    {
        $a = $this->advice($this->current(), []);

        self::assertNotNull($a['extension']);
        self::assertSame([673_200, 369_600, false], [
            $a['extension']['fee'], $a['extension']['salary'], $a['extension']['exact'],
        ]);
    }

    public function testExtensionOverBudgetReplacesWhenSomeoneIsAffordable(): void
    {
        $current = $this->current(['races_left' => 3, 'renew_fee' => 2_000_000, 'renew_salary' => 400_000]);
        $a = $this->advice($current, [$this->row(['SAL' => 300_000, 'FEE' => 0, 'rating' => 76.0])], [
            'cash' => 1_000_000,
        ]);

        self::assertSame(['replace', 'extension_over_budget'], [$a['verdict'], $a['reason']]);
        self::assertSame(995_000, $a['shortlist'][0]['one_off']);
    }

    public function testExtensionOverBudgetStaysExtendWithNobodyToSign(): void
    {
        $current = $this->current(['races_left' => 3, 'renew_fee' => 2_000_000, 'renew_salary' => 400_000]);
        $a = $this->advice($current, [], ['cash' => 1_000_000]);

        self::assertSame(['extend', 'extension_budget_short'], [$a['verdict'], $a['reason']]);
        self::assertNotNull($a['extension']);
        self::assertFalse($a['extension']['fits']);
    }

    public function testABlockedExtensionIsKeepNotExtend(): void
    {
        $a = $this->advice($this->current(['races_left' => 3, 'extension_blocked' => true]), []);

        self::assertSame(['keep', 'no_extension'], [$a['verdict'], $a['reason']]);
        self::assertNull($a['extension']);
    }

    public function testNoBaselineMeansNoShortlistButContractRulesHold(): void
    {
        $a = $this->advice($this->current(['races_left' => 4]), null);

        self::assertSame('extend', $a['verdict']);
        self::assertFalse($a['ranked']);
        self::assertSame([], $a['shortlist']);
        self::assertSame([0, 0, 0, 0, 0, 0, 0], array_values($a['out']));
        self::assertNull($a['top_out']);
    }

    public function testTopOutNamesTheFilterThatRemovedTheMost(): void
    {
        $a = $this->advice(null, [
            $this->row(['ID' => 1, 'AGE' => 36]),
            $this->row(['ID' => 2, 'AGE' => 36]),
            $this->row(['ID' => 3, 'OFF' => 1]),
        ]);

        self::assertSame([], $a['shortlist']);
        self::assertSame('age', $a['top_out']);
    }

    public function testMoneyRulesCountInOut(): void
    {
        $a = $this->advice($this->current(), [$this->row(['SAL' => 330_000, 'rating' => 90.0])], [
            'money' => $this->money(2_000_000, 2_000_000, 300_000),
        ]);

        self::assertSame([], $a['shortlist']);
        self::assertSame(1, $a['out']['switch_cost']);
        self::assertSame('keep', $a['verdict']);
        self::assertTrue($a['finances_known']);
    }

    public function testMarketUnknownIsCarriedThrough(): void
    {
        $a = $this->advice(null, null, ['marketKnown' => false]);

        self::assertFalse($a['market_known']);
        self::assertFalse($a['finances_known']);
    }
}
