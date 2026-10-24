<?php

declare(strict_types=1);

namespace App\Service;

use App\Archive\SupporterGate;
use App\Cache\CacheInterface;
use App\Repository\ApiArchiveRepository;
use App\Repository\UserRepository;
use App\Sync\ArchiveEndpoints;
use App\Sync\Endpoint;
use App\Sync\SyncContext;
use App\Sync\SyncOutcome;
use App\Sync\SyncPlanner;
use LogicException;
use Throwable;
use UnexpectedValueException;

final class GproSyncService
{
    /**
     * Deadman ceiling for the coalescing lock. A healthy sync finishes well
     * under this; the TTL only matters if a process dies mid-sync without
     * releasing, in which case the next request can retry after it expires.
     */
    private const int LOCK_TTL_SECONDS = 60;

    /** Budget assumed when neither the fresh office nor the cache knows it. */
    private const int ASSUMED_REMAINING = 100;

    public function __construct(
        private readonly GproApiClient $apiClient,
        private readonly UserRepository $users,
        private readonly CacheInterface $cache,
        private readonly RaceTelemetryService $telemetry,
        private readonly RaceHistoryService $history,
        private readonly BaselineAutoFillService $baselineAutoFill,
        private readonly ApiArchiveRepository $archive,
        private readonly SyncPlanner $planner,
        private readonly int $safetyMargin = 20,
    ) {
    }

    /**
     * Attempt sync. NEVER throws — status is persisted instead.
     *
     * Returns the outcome status so callers (e.g. the warmup endpoint) can report it:
     *   'needs_token' | 'deferred_low_budget' | 'in_progress' | 'synced' | 'failed'
     *
     * @param array<string, mixed> $user
     */
    public function trySyncForUser(array $user, bool $force = true): string
    {
        return $this->sync($user, $force ? SyncPlanner::MODE_MANUAL : SyncPlanner::MODE_LOGIN)->status;
    }

