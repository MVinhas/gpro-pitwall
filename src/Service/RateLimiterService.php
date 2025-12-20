<?php

declare(strict_types=1);

namespace App\Service;

use App\Cache\CacheInterface;

final readonly class RateLimiterService
{
    public function __construct(
        private CacheInterface $cache
    ) {
    }

    public function increment(string $key, int $ttlSeconds): int
    {
        $current = (int) $this->cache->get($key, 0);
        $value = $current + 1;

        $this->cache->set($key, $value, $ttlSeconds);

        return $value;
    }

    public function get(string $key): int
    {
        return (int) $this->cache->get($key, 0);
    }
}
