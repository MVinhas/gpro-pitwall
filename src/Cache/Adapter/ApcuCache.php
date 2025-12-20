<?php

declare(strict_types=1);

namespace App\Cache\Adapter;

use App\Cache\CacheInterface;

class ApcuCache implements CacheInterface
{
    public function __construct()
    {
        if (!extension_loaded('apcu') || !apcu_enabled()) {
            // This check should ideally happen in the Factory,
            // but we guard here just in case.
            throw new \RuntimeException('APCu extension is not loaded or enabled.');
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $success = false;
        $value = apcu_fetch($key, $success);
        return $success ? $value : $default;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        // APCu uses 0 for "never expire", null is not accepted by apcu_store directly as "default"
        $ttl ??= 0;
        return apcu_store($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return apcu_delete($key);
    }

    public function clear(): bool
    {
        return apcu_clear_cache();
    }

    public function has(string $key): bool
    {
        return apcu_exists($key);
    }
}
