<?php

declare(strict_types=1);

namespace App\Service;

use App\Cache\CacheInterface;
use App\Repository\UserRepository;

/**
 * A manager deleting their own account: nothing that identifies them, and no
 * data the app has no use for, outlives it.
 */
class AccountErasureService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly CacheInterface $cache,
    ) {
    }

    public function eraseOwnAccount(int $userId): void
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            return;
        }

        // The cache holds raw GPRO payloads under the token; once the token is
        // erased nothing could find them again.
        $token = (string) ($user['api_token'] ?? '');
        if ($token !== '') {
            $this->cache->deleteNamespace(GproApiClient::cacheNamespaceFor($token));
        }

        $this->users->erase($userId);
    }
}
