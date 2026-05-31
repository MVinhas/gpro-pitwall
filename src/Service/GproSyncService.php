<?php

declare(strict_types=1);

namespace App\Service;

use App\Cache\CacheInterface;
use App\Repository\UserRepository;
use Throwable;

final class GproSyncService
{
    /**
     * Deadman ceiling for the coalescing lock. A healthy 8-call sync finishes
     * well under this; the TTL only matters if a process dies mid-sync without
     * releasing, in which case the next request can retry after it expires.
     */
    private const int LOCK_TTL_SECONDS = 60;

    /** Number of GPRO API calls one full sync spends. */
    private const int CALLS_PER_SYNC = 11;

    public function __construct(
        private readonly GproApiClient $apiClient,
        private readonly UserRepository $users,
        private readonly CacheInterface $cache,
        private readonly int $safetyMargin = 20,
    ) {
    }

    /**
     * Attempt sync. NEVER throws — status is persisted instead.
     *
     * Coalesces concurrent syncs: if one is already in flight for this user
     * (e.g. a tab change fires a second request while the first is running),
     * the later call returns immediately rather than duplicating the API work.
     *
     * Returns the outcome so callers (e.g. the warmup endpoint) can report it:
     *   'needs_token' | 'deferred_low_budget' | 'in_progress' | 'synced' | 'failed'
     *
     * @param array<string, mixed> $user
     */
    public function trySyncForUser(array $user, bool $force = true): string
    {
        $userId = (int) $user['id'];

        if (empty($user['api_token'])) {
            $this->users->updateSyncStatus($userId, 'needs_token');
            return 'needs_token';
        }

        // Refuse to start if the sync would push the remaining API budget
        // below the safety margin. null = never observed, so allow the first
        // sync (it's how we learn the budget in the first place).
        $remaining = $this->apiClient->lastKnownRemaining();
        if ($remaining !== null && $remaining < (self::CALLS_PER_SYNC + $this->safetyMargin)) {
            $this->users->updateSyncStatus($userId, 'deferred_low_budget');
            return 'deferred_low_budget';
        }

        $lockKey = 'sync_lock_' . $userId;
        if ($this->cache->has($lockKey)) {
            // A sync is already running for this user — coalesce, don't duplicate.
            return 'in_progress';
        }
        $this->cache->set($lockKey, time(), self::LOCK_TTL_SECONDS);

        $this->users->updateSyncStatus($userId, 'running');

        try {
            $this->apiClient->setToken($user['api_token']);

            $this->apiClient->getOfficeData($force);
            $this->apiClient->getMyPilotDetails($force);
            $this->apiClient->getCarData($force);
            $this->apiClient->getNextRaceProfile($force);
            $this->apiClient->getRaceSetup($force);
            $this->apiClient->getStaffAndFacilities($force);
            $this->apiClient->getTechnicalDirector($force);
            $this->apiClient->getTyreSuppliers($force);
            $this->apiClient->getMenu($force);
            $this->apiClient->getMoneyLevels($force);
            $this->apiClient->getSponsorNegotiations($force);

            $this->users->markSynced($userId);
            return 'synced';
        } catch (Throwable) {
            $this->users->updateSyncStatus($userId, 'failed');
            return 'failed';
        } finally {
            $this->cache->delete($lockKey);
        }
    }
}
