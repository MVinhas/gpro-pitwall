<?php

declare(strict_types=1);

namespace App\Service;

use App\Archive\ArchiveProjector;
use App\Archive\RaceSnapshotReader;
use App\Archive\SupporterGate;
use App\Cache\CacheInterface;
use App\Repository\ApiArchiveRepository;
use App\Sync\Endpoint;
use App\Sync\EndpointFetcher;
use App\Sync\HarvestResult;
use App\Sync\SyncContextFactory;
use App\Sync\SyncPlanner;
use Throwable;
use UnexpectedValueException;

/**
 * One bounded, low-priority slice of the sync planner's phase 2: a few GPRO
 * calls, then a few projections out of the archive. It never touches the office
 * endpoint (the page-critical sync owns that) and plans against the office the
 * last sync archived, so every row it files is stamped with that sync's race.
 */
class HarvestService
{
    public const int MAX_CALLS = 3;
    public const int MAX_PROJECTIONS = 5;

    /** Deadman ceiling, like the sync lock: only matters if a process dies mid-slice. */
    private const int LOCK_TTL_SECONDS = 60;

    /** How long a failed fetch is left alone before it is planned again. */
    private const int FAILED_RETRY_SECONDS = 3600;

    public function __construct(
        private readonly GproApiClient $apiClient,
        private readonly CacheInterface $cache,
        private readonly ApiArchiveRepository $archive,
        private readonly SyncContextFactory $contexts,
        private readonly EndpointFetcher $fetcher,
        private readonly SyncPlanner $planner,
        private readonly GproApiThrottle $throttle,
        private readonly ArchiveProjector $projector,
        private readonly RaceSnapshotReader $snapshots,
        private readonly RaceTelemetryService $telemetry,
        private readonly BaselineAutoFillService $baselineAutoFill,
    ) {
    }

    /**
     * Whether a slice has anything to do. Reads the cache and the archive only.
     *
     * @param array<string, mixed> $user
     */
    public function isDue(array $user): bool
    {
        $userId = (int) $user['id'];
        if ($this->bindToken($user)) {
            if ($this->plannedWork($userId) !== []) {
                return true;
            }
        }

        return $this->projector->pendingRaces($userId, 1) !== [];
    }

    /**
     * Never throws. Idle (nothing to do, or nothing this user can spend) is
     * 'done'; 'more' asks to be run again; 'busy' means the shared outbound
     * budget is spent for now; 'locked' means a sync or another slice is running.
     *
     * @param array<string, mixed> $user
     */
    public function runSlice(array $user): HarvestResult
    {
        $userId = (int) $user['id'];
        $started = hrtime(true);

        if (!$this->bindToken($user)) {
            return new HarvestResult('done', 0, 0, self::elapsed($started));
        }

        $lockKey = 'harvest_lock_' . $userId;
        if ($this->cache->has('sync_lock_' . $userId) || $this->cache->has($lockKey)) {
            return $this->finish($userId, 'locked', 0, 0, $started);
        }
        $this->cache->set($lockKey, time(), self::LOCK_TTL_SECONDS);

        $calls = 0;
        $projected = 0;
        try {
            $entries = $this->plannedWork($userId);
            $busy = false;
            $attempted = 0;
            $ingested = false;
            foreach (array_slice($entries, 0, self::MAX_CALLS) as $entry) {
                if ($this->throttle->available(microtime(true)) < 1.0) {
                    $busy = true;
                    break;
                }
                $attempted++;
                $calls++;
                $ingested = $this->fetchOne($userId, $entry['endpoint'], $entry['params']) || $ingested;
            }
            if ($ingested) {
                $this->baselineAutoFill->run();
            }

            [$projected, $failed] = $this->project($userId);
            $more = count($entries) > $attempted || $this->stillPending($userId, $failed);

            return $this->finish($userId, $busy ? 'busy' : ($more ? 'more' : 'done'), $calls, $projected, $started);
        } catch (Throwable $e) {
            error_log('[Harvest] user ' . $userId . ' slice failed: ' . $e::class);

            return $this->finish($userId, 'busy', $calls, $projected, $started);
        } finally {
            $this->cache->delete($lockKey);
        }
    }

