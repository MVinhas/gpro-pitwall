<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\RaceHistoryRepository;
use App\Repository\TrackRepository;
use App\Telemetry\RaceHistoryMapper;
use Throwable;

/**
 * Archives one manager's own race in full, from the payloads the sync has
 * already fetched.
 *
 * Runs alongside RaceTelemetryService on every sync: that one contributes an
 * anonymous row to the shared corpus, this one keeps the detailed record under
 * the manager's own id. Neither can see the other's table.
 *
 * Like telemetry, archiving is observational — a malformed payload must never
 * surface to the manager whose sync happened to carry it.
 */
final readonly class RaceHistoryService
{
    public function __construct(
        private RaceHistoryRepository $repository,
        private RaceHistoryMapper $mapper,
        private TrackRepository $tracks,
    ) {
    }

    /**
     * @param array<string, mixed> $analysis RaceAnalysis payload
     * @param array<string, mixed> $td       TDProfile payload ([] when none)
     * @param array<string, mixed> $staff    StaffAndFacilities payload ([] when none)
     * @param array<string, mixed> $driverProfile DriProfile payload ([] when none)
     * @return bool true when a previously unarchived race was stored (a known
     *              race is refreshed in place and reports false)
     */
    public function archive(
        int $userId,
        array $analysis,
        array $td = [],
        array $staff = [],
        array $driverProfile = [],
    ): bool {
        if ($analysis === []) {
            return false;
        }

        try {
            $row = $this->mapper->map(
                $userId,
                $analysis,
                $td,
                $staff,
                $this->lapLengthFor($analysis),
                $driverProfile,
            );

            if ($row === null) {
                return false;
            }

            $userId = $this->intOf($row['user_id'] ?? null);
            $season = $this->intOf($row['season'] ?? null);
            $race = $this->intOf($row['race'] ?? null);
            $known = $this->repository->exists($userId, $season, $race);
            $this->repository->upsert($row);

            return !$known;
        } catch (Throwable $e) {
            error_log('[RaceHistory] archive failed: ' . $e::class);
            return false;
        }
    }

    private function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Lap length for the raced track, so fuel and tyre burn can be stored per
     * kilometre instead of per lap.
     *
     * Resolved by NAME, never by the payload's track id: `tracks.id` is a local
     * autoincrement from the seed CSV and does not match GPRO's track ids, so
     * an id lookup silently returns a different circuit.
     *
     * @param array<string, mixed> $analysis
     */
    private function lapLengthFor(array $analysis): ?float
    {
        $name = $analysis['trackName'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            return null;
        }

        return $this->tracks->findLapLength(self::normaliseTrackName($name));
    }

    /**
     * RaceAnalysis returns "Brno (Czech Republic)" where the tracks table holds
     * "Brno". Without stripping the country suffix every lookup misses, and the
     * per-km fuel and tyre rates silently come back null for every race.
     */
    public static function normaliseTrackName(string $name): string
    {
        return trim((string) preg_replace('/\s*\([^)]*\)\s*$/', '', trim($name)));
    }
}