    /**
     * Runs phase 1 of the planner: the office is always fetched fresh as the
     * change detector, then only the endpoints the plan names are spent on.
     * NEVER throws — status is persisted instead.
     *
     * Coalesces concurrent syncs: if one is already in flight for this user
     * (e.g. a tab change fires a second request while the first is running),
     * the later call returns immediately rather than duplicating the API work.
     *
     * @param array<string, mixed> $user
     */
    public function sync(array $user, string $mode): SyncOutcome
    {
        $userId = (int) $user['id'];
        $started = hrtime(true);

        if (empty($user['api_token'])) {
            $this->users->updateSyncStatus($userId, 'needs_token');
            return new SyncOutcome('needs_token');
        }

        // Scope the client to this user's token before any cache read — the
        // budget counter (and everything else) is namespaced per user.
        $this->apiClient->setToken($user['api_token'], $userId);

        // null = never observed, so allow the first sync (it's how we learn
        // the budget in the first place).
        $known = $this->apiClient->lastKnownRemaining();
        if ($known !== null && $known <= $this->safetyMargin) {
            $this->users->updateSyncStatus($userId, 'deferred_low_budget');
            return new SyncOutcome('deferred_low_budget');
        }

        $lockKey = 'sync_lock_' . $userId;
        if ($this->cache->has($lockKey)) {
            // A sync is already running for this user — coalesce, don't duplicate.
            return new SyncOutcome('in_progress');
        }
        $this->cache->set($lockKey, time(), self::LOCK_TTL_SECONDS);

        $this->users->updateSyncStatus($userId, 'running');

        $fetched = [];
        $calls = 0;
        try {
            // Read before the fresh fetch: that fetch archives a new office row.
            // Compared against the office the last SYNC planned against: page
            // renders archive offices too and would swallow done-flag flips.
            // The plain row is only the fallback for the first sync after the
            // 'sync' rows were introduced.
            $scope = $this->apiClient->tokenScope();
            $previousOffice = $this->safely(
                fn (): ?array => $this->archive->latest($userId, 'office', 'sync', $scope)
                    ?? $this->archive->latest($userId, 'office', '', $scope),
                null,
            );

            $calls++;
            try {
                $office = $this->apiClient->getOfficeData(true);
            } catch (Throwable $e) {
                error_log('[GproSync] user ' . $userId . ': ' . Endpoint::OFFICE . ' failed: ' . $e::class);
                $this->users->updateSyncStatus($userId, 'failed');

                return $this->outcome('failed', [], $calls, $started);
            }
            $fetched[] = Endpoint::OFFICE;

            $rawRemaining = $office['apiRequestsRemaining'] ?? null;
            $remaining = is_numeric($rawRemaining)
                ? (int) $rawRemaining
                : ($this->apiClient->lastKnownRemaining() ?? self::ASSUMED_REMAINING);
            if ($remaining <= $this->safetyMargin) {
                $this->users->updateSyncStatus($userId, 'deferred_low_budget');

                return $this->outcome('deferred_low_budget', $fetched, $calls, $started);
            }

            $ctx = $this->context($userId, $scope, $previousOffice, $office, $remaining, $mode);
            $plan = $this->planner->plan($ctx);

            /** @var array<string, array<string, mixed>> $results */
            $results = [];
            /** @var list<string> $attempted */
            $attempted = [];
            $names = [];
            /** @var list<string> $unpaid endpoints the plan wanted but could not get this sync */
            $unpaid = $plan->dropped;
            foreach ($plan->phase1 as $entry) {
                $endpoint = $entry['endpoint'];
                $names[] = $endpoint;
                $attempted[] = $endpoint;
                $calls++;
                try {
                    $payload = $this->fetch($endpoint);
                    // Both getters swallow their own failure into [].
                    if ($payload === [] && $endpoint === Endpoint::ANALYSIS) {
                        // A thrown RaceAnalysis is still an answer to "has this race been asked
                        // for": remember it, and do not hold the sync row back for it.
                        $this->apiClient->markAnalysisAttempt();
                        error_log('[GproSync] user ' . $userId . ': ' . $endpoint . ' failed: '
                            . UnexpectedValueException::class);
                        continue;
                    }
                    if ($payload === [] && $endpoint === Endpoint::TD) {
                        throw new UnexpectedValueException('empty payload');
                    }
                    $results[$endpoint] = $payload;
                    $fetched[] = $endpoint;
                } catch (Throwable $e) {
                    error_log('[GproSync] user ' . $userId . ': ' . $endpoint . ' failed: ' . $e::class);
                    $unpaid[] = $endpoint;
                }
            }
            error_log(sprintf(
                '[GproSync] user %d mode %s plan: %d calls (%s) in %d ms',
                $userId,
                $mode,
                count($plan->phase1),
                implode(', ', $names),
                intdiv((int) (hrtime(true) - $started), 1_000_000),
            ));

            if (
                isset($results[Endpoint::ANALYSIS])
                && !SupporterGate::isUnavailable('RaceAnalysis', $results[Endpoint::ANALYSIS])
            ) {
                $this->recordRace($userId, $office, $results, $attempted);
            }

            // The baseline the next sync diffs against advances unless an unpaid endpoint
            // already has a row for this race: healing only sees endpoints with none, so
            // a done-flag flip on one that has a row would otherwise be lost. GPRO mid
            // race-update serves a half-moved office, which the next login must re-detect.
            $held = array_intersect($unpaid, $ctx->archivedThisRace) !== [];
            if (!$held && !filter_var($office['updateInProgress'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $this->apiClient->archiveSyncOffice($office);
            }

            if ($plan->trimmed) {
                $this->users->updateSyncStatus($userId, 'deferred_low_budget');

                return $this->outcome('deferred_low_budget', $fetched, $calls, $started);
            }

            $this->users->markSynced($userId);

            return $this->outcome('synced', $fetched, $calls, $started);
        } catch (Throwable $e) {
            error_log('[GproSync] user ' . $userId . ' sync failed: ' . $e::class);
            $this->users->updateSyncStatus($userId, 'failed');

            return $this->outcome('failed', $fetched, $calls, $started);
        } finally {
            $this->cache->delete($lockKey);
        }
    }

    /**
     * @param list<string> $fetched
     */
    private function outcome(string $status, array $fetched, int $calls, int $started): SyncOutcome
    {
        return new SyncOutcome($status, $fetched, $calls, intdiv((int) (hrtime(true) - $started), 1_000_000));
    }

    /**
     * @param array<string, mixed>|null $previousOffice
     * @param array<string, mixed> $office
     */
    private function context(
        int $userId,
        string $scope,
        ?array $previousOffice,
        array $office,
        int $remaining,
        string $mode,
    ): SyncContext {
        $season = self::intOrNull($office['seasonNb'] ?? null);
        $race = self::intOrNull($office['raceNb'] ?? null);

        $thisRace = [];
        $thisSeason = [];
        if ($season !== null) {
            $thisSeason = ArchiveEndpoints::toPlanner(
                $this->safely(fn (): array => $this->archive->endpointsForSeason($userId, $season, $scope), []),
            );
        }
        if ($season !== null && $race !== null) {
            $thisRace = array_values(array_diff(
                ArchiveEndpoints::toPlanner(
                    $this->safely(
                        fn (): array => $this->archive->endpointsForRace($userId, $season, $race, $scope),
                        [],
                    ),
                ),
                [Endpoint::ANALYSIS],
            ));
            if ($this->analysisArchivedForPreviousRace($userId, $scope, $season, $race)) {
                $thisRace[] = Endpoint::ANALYSIS;
            }
        }

        return new SyncContext($previousOffice, $office, $remaining, $mode, $thisRace, $thisSeason, [], 0);
    }

    /**
     * RaceAnalysis is stamped with the race it analyses, which is the one just
     * run: the race before the office's (the office names the NEXT race).
     */
    private function analysisArchivedForPreviousRace(int $userId, string $scope, int $season, int $race): bool
    {
        if ($this->analysisAskedAfterPreviousRaceEnded($userId, $scope)) {
            return true;
        }

        $latest = $this->safely(
            fn (): ?array => $this->archive->latest($userId, 'RaceAnalysis', '', $scope),
            null,
        );
        $analysedSeason = self::intOrNull($latest['selSeasonNb'] ?? null);
        if ($analysedSeason === null) {
            return false;
        }
        if ($race > 1) {
            return $analysedSeason === $season && self::intOrNull($latest['selRaceNb'] ?? null) === $race - 1;
        }

        return $analysedSeason === $season - 1;
    }

    /**
     * Whatever GPRO answered once the race was over (an analysis, a NonSup or
     * NA payload, or an error) is as good as it gets until the next race, so
     * asking again would only spend a call: a manager who skipped the race, or
     * a non-supporter, would otherwise pay one on every login.
     */
    private function analysisAskedAfterPreviousRaceEnded(int $userId, string $scope): bool
    {
        $threshold = $this->apiClient->postRaceAt();
        if ($threshold === null) {
            return false;
        }
        if ($this->apiClient->hasAnalysisAttempt()) {
            return true;
        }
        $fetchedAt = $this->safely(
            fn (): ?string => $this->archive->latestFetchedAt($userId, 'RaceAnalysis', '', $scope),
            null,
        );

        return $fetchedAt !== null && (int) strtotime($fetchedAt . ' UTC') >= $threshold->getTimestamp();
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $endpoint): array
    {
        return match ($endpoint) {
            Endpoint::DRIVER => $this->apiClient->getMyPilotDetails(true),
            Endpoint::CAR => $this->apiClient->getCarData(true),
            Endpoint::TRACK => $this->apiClient->getNextRaceProfile(true),
            Endpoint::SETUP => $this->apiClient->getRaceSetup(true),
            Endpoint::TESTING => $this->apiClient->getTesting(true),
            Endpoint::STAFF => $this->apiClient->getStaffAndFacilities(true),
            Endpoint::TD => $this->apiClient->getTechnicalDirector(true),
            Endpoint::SPONSORS => $this->apiClient->getSponsorNegotiations(true),
            Endpoint::MONEY => $this->apiClient->getMoneyLevels(true),
            Endpoint::ANALYSIS => $this->apiClient->getRaceAnalysis(true),
            Endpoint::CALENDAR => $this->apiClient->getCalendar(true),
            Endpoint::MENU => $this->apiClient->getMenu(true),
            Endpoint::TYRES => $this->apiClient->getTyreSuppliers(true),
            default => throw new LogicException('Not a phase 1 endpoint: ' . $endpoint),
        };
    }

    /**
     * @param array<string, mixed> $office
     * @param array<string, array<string, mixed>> $results
     * @param list<string> $attempted
     */
    private function recordRace(int $userId, array $office, array $results, array $attempted): void
    {
        $analysis = $results[Endpoint::ANALYSIS];

        // Technical Directors exist only from Pro up, and only the office
        // says whether this manager has one: GPRO answers TDProfile with a
        // placeholder ("Unknown technical director") that must not be stored
        // as if it were a person.
        $td = self::hasId($office['tdId'] ?? null)
            ? $this->payload(
                Endpoint::TD,
                $results,
                $attempted,
                fn (): array => $this->apiClient->getTechnicalDirector(),
            )
            : [];

        // RaceAnalysis carries no driver age; the DriProfile does, but it
        // describes the driver under contract now, so it only fills the
        // gap when that driver is the one who ran the analysed race.
        $driverProfile = self::raceWasRunByCurrentDriver($analysis, $office)
            ? $this->payload(
                Endpoint::DRIVER,
                $results,
                $attempted,
                fn (): array => $this->apiClient->getMyPilotDetails(),
            )
            : [];

        $staff = $this->payload(
            Endpoint::STAFF,
            $results,
            $attempted,
            fn (): array => $this->apiClient->getStaffAndFacilities(),
        );

        // Anonymous telemetry: contributes race data to the shared corpus
        // without a per-user identifier ever being involved.
        $this->telemetry->ingest($analysis, $td, $driverProfile);

        // The manager's own detailed archive — same payload, opposite
        // privacy contract, kept in a separate table that is only ever
        // read back by the user it belongs to.
        $this->history->archive($userId, $analysis, $td, $staff, $driverProfile);

        // Grow the Division Baseline from whatever the corpus has learned.
        // Pure SQL over data already stored — no API call, and idempotent,
        // so running it every sync converges rather than duplicating.
        $this->baselineAutoFill->run();
    }

    /**
     * This sync's payload for the endpoint; a planned call that failed is not
     * retried, and an endpoint the plan skipped is read from cache/archive.
     * Nice-to-have data: never fails the sync.
     *
     * @param array<string, array<string, mixed>> $results
     * @param list<string> $attempted
     * @param callable(): array<string, mixed> $read
     *
     * @return array<string, mixed>
     */
    private function payload(string $endpoint, array $results, array $attempted, callable $read): array
    {
        if (isset($results[$endpoint])) {
            return $results[$endpoint];
        }
        if (in_array($endpoint, $attempted, true)) {
            return [];
        }

        return $this->safely($read, []);
    }

    /**
     * @template T
     *
     * @param callable(): T $read
     * @param T $default
     *
     * @return T
     */
    private function safely(callable $read, mixed $default): mixed
    {
        try {
            return $read();
        } catch (Throwable $e) {
            error_log('[GproSync] read failed: ' . $e::class);

            return $default;
        }
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param array<string, mixed> $analysis RaceAnalysis payload
     * @param array<string, mixed> $office   Office payload
     */
    public static function raceWasRunByCurrentDriver(array $analysis, array $office): bool
    {
        $driver = $analysis['driver'] ?? null;
        $raced = is_array($driver) && is_scalar($driver['id'] ?? null) ? (string) $driver['id'] : '';
        $current = trim((string) ($office['driId'] ?? ''));

        return self::hasId($current) && $raced === $current;
    }

    private static function hasId(mixed $id): bool
    {
        $id = trim(is_scalar($id) ? (string) $id : '');

        return $id !== '' && $id !== '0';
    }
}
