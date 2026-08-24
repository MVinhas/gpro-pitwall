<?php

declare(strict_types=1);

namespace App\Cache;

/**
 * Prefixes every key with a namespace derived from the app version, so a
 * release bump makes all previously cached entries miss instead of being
 * served to code that no longer understands their shape.
 *
 * Why this exists: APCu (and Redis) keep one shared segment that outlives a
 * deploy — unlike var/cache, there is no directory a release can wipe. A
 * payload cached by 1.14.0 stayed readable by 1.14.1 under the identical key,
 * so shape changes only cleared after a manual apcu_clear_cache(). Rolling the
 * prefix turns "deploy, then remember to flush" into a plain cache miss.
 *
 * Old entries are left to expire on their own TTL rather than being deleted:
 * the segment is shared with whatever is still serving the previous release
 * mid-deploy, and yanking its keys would stampede the upstream API.
 */
final class NamespacedCache implements CacheInterface
{
    private readonly string $prefix;

    public function __construct(
        private readonly CacheInterface $inner,
        string $version,
    ) {
        // Hashed and truncated so the prefix stays short and opaque — cache
        // keys reach filenames and log lines, and a version string there is
        // just noise that also advertises the running release.
        $this->prefix = 'v' . substr(hash('sha256', $version), 0, 8) . ':';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->inner->get($this->prefix . $key, $default);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->inner->set($this->prefix . $key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->inner->delete($this->prefix . $key);
    }

    /** Wipes the whole driver, every namespace — never just this release's. */
    public function clear(): bool
    {
        return $this->inner->clear();
    }

    public function has(string $key): bool
    {
        return $this->inner->has($this->prefix . $key);
    }
}
