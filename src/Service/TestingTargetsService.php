<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Resolves "the next three target races for a testing session run today"
 * — the +3 / +4 / +5 races whose track PHA is what the test points will
 * land on (testing decays over 3 race weekends).
 *
 * Wraps across seasons when the test would land beyond the current
 * season's 17-race calendar:
 *   race 1..12 → all targets in current season
 *   race 13    → 16, 17, next-season 1
 *   race 14    → 17, next-season 1, 2
 *   race 15..17 → next-season 1, 2, 3
 *
 * Next-season targets are skipped silently when the next-season
 * calendar isn't yet published (`nextSeasonPublished == 0`).
 */
final class TestingTargetsService
{
    public const int RACES_PER_SEASON = 17;

    /** Test points land in the car after the 3rd race following the session. */
    private const array LAND_OFFSETS = [3, 4, 5];

    /**
     * @param int $currentRace 1..17
     * @param array<string, mixed> $calendar GetCalendar payload
     * @param array<string, mixed> $allTracks GetAllTracksPreview payload
     * @return list<array{
     *   offset: int,
     *   season: string,
     *   race: int,
     *   track_id: ?int,
     *   track_name: ?string,
     *   power: ?int,
     *   handling: ?int,
     *   acceleration: ?int,
     * }>
     */
    public function targetsFor(int $currentRace, array $calendar, array $allTracks): array
    {
        $thisSeasonRaces = $this->raceIndex($calendar['events'] ?? []);
        $nextSeasonRaces = ((int) ($calendar['nextSeasonPublished'] ?? 0) === 1)
            ? $this->raceIndex($calendar['nextSeasonEvents'] ?? [])
            : [];
        $trackPha = $this->trackPhaIndex($allTracks['tracks'] ?? []);

        $out = [];
        foreach (self::LAND_OFFSETS as $offset) {
            $desired = $currentRace + $offset;
            if ($desired <= self::RACES_PER_SEASON) {
                $seasonLabel = 'current';
                $raceIdx     = $desired;
                $trackId     = $thisSeasonRaces[$raceIdx] ?? null;
            } else {
                $seasonLabel = 'next';
                $raceIdx     = $desired - self::RACES_PER_SEASON;
                $trackId     = $nextSeasonRaces[$raceIdx] ?? null;
            }

            $pha = $trackId !== null ? ($trackPha[$trackId] ?? null) : null;

            $out[] = [
                'offset'       => $offset,
                'season'       => $seasonLabel,
                'race'         => $raceIdx,
                'track_id'     => $trackId,
                'track_name'   => $pha['name'] ?? null,
                'power'        => $pha['power'] ?? null,
                'handling'     => $pha['handling'] ?? null,
                'acceleration' => $pha['acceleration'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return array<int, int> race-index → trackId
     */
    private function raceIndex(array $events): array
    {
        $out = [];
        foreach ($events as $event) {
            if (($event['eventType'] ?? '') !== 'R') {
                continue;
            }
            $idx = (int) ($event['idx'] ?? 0);
            $tid = (int) ($event['trackId'] ?? 0);
            if ($idx > 0 && $tid > 0) {
                $out[$idx] = $tid;
            }
        }
        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $tracks
     * @return array<int, array{name: string, power: int, handling: int, acceleration: int}>
     */
    private function trackPhaIndex(array $tracks): array
    {
        $out = [];
        foreach ($tracks as $t) {
            $id = (int) ($t['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $out[$id] = [
                'name'         => (string) ($t['name'] ?? ''),
                'power'        => (int) ($t['power'] ?? 0),
                'handling'     => (int) ($t['handl'] ?? 0),
                'acceleration' => (int) ($t['accel'] ?? 0),
            ];
        }
        return $out;
    }
}
