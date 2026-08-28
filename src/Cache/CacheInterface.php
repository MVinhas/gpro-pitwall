<?php

declare(strict_types=1);

namespace App\Cache;

interface CacheInterface
{
    public function get(string $key, mixed $default = null): mixed;

    /** A null $ttl means "no expiry", not "expire immediately". */
    public function set(string $key, mixed $value, ?int $ttl = null): bool;

    public function delete(string $key): bool;

    public function clear(): bool;

    /** False for an expired entry, which is reaped as a side effect. */
    public function has(string $key): bool;
}
