<?php

declare(strict_types=1);

namespace App\Service;

use App\Cache\CacheInterface;
use App\Repository\UserRepository;
use App\Sync\Endpoint;
use App\Sync\EndpointFetcher;
use App\Sync\SyncContextFactory;
use App\Sync\SyncOutcome;
use App\Sync\SyncPlanner;
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
        private readonly SyncContextFactory $contexts,
        private readonly EndpointFetcher $fetcher,
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
     * The client's fetch gate is open for those fetches only, so nothing else
     * this run reads can spend a call. NEVER throws — status is persisted instead.
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
            $scope = $this->apiClient->tokenScope();
            $previousOffice = $this->contexts->previousOffice($userId, $scope);

            $calls++;
            try {
                $office = $this->apiClient->withFetching(fn (): array => $this->apiClient->getOfficeData(true));
                // Nothing to plan against, and nothing a sync may be recorded on.
                if ($office === []) {
                    throw new UnexpectedValueException('empty payload');
                }
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

            $ctx = $this->contexts->build($userId, $scope, $previousOffice, $office, $remaining, $mode);
            $plan = $this->planner->plan($ctx);

            $names = [];
            /** @var list<string> $unpaid endpoints the plan wanted but could not get this sync */
            $unpaid = $plan->dropped;
            foreach ($plan->phase1 as $entry) {
                $endpoint = $entry['endpoint'];
                $names[] = $endpoint;
                $calls++;
                try {
                    $payload = $this->apiClient->withFetching(fn (): array => $this->fetcher->fetch($endpoint));
                    // The TD getter swallows its own failure into [].
                    if ($payload === [] && $endpoint === Endpoint::TD) {
                        throw new UnexpectedValueException('empty payload');
                    }
                    $fetched[] = $endpoint;
                } catch (Throwable $e) {
                    error_log('[GproSync] user ' . $userId . ': ' . $endpoint . ' failed: ' . $e::class);
                    $unpaid[] = $endpoint;
                }
            }
            $calls += $this->fetchSponsorProfiles($userId, $this->planner->spareCalls($ctx, count($plan->phase1)));
            error_log(sprintf(
                '[GproSync] user %d mode %s plan: %d calls (%s) in %d ms',
                $userId,
                $mode,
                $calls,
                implode(', ', $names),
                intdiv((int) (hrtime(true) - $started), 1_000_000),
            ));

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
     * The Cockpit's sponsor advice reads one profile per ongoing negotiation,
     * and pages never fetch: each one not stored yet is fetched here, within
     * the calls the plan's floor leaves. Returns the calls made.
     */
    private function fetchSponsorProfiles(int $userId, int $spare): int
    {
        $ongoing = $spare > 0 ? $this->apiClient->getSponsorNegotiations()['ongNegs'] ?? [] : [];
        if (!is_array($ongoing)) {
            return 0;
        }

        $calls = 0;
        foreach ($ongoing as $negotiation) {
            if ($calls >= $spare) {
                break;
            }
            $sponsorId = is_array($negotiation) && is_numeric($negotiation['sponsorId'] ?? null)
                ? (int) $negotiation['sponsorId']
                : 0;
            if ($sponsorId <= 0 || $this->apiClient->getSponsorProfile($sponsorId) !== []) {
                continue;
            }
            $calls++;
            try {
                $this->apiClient->withFetching(fn (): array => $this->apiClient->getSponsorProfile($sponsorId, true));
            } catch (Throwable $e) {
                error_log('[GproSync] user ' . $userId . ': sponsor profile failed: ' . $e::class);
            }
        }

        return $calls;
    }

    /**
     * @param list<string> $fetched
     */
    private function outcome(string $status, array $fetched, int $calls, int $started): SyncOutcome
    {
        return new SyncOutcome($status, $fetched, $calls, intdiv((int) (hrtime(true) - $started), 1_000_000));
    }
}
