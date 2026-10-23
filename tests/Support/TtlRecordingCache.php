<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Cache\CacheInterface;

/** Decorator that remembers the TTL last passed to set() for each key. */
final class TtlRecordingCache implements CacheInterface
{
    /** @var array<string, int|null> */
    public array $ttls = [];

    public function __construct(private readonly CacheInterface $inner)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->inner->get($key, $default);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->ttls[$key] = $ttl;

        return $this->inner->set($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->inner->delete($key);
    }

    public function clear(): bool
    {
        return $this->inner->clear();
    }

    public function has(string $key): bool
    {
        return $this->inner->has($key);
    }
}
