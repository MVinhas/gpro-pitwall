<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\UserRepository;
use Throwable;

final class GproSyncService
{
    public function __construct(
        private readonly GproApiClient $apiClient,
        private readonly UserRepository $users
    ) {
    }

    /**
     * Attempt sync.
     * NEVER throws. Status is persisted instead.
     */
    public function trySyncForUser(array $user, bool $force = true): void
    {
        if (empty($user['api_token'])) {
            $this->users->updateSyncStatus((int) $user['id'], 'needs_token');
            return;
        }

        $this->users->updateSyncStatus((int) $user['id'], 'running');

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

            $this->users->markSynced((int) $user['id']);
        } catch (Throwable) {
            $this->users->updateSyncStatus((int) $user['id'], 'failed');
        }
    }
}
