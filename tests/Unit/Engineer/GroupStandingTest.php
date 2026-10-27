<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\GroupStanding;
use App\Engineer\SeasonObjective;
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

    public function testHoldNudgesOnlyInTheBottomThird(): void
    {
        $at26 = GroupStanding::staff(self::group(26), ['IDM' => 7], SeasonObjective::HOLD);
        $at27 = GroupStanding::staff(self::group(27), ['IDM' => 7], SeasonObjective::HOLD);

        $this->assertNotNull($at26);
        $this->assertNotNull($at27);
        $this->assertSame('middle', $at26['band']);
        $this->assertFalse($at26['nudge']);
        $this->assertTrue($at27['nudge']);
        $this->assertSame(81, $at27['band_average']); // positions 15..26 = 86..75, mean 80.5
    }

    public function testHoldWithAnEmptyMiddleFallsBackToTheTop(): void
    {
        // of 2: third 1, middle slice is positions 2..1, empty
        $standing = GroupStanding::staff(self::staff([90, 70], 1), ['IDM' => 7], SeasonObjective::HOLD);

        $this->assertNotNull($standing);
        $this->assertSame(90, $standing['band_average']);
    }

    public function testSurviveNeverNudges(): void
    {
        $standing = GroupStanding::staff(self::group(40), ['IDM' => 7], SeasonObjective::SURVIVE);

        $this->assertNotNull($standing);
        $this->assertSame(40, $standing['rank']);
        $this->assertFalse($standing['nudge']);
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
            $managers[] = ['IDM' => 1000 + $i, 'cash' => 0, 'carLevelRank' => $i + 1, 'carLevel' => 1];
        }
        $managers[26] = ['IDM' => 7, 'cash' => 500, 'carLevelRank' => 27, 'carLevel' => 6];

        $this->assertSame(
            ['level' => 6, 'rank' => 27, 'of' => 40],
            GroupStanding::car(['managers' => $managers], ['IDM' => 7]),
        );
    }

    public function testCarStandingNeedsAnOwnNumericRow(): void
    {
        $money = ['managers' => [['IDM' => 7, 'carLevel' => 6], ['IDM' => 8, 'carLevel' => 5, 'carLevelRank' => 2]]];

        $this->assertNull(GroupStanding::car($money, ['IDM' => 7]));
        $this->assertNull(GroupStanding::car($money, ['IDM' => 99]));
        $this->assertNull(GroupStanding::car([], ['IDM' => 7]));
    }
}
