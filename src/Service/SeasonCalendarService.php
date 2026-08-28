<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Builds the season calendar as one row per round, each carrying the track's
 * Power / Handling / Acceleration demand.
 *
 * Deliberately carries no car-vs-track verdict: the manager reads the P/H/A
 * columns and draws their own conclusions. The cockpit PHA card already does
 * the match verdict for the *next* race, and that stays the only place it
 * lives — two implementations of "does my car suit this track" would drift.
 */
final class SeasonCalendarService
{
    /**
     * @param array<string, mixed> $calendar  GetCalendar payload
     * @param array<string, mixed> $allTracks GetAllTracksPreview payload
     * @param int $currentRace 1..17, or 0 when unknown
     * @return list<array{
     *   race: int,
     *   track_id: int,
     *   track_name: ?string,
     *   power: ?int,
     *   handling: ?int,
     *   acceleration: ?int,
     *   is_current: bool,
     * }>
     */
    public function season(array $calendar, array $allTracks, int $currentRace = 0): array
    {
        $rounds = $this->raceIndex($calendar['events'] ?? []);
        $pha = $this->trackPhaIndex($allTracks['tracks'] ?? []);

        ksort($rounds);

        $out = [];
        foreach ($rounds as $race => $trackId) {
            $track = $pha[$trackId] ?? null;

            $out[] = [
                'race'         => $race,
                'track_id'     => $trackId,
                'track_name'   => $track['name'] ?? null,
                'power'        => $track['power'] ?? null,
                'handling'     => $track['handling'] ?? null,
                'acceleration' => $track['acceleration'] ?? null,
                'is_current'   => $race === $currentRace,
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
            if ($idx > 0 && $tid > 0 && !isset($out[$idx])) {
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
