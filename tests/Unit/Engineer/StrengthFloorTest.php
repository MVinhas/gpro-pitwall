<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\StrengthFloor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StrengthFloor::class)]
final class StrengthFloorTest extends TestCase
{
    private static function floor(int $rank, int $of, ?int $relegationPos = null): StrengthFloor
    {
        return StrengthFloor::fromGroup(['level' => 5, 'rank' => $rank, 'of' => $of], $relegationPos);
    }

    public function testTopThreeReadsTheReportedRank(): void
    {
        self::assertTrue(self::floor(3, 37)->topThree());
        self::assertFalse(self::floor(4, 37)->topThree());
        self::assertFalse(StrengthFloor::fromGroup(null, 31)->topThree());
    }

    public function testTopHalfCarriesATenPercentBuffer(): void
    {
        self::assertTrue(self::floor(16, 40)->keepsTopHalf());
        self::assertFalse(self::floor(17, 40)->keepsTopHalf());
        self::assertTrue(self::floor(14, 37)->keepsTopHalf());
        self::assertFalse(self::floor(15, 37)->keepsTopHalf());
    }

    public function testAboveRelegationCarriesTheSameBuffer(): void
    {
        self::assertTrue(self::floor(26, 40, 31)->keepsAboveRelegation());
        self::assertFalse(self::floor(27, 40, 31)->keepsAboveRelegation());
        self::assertFalse(self::floor(1, 40, null)->keepsAboveRelegation());
    }

    public function testNoRankRefusesEveryFloor(): void
    {
        $floor = StrengthFloor::fromGroup(null, 31);

        self::assertFalse($floor->topThree());
        self::assertFalse($floor->keepsTopHalf());
        self::assertFalse($floor->keepsAboveRelegation());
    }
}
