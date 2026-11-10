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
}
