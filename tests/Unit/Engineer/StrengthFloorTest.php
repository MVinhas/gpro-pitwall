<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\StrengthFloor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StrengthFloor::class)]
final class StrengthFloorTest extends TestCase
{
    private static function floor(int $rank, int $of): StrengthFloor
    {
        return StrengthFloor::fromGroup(['level' => 5, 'rank' => $rank, 'of' => $of]);
    }

    public function testTopThreeReadsTheReportedRank(): void
    {
        self::assertTrue(self::floor(3, 37)->topThree());
        self::assertFalse(self::floor(4, 37)->topThree());
        self::assertFalse(StrengthFloor::fromGroup(null)->topThree());
    }

    public function testTopQuarterCarriesATenPercentBuffer(): void
    {
        self::assertTrue(self::floor(6, 40)->keepsTopQuarter());
        self::assertFalse(self::floor(7, 40)->keepsTopQuarter());
        self::assertTrue(self::floor(5, 37)->keepsTopQuarter());
        self::assertFalse(self::floor(6, 37)->keepsTopQuarter());
    }

    public function testOutOfTheBottomQuarterCarriesTheSameBuffer(): void
    {
        self::assertTrue(self::floor(26, 40)->keepsOutOfBottomQuarter());
        self::assertFalse(self::floor(27, 40)->keepsOutOfBottomQuarter());
        self::assertTrue(self::floor(23, 37)->keepsOutOfBottomQuarter());
        self::assertFalse(self::floor(24, 37)->keepsOutOfBottomQuarter());
    }

    public function testOutOfTheBottomQuarterNowHasNoBuffer(): void
    {
        self::assertTrue(self::floor(30, 40)->outOfBottomQuarter());
        self::assertFalse(self::floor(31, 40)->outOfBottomQuarter());
        self::assertTrue(self::floor(27, 37)->outOfBottomQuarter());
        self::assertFalse(self::floor(28, 37)->outOfBottomQuarter());
        self::assertFalse(StrengthFloor::fromGroup(null)->outOfBottomQuarter());
    }

    public function testNoRankRefusesEveryFloor(): void
    {
        $floor = StrengthFloor::fromGroup(null);

        self::assertFalse($floor->topThree());
        self::assertFalse($floor->keepsTopQuarter());
        self::assertFalse($floor->keepsOutOfBottomQuarter());
    }
}
