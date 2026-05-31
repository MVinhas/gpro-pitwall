<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\TestingTargetsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TestingTargetsService::class)]
final class TestingTargetsServiceTest extends TestCase
{
    private TestingTargetsService $svc;

    protected function setUp(): void
    {
        $this->svc = new TestingTargetsService();
    }

    /**
     * @return array{events: list<array<string, mixed>>, nextSeasonPublished: int, nextSeasonEvents: list<array<string, mixed>>}
     */
    private function calendar(bool $nextPublished = true): array
    {
        // Build a 17-race current season with trackId = 100+raceIdx, and a
        // next season with trackId = 200+raceIdx. Inject staff-day events
        // to confirm the eventType=R filter works.
        $current = [];
        for ($i = 1; $i <= 17; $i++) {
            $current[] = ['eventType' => 'SD', 'idx' => (string) ($i * 10), 'trackId' => '1'];
            $current[] = ['eventType' => 'R', 'idx' => (string) $i, 'trackId' => (string) (100 + $i)];
        }
        $next = [];
        for ($i = 1; $i <= 17; $i++) {
            $next[] = ['eventType' => 'R', 'idx' => (string) $i, 'trackId' => (string) (200 + $i)];
        }
        return [
            'events'              => $current,
            'nextSeasonPublished' => $nextPublished ? 1 : 0,
            'nextSeasonEvents'    => $nextPublished ? $next : [],
        ];
    }

    /**
     * @return array{tracks: list<array<string, mixed>>}
     */
    private function tracks(): array
    {
        $rows = [];
        for ($i = 1; $i <= 17; $i++) {
            // current-season tracks: 100+i, PHA = (i, i+1, i+2)
            $rows[] = ['id' => 100 + $i, 'name' => "Cur{$i}", 'power' => $i, 'handl' => $i + 1, 'accel' => $i + 2];
            // next-season tracks: 200+i, PHA = (i+10, i+11, i+12)
            $rows[] = ['id' => 200 + $i, 'name' => "Nxt{$i}", 'power' => $i + 10, 'handl' => $i + 11, 'accel' => $i + 12];
        }
        return ['tracks' => $rows];
    }

    public function testTargetsForRaceOneAreAllCurrentSeason(): void
    {
        $out = $this->svc->targetsFor(1, $this->calendar(), $this->tracks());
        $offsets = array_column($out, 'offset');
        $races   = array_column($out, 'race');
        $seasons = array_column($out, 'season');
        $this->assertSame([3, 4, 5], $offsets);
        $this->assertSame([4, 5, 6], $races);
        $this->assertSame(['current', 'current', 'current'], $seasons);
        $this->assertSame('Cur4', $out[0]['track_name']);
        $this->assertSame(4, $out[0]['power']);
    }

    public function testTargetsForRaceThirteenWrapToNextSeasonRaceOne(): void
    {
        $out = $this->svc->targetsFor(13, $this->calendar(), $this->tracks());
        $this->assertSame([16, 17, 1], array_column($out, 'race'));
        $this->assertSame(['current', 'current', 'next'], array_column($out, 'season'));
        $this->assertSame('Nxt1', $out[2]['track_name']);
    }

    public function testTargetsForRaceFourteenSpanOneCurrentTwoNext(): void
    {
        $out = $this->svc->targetsFor(14, $this->calendar(), $this->tracks());
        $this->assertSame([17, 1, 2], array_column($out, 'race'));
        $this->assertSame(['current', 'next', 'next'], array_column($out, 'season'));
    }

    public function testTargetsForRaceFifteenOnwardAreAllNextSeason(): void
    {
        foreach ([15, 16, 17] as $cur) {
            $out = $this->svc->targetsFor($cur, $this->calendar(), $this->tracks());
            $seasons = array_column($out, 'season');
            $this->assertSame(['next', 'next', 'next'], $seasons, "currentRace={$cur}");
        }
    }

    public function testNextSeasonTargetsBlankWhenNotPublished(): void
    {
        $out = $this->svc->targetsFor(15, $this->calendar(nextPublished: false), $this->tracks());
        // All 3 targets fall into next season but it isn't published →
        // each row still appears (so the cockpit can render "n/a") but
        // track_id/PHA are null.
        foreach ($out as $row) {
            $this->assertSame('next', $row['season']);
            $this->assertNull($row['track_id']);
            $this->assertNull($row['power']);
            $this->assertNull($row['track_name']);
        }
    }

    public function testFiltersOutNonRaceEvents(): void
    {
        // calendar() injects SD entries with idx 10..170 and trackId 1; if those
        // leaked through, targets for early races would point at trackId 1 with
        // unexpected PHA. Verify the trackId lookup matches the race trackId.
        $out = $this->svc->targetsFor(1, $this->calendar(), $this->tracks());
        $this->assertSame(104, $out[0]['track_id']);
    }
}
