<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\GroupStanding;
use App\Engineer\SeasonObjective;
use App\Engineer\StrengthFloor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GroupStanding::class)]
final class GroupStandingTest extends TestCase
{
    /**
     * @param list<int|string> $oas
     * @return array{managers: list<array<string, mixed>>}
     */
    private static function staff(array $oas, int $ownIndex): array
    {
        $managers = [];
        foreach ($oas as $i => $oa) {
            $managers[] = ['name' => 'M' . $i, 'IDM' => 1000 + $i, 'staffOA' => (string) $oa];
        }
        $managers[$ownIndex]['IDM'] = 7;

        return ['managers' => $managers];
    }

    /**
     * 40 managers with OA 100 down to 61; the one at 0-based $rank - 1 is the manager's own.
     *
     * @return array{managers: list<array<string, mixed>>}
     */
    private static function group(int $rank): array
    {
        return self::staff(range(100, 61), $rank - 1);
    }

    public function testOwnRowFoundByIdm(): void
    {
        $standing = GroupStanding::staff(self::staff([60, 80, 70], 1), ['IDM' => '7'], SeasonObjective::PROMOTE);

        $this->assertNotNull($standing);
        $this->assertSame(1, $standing['rank']);
        $this->assertSame(3, $standing['of']);
        $this->assertSame(80, $standing['own']);
    }

    public function testMissingOwnRowGivesNull(): void
    {
        $this->assertNull(GroupStanding::staff(self::staff([60, 80], 0), ['IDM' => 99], SeasonObjective::PROMOTE));
        $this->assertNull(GroupStanding::staff(self::staff([60, 80], 0), [], SeasonObjective::PROMOTE));
        $this->assertNull(GroupStanding::staff([], ['IDM' => 7], SeasonObjective::PROMOTE));
    }

    public function testTiesShareTheBetterRank(): void
    {
        $standing = GroupStanding::staff(self::staff([50, 50, 40], 1), ['IDM' => 7], SeasonObjective::PROMOTE);

        $this->assertSame(1, $standing['rank'] ?? null);
    }

    public function testPromoteNudgesOutsideTheTopThird(): void
    {
        $standing = GroupStanding::staff(self::group(20), ['IDM' => 7], SeasonObjective::PROMOTE);

        $this->assertNotNull($standing);
        $this->assertSame(20, $standing['rank']);
        $this->assertSame(40, $standing['of']);
        $this->assertSame(81, $standing['own']);
        $this->assertSame('top', $standing['band']);
        $this->assertTrue($standing['nudge']);
        $this->assertSame(94, $standing['band_average']); // mean of 100..87 = 93.5
    }

    public function testPromoteInsideTheTopThirdDoesNotNudge(): void
    {
        $standing = GroupStanding::staff(self::group(14), ['IDM' => 7], SeasonObjective::PROMOTE);

        $this->assertNotNull($standing);
        $this->assertSame(14, $standing['rank']);
        $this->assertFalse($standing['nudge']);
    }

    public function testStayNudgesOnlyInTheBottomThird(): void
    {
        $at26 = GroupStanding::staff(self::group(26), ['IDM' => 7], SeasonObjective::STAY);
        $at27 = GroupStanding::staff(self::group(27), ['IDM' => 7], SeasonObjective::STAY);

        $this->assertNotNull($at26);
        $this->assertNotNull($at27);
        $this->assertSame('middle', $at26['band']);
        $this->assertFalse($at26['nudge']);
        $this->assertTrue($at27['nudge']);
        $this->assertSame(81, $at27['band_average']); // positions 15..26 = 86..75, mean 80.5
    }

    public function testStayWithAnEmptyMiddleFallsBackToTheTop(): void
    {
        // of 4: third 2, middle slice is positions 3..2, empty
        $standing = GroupStanding::staff(self::staff([90, 80, 70, 60], 3), ['IDM' => 7], SeasonObjective::STAY);

        $this->assertNotNull($standing);
        $this->assertSame(85, $standing['band_average']);
        $this->assertSame('top', $standing['band']);
    }

    public function testAGroupOfFewerThanThreeRatedManagersMakesNoClaim(): void
    {
        foreach ([SeasonObjective::PROMOTE, SeasonObjective::STAY] as $objective) {
            $this->assertNull(GroupStanding::staff(self::staff([50], 0), ['IDM' => 7], $objective));
            $this->assertNull(GroupStanding::staff(self::staff([90, 50], 1), ['IDM' => 7], $objective));

            $group = self::staff([90, 50], 1);
            $group['managers'][] = ['name' => 'Blank', 'IDM' => 55, 'staffOA' => ''];
            $this->assertNull(GroupStanding::staff($group, ['IDM' => 7], $objective));
        }

        $this->assertNotNull(GroupStanding::staff(self::staff([90, 70, 50], 2), ['IDM' => 7], SeasonObjective::STAY));
    }

