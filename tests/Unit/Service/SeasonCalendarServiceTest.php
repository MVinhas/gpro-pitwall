<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\SeasonCalendarService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SeasonCalendarService::class)]
final class SeasonCalendarServiceTest extends TestCase
{
    private SeasonCalendarService $service;

    protected function setUp(): void
    {
        $this->service = new SeasonCalendarService();
    }

    /** @return array<string, mixed> */
    private function calendar(int $races = 17): array
    {
        $events = [];
        foreach (range(1, $races) as $idx) {
            $events[] = ['eventType' => 'R', 'idx' => $idx, 'trackId' => 100 + $idx];
        }

        return ['events' => $events];
    }

    /** @return array<string, mixed> */
    private function tracks(int $races = 17): array
    {
        $tracks = [];
        foreach (range(1, $races) as $idx) {
            $tracks[] = [
                'id' => 100 + $idx,
                'name' => "Track {$idx}",
                'power' => $idx,
                'handl' => $idx + 1,
                'accel' => $idx + 2,
            ];
        }

        return ['tracks' => $tracks];
    }

    public function testTheWholeSeasonIsReturnedOneRowPerRound(): void
    {
        $rows = $this->service->season($this->calendar(), $this->tracks());

        $this->assertCount(17, $rows);
        $this->assertSame(range(1, 17), array_column($rows, 'race'));
    }

    public function testEachRowCarriesTheTrackNameAndItsPha(): void
    {
        $rows = $this->service->season($this->calendar(), $this->tracks());

        $this->assertSame('Track 1', $rows[0]['track_name']);
        $this->assertSame(1, $rows[0]['power']);
        $this->assertSame(2, $rows[0]['handling']);
        $this->assertSame(3, $rows[0]['acceleration']);
    }

    public function testRoundsAreOrderedByRaceNumberRegardlessOfPayloadOrder(): void
    {
        $calendar = ['events' => [
            ['eventType' => 'R', 'idx' => 3, 'trackId' => 103],
            ['eventType' => 'R', 'idx' => 1, 'trackId' => 101],
            ['eventType' => 'R', 'idx' => 2, 'trackId' => 102],
        ]];

        $rows = $this->service->season($calendar, $this->tracks(3));

        $this->assertSame([1, 2, 3], array_column($rows, 'race'));
    }

    /** Qualifying and practice events share the calendar; only races are rounds. */
    public function testNonRaceEventsAreExcluded(): void
    {
        $calendar = ['events' => [
            ['eventType' => 'R', 'idx' => 1, 'trackId' => 101],
            ['eventType' => 'Q', 'idx' => 1, 'trackId' => 101],
            ['eventType' => 'P', 'idx' => 1, 'trackId' => 101],
        ]];

        $rows = $this->service->season($calendar, $this->tracks(1));

        $this->assertCount(1, $rows);
    }

    public function testAnEmptyCalendarYieldsNoRows(): void
    {
        $this->assertSame([], $this->service->season([], $this->tracks()));
    }

    public function testARoundWhoseTrackIsUnknownStillAppearsWithNullPha(): void
    {
        $calendar = ['events' => [
            ['eventType' => 'R', 'idx' => 1, 'trackId' => 999],
        ]];

        $rows = $this->service->season($calendar, $this->tracks(3));

        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['race']);
        $this->assertNull($rows[0]['track_name']);
        $this->assertNull($rows[0]['power']);
    }

    public function testMalformedEventsAreSkipped(): void
    {
        $calendar = ['events' => [
            ['eventType' => 'R', 'idx' => 0, 'trackId' => 101],
            ['eventType' => 'R', 'idx' => 1, 'trackId' => 0],
            ['eventType' => 'R', 'idx' => 2, 'trackId' => 102],
        ]];

        $rows = $this->service->season($calendar, $this->tracks(3));

        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['race']);
    }

    public function testTheCurrentRaceIsFlaggedSoTheTableCanHighlightIt(): void
    {
        $rows = $this->service->season($this->calendar(), $this->tracks(), currentRace: 5);

        $this->assertTrue($rows[4]['is_current']);
        $this->assertSame(5, $rows[4]['race']);

        $flagged = array_filter($rows, static fn(array $r): bool => $r['is_current']);
        $this->assertCount(1, $flagged);
    }

    public function testNoRoundIsFlaggedWhenTheCurrentRaceIsUnknown(): void
    {
        $rows = $this->service->season($this->calendar(), $this->tracks());

        $this->assertSame([], array_filter($rows, static fn(array $r): bool => $r['is_current']));
    }

    public function testDuplicateRoundEntriesCollapseToOneRow(): void
    {
        $calendar = ['events' => [
            ['eventType' => 'R', 'idx' => 1, 'trackId' => 101],
            ['eventType' => 'R', 'idx' => 1, 'trackId' => 102],
        ]];

        $rows = $this->service->season($calendar, $this->tracks(3));

        $this->assertCount(1, $rows);
    }

    public function testAbsentPhaValuesReadAsZeroRatherThanBreakingTheRow(): void
    {
        $calendar = ['events' => [['eventType' => 'R', 'idx' => 1, 'trackId' => 101]]];
        $tracks = ['tracks' => [['id' => 101, 'name' => 'Sparse']]];

        $rows = $this->service->season($calendar, $tracks);

        $this->assertSame('Sparse', $rows[0]['track_name']);
        $this->assertSame(0, $rows[0]['power']);
        $this->assertSame(0, $rows[0]['handling']);
        $this->assertSame(0, $rows[0]['acceleration']);
    }
}
