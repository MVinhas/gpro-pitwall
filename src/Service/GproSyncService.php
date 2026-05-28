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

    public function __construct(
        private readonly GproApiClient $apiClient,
        private readonly UserRepository $users,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Attempt sync. NEVER throws — status is persisted instead.
     *
     * Coalesces concurrent syncs: if one is already in flight for this user
     * (e.g. a tab change fires a second request while the first is running),
     * the later call returns immediately rather than duplicating the API work.
     *
     * @param array<string, mixed> $user
     */
    public function trySyncForUser(array $user, bool $force = true): void
    {
        $userId = (int) $user['id'];

        if (empty($user['api_token'])) {
            $this->users->updateSyncStatus($userId, 'needs_token');
            return;
        }

        $lockKey = 'sync_lock_' . $userId;
        if ($this->cache->has($lockKey)) {
            // A sync is already running for this user — coalesce, don't duplicate.
            return;
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

            $this->users->markSynced($userId);
        } catch (Throwable) {
            $this->users->updateSyncStatus($userId, 'failed');
        } finally {
            $this->cache->delete($lockKey);
        }
    }
}
