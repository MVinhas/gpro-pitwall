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
}
