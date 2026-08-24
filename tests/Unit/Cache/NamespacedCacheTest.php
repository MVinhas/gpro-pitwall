<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cache;

use App\Cache\Adapter\NullCache;
use App\Cache\CacheInterface;
use App\Cache\NamespacedCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * In-memory stand-in for a cache that outlives a deploy — the APCu case. The
 * store is passed in by reference so two NamespacedCache instances (an "old"
 * and a "new" release) can share one backing segment, exactly as two PHP-FPM
 * releases share one APCu shared-memory segment.
 */
final class SharedMemoryCache implements CacheInterface
{
    /** @var array<string, mixed> */
    public array $store = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store[$key] ?? $default;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->store[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->store = [];
        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->store);
    }
}

#[CoversClass(NamespacedCache::class)]
final class NamespacedCacheTest extends TestCase
{
    public function testDeployBumpMakesPreviousEntriesMiss(): void
    {
        $shared = new SharedMemoryCache();

        $old = new NamespacedCache($shared, '1.14.0');
        $old->set('u123:car_data', ['shape' => 'old']);

        // Same key, same shared segment, new release. The old-shape payload
        // must NOT be served — that is the stale-cache bug this class exists
        // to kill, the one that previously needed a manual APCu flush.
        $new = new NamespacedCache($shared, '1.14.1');
        self::assertNull($new->get('u123:car_data'));
        self::assertFalse($new->has('u123:car_data'));
    }

    public function testSameVersionStillHits(): void
    {
        $shared = new SharedMemoryCache();

        $a = new NamespacedCache($shared, '1.14.1');
        $a->set('u123:car_data', ['shape' => 'new']);

        // Caching must still actually cache within one release, otherwise the
        // fix would just turn every request into an upstream API call.
        $b = new NamespacedCache($shared, '1.14.1');
        self::assertSame(['shape' => 'new'], $b->get('u123:car_data'));
        self::assertTrue($b->has('u123:car_data'));
    }

    public function testDeleteRemovesOnlyTheNamespacedEntry(): void
    {
        $shared = new SharedMemoryCache();

        $old = new NamespacedCache($shared, '1.14.0');
        $old->set('k', 'old-value');

        $new = new NamespacedCache($shared, '1.14.1');
        $new->set('k', 'new-value');
        $new->delete('k');

        self::assertNull($new->get('k'));
        self::assertSame('old-value', $old->get('k'));
    }

    public function testKeysAreActuallyPrefixedInTheBackingStore(): void
    {
        $shared = new SharedMemoryCache();

        (new NamespacedCache($shared, '1.14.1'))->set('plain', 1);

        $keys = array_keys($shared->store);
        self::assertCount(1, $keys);
        self::assertNotSame('plain', $keys[0]);
        self::assertStringEndsWith(':plain', $keys[0]);
    }

    public function testClearDelegatesToTheUnderlyingDriver(): void
    {
        $shared = new SharedMemoryCache();
        $shared->set('unrelated', 'x');

        self::assertTrue((new NamespacedCache($shared, '1.14.1'))->clear());
        self::assertSame([], $shared->store);
    }

    public function testTtlIsPassedThroughUnchanged(): void
    {
        $inner = new class extends NullCache {
            public ?int $seenTtl = -1;

            public function set(string $key, mixed $value, ?int $ttl = null): bool
            {
                $this->seenTtl = $ttl;
                return true;
            }
        };

        (new NamespacedCache($inner, '1.14.1'))->set('k', 'v', 900);

        self::assertSame(900, $inner->seenTtl);
    }

    public function testDefaultIsReturnedOnMiss(): void
    {
        self::assertSame(
            'fallback',
            (new NamespacedCache(new SharedMemoryCache(), '1.14.1'))->get('nope', 'fallback'),
        );
    }
}