    /**
     * Points the shared client at the user. False when there is no token.
     *
     * @param array<string, mixed> $user
     */
    private function bindToken(array $user): bool
    {
        $token = $user['api_token'] ?? null;
        if (!is_string($token) || $token === '') {
            return false;
        }
        $this->apiClient->setToken($token, (int) $user['id']);

        return true;
    }

    /**
     * Phase-2 work the planner wants paid for, or nothing when a call could not
     * be filed properly: the budget is unknown, or the office the archive
     * stamps rows from is not cached (the rows would carry no race).
     *
     * @return list<array{endpoint: string, params: string}>
     */
    private function plannedWork(int $userId): array
    {
        // The remaining budget is the last one observed (up to an hour old) and
        // the backfill counter lives in a cache that can be flushed mid-window,
        // so a slice can overshoot the planner's floors by at most its own
        // MAX_CALLS calls; the >= 60 / 50 floors and the `SR=` exclusion bound it.
        $remaining = $this->apiClient->lastKnownRemaining();
        $cachedOffice = $this->apiClient->getCachedOfficeData();
        if ($remaining === null || $cachedOffice === []) {
            return [];
        }

        $scope = $this->apiClient->tokenScope();
        $office = $this->contexts->previousOffice($userId, $scope);
        if ($office === null || $office === [] || !self::sameRace($office, $cachedOffice)) {
            // New rows are stamped from the cached office: if it is not the race
            // the archived sync office describes, they would land on the wrong one.
            return [];
        }

        $ctx = $this->contexts->build(
            $userId,
            $scope,
            $office,
            $office,
            $remaining,
            SyncPlanner::MODE_HARVEST,
            $this->backfillPending($userId, $scope),
            $this->apiClient->backfillCount(),
        );

        return array_values(array_filter(
            $this->planner->plan($ctx)->phase2,
            fn (array $entry): bool => !$this->apiClient->hasHarvestFailed($entry['endpoint'], $entry['params']),
        ));
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function sameRace(array $a, array $b): bool
    {
        foreach (['seasonNb', 'raceNb'] as $key) {
            $x = $a[$key] ?? null;
            $y = $b[$key] ?? null;
            if (!is_scalar($x) || !is_scalar($y) || (string) $x !== (string) $y) {
                return false;
            }
        }

        return true;
    }

    /**
     * Unlocked races the latest analysis lists that have no analysis yet, oldest
     * first, as 'season,race'. A race counts as having one when any analysis row
     * is stamped with it or was asked for it, so a NonSup answer is not retried.
     *
     * @return list<string>
     */
    private function backfillPending(int $userId, string $scope): array
    {
        try {
            $latest = $this->archive->latest($userId, 'RaceAnalysis', '', $scope);
            $listed = is_array($latest) && is_array($latest['racesToSelect'] ?? null) ? $latest['racesToSelect'] : [];
            if ($listed === []) {
                return [];
            }

            $known = [];
            foreach ($this->archive->stampedRaces($userId, 'RaceAnalysis') as $race) {
                $known[$race['season'] . ',' . $race['race']] = true;
            }
            foreach ($this->archive->paramsFor($userId, 'RaceAnalysis') as $params) {
                if (str_starts_with($params, 'SR=')) {
                    $known[substr($params, 3)] = true;
                }
            }

            $pending = [];
            foreach ($listed as $item) {
                if (!is_array($item) || !filter_var($item['unlocked'] ?? null, FILTER_VALIDATE_BOOLEAN)) {
                    continue;
                }
                $season = $item['season'] ?? null;
                $race = $item['race'] ?? null;
                if (!is_numeric($season) || !is_numeric($race)) {
                    continue;
                }
                $key = (int) $season . ',' . (int) $race;
                if (!isset($known[$key]) && !$this->apiClient->hasHarvestFailed(Endpoint::ANALYSIS, 'SR=' . $key)) {
                    $pending[$key] = [(int) $season, (int) $race];
                }
            }
            usort($pending, static fn (array $a, array $b): int => $a <=> $b);

            return array_map(static fn (array $r): string => $r[0] . ',' . $r[1], $pending);
        } catch (Throwable $e) {
            error_log('[Harvest] user ' . $userId . ': backfill read failed: ' . $e::class);

            return [];
        }
    }

    /**
     * @return bool whether a race analysis was newly stored as telemetry
     */
    private function fetchOne(int $userId, string $endpoint, string $params): bool
    {
        $backfill = $endpoint === Endpoint::ANALYSIS && $params !== '';
        try {
            $payload = $this->fetcher->fetch($endpoint, $params);
        } catch (Throwable $e) {
            error_log('[Harvest] user ' . $userId . ': ' . $endpoint . ' failed: ' . $e::class);
            $this->apiClient->markHarvestFailed($endpoint, $params, self::FAILED_RETRY_SECONDS);

            return false;
        } finally {
            if ($backfill) {
                $this->apiClient->addBackfill();
            }
        }

        if ($endpoint !== Endpoint::ANALYSIS) {
            return false;
        }
        if ($payload === []) {
            // getRaceAnalysis() swallows its own failure; an asked-for race is
            // not asked for again until the next one, as in the sync.
            if (!$backfill) {
                $this->apiClient->markAnalysisAttempt();
            }
            error_log('[Harvest] user ' . $userId . ': ' . $endpoint . ' failed: ' . UnexpectedValueException::class);
            $this->apiClient->markHarvestFailed($endpoint, $params, self::FAILED_RETRY_SECONDS);

            return false;
        }
        if (SupporterGate::isUnavailable('RaceAnalysis', $payload)) {
            return false;
        }

        return $this->ingest($userId, $payload);
    }

    /**
     * The TD and driver profile that raced come from the snapshot archived for
     * the analysed race, never from today's office.
     *
     * @param array<string, mixed> $analysis
     */
    private function ingest(int $userId, array $analysis): bool
    {
        try {
            $season = $analysis['selSeasonNb'] ?? null;
            $race = $analysis['selRaceNb'] ?? null;
            $td = [];
            $driver = [];
            if (is_numeric($season) && is_numeric($race)) {
                $snapshot = $this->snapshots->read($userId, (int) $season, (int) $race);
                $td = $snapshot->td;
                $driver = GproSyncService::raceWasRunByCurrentDriver($analysis, $snapshot->office)
                    ? $snapshot->driver
                    : [];
            }

            return $this->telemetry->ingest($analysis, $td, $driver);
        } catch (Throwable $e) {
            error_log('[Harvest] user ' . $userId . ': telemetry failed: ' . $e::class);

            return false;
        }
    }

    /**
     * @return array{int, list<array{season: int, race: int}>} projected count, races that failed
     */
    private function project(int $userId): array
    {
        $projected = 0;
        $failed = [];
        foreach ($this->projector->pendingRaces($userId, self::MAX_PROJECTIONS) as $race) {
            try {
                $this->projector->projectRace($userId, $race['season'], $race['race']);
                $projected++;
            } catch (Throwable $e) {
                error_log('[Harvest] user ' . $userId . ': projection failed: ' . $e::class);
                $failed[] = $race;
            }
        }

        return [$projected, $failed];
    }

    /**
     * A failed race stays pending, but must not keep the slices coming.
     *
     * @param list<array{season: int, race: int}> $failed
     */
    private function stillPending(int $userId, array $failed): bool
    {
        foreach ($this->projector->pendingRaces($userId, count($failed) + 1) as $race) {
            if (!in_array($race, $failed, true)) {
                return true;
            }
        }

        return false;
    }

    private function finish(int $userId, string $status, int $calls, int $projected, int $started): HarvestResult
    {
        $ms = self::elapsed($started);
        error_log(sprintf(
            '[Harvest] user %d: %s, %d calls, %d projected, %d ms',
            $userId,
            $status,
            $calls,
            $projected,
            $ms,
        ));

        return new HarvestResult($status, $calls, $projected, $ms);
    }

    private static function elapsed(int $started): int
    {
        return intdiv(hrtime(true) - $started, 1_000_000);
    }
}