    public function testANonScalarIdmNeverMatches(): void
    {
        $group = self::staff([60, 80, 70], 1);
        $group['managers'][1]['IDM'] = [7];

        $this->assertNull(GroupStanding::staff($group, ['IDM' => [7]], SeasonObjective::PROMOTE));
        $this->assertNull(GroupStanding::staff(self::staff([60, 80, 70], 1), ['IDM' => [7]], SeasonObjective::PROMOTE));

        $money = ['managers' => [['IDM' => [7], 'carLevel' => 6, 'carLevelRank' => 2]]];
        $this->assertNull(GroupStanding::car($money, ['IDM' => [7]]));
    }

    public function testStayAtTheBottomOfALargeGroupNudges(): void
    {
        $standing = GroupStanding::staff(self::group(40), ['IDM' => 7], SeasonObjective::STAY);

        $this->assertNotNull($standing);
        $this->assertSame(40, $standing['rank']);
        $this->assertTrue($standing['nudge']);
    }

    public function testNonNumericStaffOaIsIgnored(): void
    {
        $group = self::staff([60, 80, 70], 0);
        $group['managers'][] = ['name' => 'Blank', 'IDM' => 55, 'staffOA' => ''];
        $group['managers'][] = ['name' => 'Missing', 'IDM' => 56];

        $standing = GroupStanding::staff($group, ['IDM' => 7], SeasonObjective::PROMOTE);

        $this->assertNotNull($standing);
        $this->assertSame(3, $standing['of']);
        $this->assertSame(3, $standing['rank']);

        $group['managers'][0]['staffOA'] = 'n/a';
        $this->assertNull(GroupStanding::staff($group, ['IDM' => 7], SeasonObjective::PROMOTE));
    }

    public function testCarStandingFromMoneyLevels(): void
    {
        $managers = [];
        for ($i = 0; $i < 40; $i++) {
            $managers[] = ['IDM' => 1000 + $i, 'cash' => 0, 'carLevelRank' => $i, 'carLevel' => 0];
        }
        $managers[26] = ['IDM' => 7, 'cash' => 500, 'carLevelRank' => 26, 'carLevel' => 5];

        $this->assertSame(
            ['level' => 6, 'rank' => 27, 'of' => 40],
            GroupStanding::car(['managers' => $managers], ['IDM' => 7]),
        );
    }

    /**
     * @return array{managers: list<array<string, mixed>>}
     */
    private static function moneyLevels(int $carLevel, int $carLevelRank): array
    {
        $managers = [['IDM' => 7, 'carLevel' => $carLevel, 'carLevelRank' => $carLevelRank]];
        for ($i = 1; $i < 37; $i++) {
            $managers[] = ['IDM' => 1000 + $i, 'carLevel' => 1, 'carLevelRank' => $i];
        }

        return ['managers' => $managers];
    }

    public function testCarRankZeroIsFirst(): void
    {
        $car = GroupStanding::car(self::moneyLevels(7, 0), ['IDM' => 7]);

        $this->assertSame(['level' => 8, 'rank' => 1, 'of' => 37], $car);
        $this->assertTrue(StrengthFloor::fromGroup($car)->topThree());
    }

    public function testCarRankThreeIsFourthAndNotTopThree(): void
    {
        $car = GroupStanding::car(self::moneyLevels(7, 3), ['IDM' => 7]);

        $this->assertNotNull($car);
        $this->assertSame(4, $car['rank']);
        $this->assertFalse(StrengthFloor::fromGroup($car)->topThree());
    }

    public function testLevelIsOneBased(): void
    {
        $this->assertSame(1, GroupStanding::level(0));
        $this->assertSame(6, GroupStanding::level('5'));
        $this->assertNull(GroupStanding::level(null));
        $this->assertNull(GroupStanding::level('x'));
    }

    public function testCarStandingNeedsAnOwnNumericRow(): void
    {
        $money = ['managers' => [['IDM' => 7, 'carLevel' => 6], ['IDM' => 8, 'carLevel' => 5, 'carLevelRank' => 2]]];

        $this->assertNull(GroupStanding::car($money, ['IDM' => 7]));
        $this->assertNull(GroupStanding::car($money, ['IDM' => 99]));
        $this->assertNull(GroupStanding::car([], ['IDM' => 7]));
    }
}
