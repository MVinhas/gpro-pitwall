<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\PageController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PageController::class)]
final class PageControllerTest extends TestCase
{
    private const TRACKS = ['Buenos Aires', 'Monte Carlo', 'Monza'];

    public function testDefaultsToTheNextRaceTrackWhenKnown(): void
    {
        $this->assertSame(
            'Monte Carlo',
            PageController::resolveDefaultTrack(self::TRACKS, 'Monte Carlo'),
        );
    }

    public function testFallsBackToFirstTrackWhenNoNextRaceCached(): void
    {
        // Pre-first-sync: no Office data, so trackName is empty.
        $this->assertSame(
            'Buenos Aires',
            PageController::resolveDefaultTrack(self::TRACKS, ''),
        );
    }

    public function testFallsBackToFirstTrackWhenNextRaceIsUnknown(): void
    {
        // Defensive: a trackName that isn't in our config must not leak through.
        $this->assertSame(
            'Buenos Aires',
            PageController::resolveDefaultTrack(self::TRACKS, 'Nowhere Speedway'),
        );
    }

    public function testEmptyTrackListYieldsEmptyString(): void
    {
        $this->assertSame('', PageController::resolveDefaultTrack([], 'Monte Carlo'));
    }

    public function testRanksCashByValueNotResponseOrder(): void
    {
        // Manager 7 has the 3rd-most cash even though listed last.
        $managers = [
            ['IDM' => 1, 'cash' => 90_000_000],
            ['IDM' => 2, 'cash' => 50_000_000],
            ['IDM' => 7, 'cash' => 60_000_000],
        ];

        $this->assertSame(
            ['rank' => 2, 'total' => 3],
            PageController::rankCashAgainstGroup(7, 60_000_000, $managers),
        );
    }

    public function testTopCashRanksFirst(): void
    {
        $managers = [
            ['IDM' => 7, 'cash' => 90_000_000],
            ['IDM' => 1, 'cash' => 50_000_000],
        ];

        $this->assertSame(
            ['rank' => 1, 'total' => 2],
            PageController::rankCashAgainstGroup(7, 90_000_000, $managers),
        );
    }

    public function testReturnsNullsWhenManagerNotInGroup(): void
    {
        $managers = [
            ['IDM' => 1, 'cash' => 90_000_000],
            ['IDM' => 2, 'cash' => 50_000_000],
        ];

        $this->assertSame(
            ['rank' => null, 'total' => null],
            PageController::rankCashAgainstGroup(99, 10_000_000, $managers),
        );
    }

    public function testReturnsNullsForEmptyGroup(): void
    {
        $this->assertSame(
            ['rank' => null, 'total' => null],
            PageController::rankCashAgainstGroup(1, 10_000_000, []),
        );
    }

    public function testResolvesNextRaceTrackIdByRaceNumber(): void
    {
        $calendar = ['events' => [
            ['eventType' => 'R', 'idx' => 4, 'trackId' => 41], // Magny Cours
            ['eventType' => 'SD', 'idx' => 5, 'trackId' => 1],
            ['eventType' => 'R', 'idx' => 5, 'trackId' => 63], // Poznan
        ]];

        $this->assertSame(63, PageController::nextRaceTrackId($calendar, 5));
    }

    public function testNextRaceTrackIdReturnsZeroWhenRaceNotInCalendar(): void
    {
        $calendar = ['events' => [
            ['eventType' => 'R', 'idx' => 4, 'trackId' => 41],
        ]];

        $this->assertSame(0, PageController::nextRaceTrackId($calendar, 9));
        $this->assertSame(0, PageController::nextRaceTrackId($calendar, 0));
        $this->assertSame(0, PageController::nextRaceTrackId([], 5));
    }

    public function testSetupIsStaleWhenRaceSetupTrackTrailsOffice(): void
    {
        // Office rolled over to race 63; RaceSetup still on the previous 41.
        $this->assertTrue(PageController::isRaceSetupStale(63, 41));
    }

    public function testSetupIsFreshWhenTrackIdsMatch(): void
    {
        // RaceSetup has rolled over: weather is for the current race.
        $this->assertFalse(PageController::isRaceSetupStale(63, 63));
    }

    public function testSetupNotFlaggedStaleWhenAnIdIsUnknown(): void
    {
        // Unknown ids (RaceSetup or Office absent) — can't tell, so don't nag.
        $this->assertFalse(PageController::isRaceSetupStale(0, 41));
        $this->assertFalse(PageController::isRaceSetupStale(63, 0));
        $this->assertFalse(PageController::isRaceSetupStale(0, 0));
    }
}
