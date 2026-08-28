<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Cache\CacheInterface;
use App\Service\RateLimiterService;
use App\Tests\Support\ArrayCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RateLimiterService::class)]
final class RateLimiterServiceTest extends TestCase
{
    private ArrayCache $cache;
    private RateLimiterService $limiter;

    protected function setUp(): void
    {
        $this->cache = new ArrayCache();
        $this->limiter = new RateLimiterService($this->cache);
    }

    public function testAnUnseenKeyStartsAtZero(): void
    {
        $this->assertSame(0, $this->limiter->get('login:1.2.3.4'));
    }

    public function testTheFirstIncrementReturnsOne(): void
    {
        $this->assertSame(1, $this->limiter->increment('login:1.2.3.4', 300));
    }

    public function testIncrementsAccumulate(): void
    {
        $this->limiter->increment('login:1.2.3.4', 300);
        $this->limiter->increment('login:1.2.3.4', 300);

        $this->assertSame(3, $this->limiter->increment('login:1.2.3.4', 300));
        $this->assertSame(3, $this->limiter->get('login:1.2.3.4'));
    }

    public function testCountersAreIndependentPerKey(): void
    {
        $this->limiter->increment('login:1.1.1.1', 300);
        $this->limiter->increment('login:1.1.1.1', 300);
        $this->limiter->increment('login:2.2.2.2', 300);

        $this->assertSame(2, $this->limiter->get('login:1.1.1.1'));
        $this->assertSame(1, $this->limiter->get('login:2.2.2.2'));
    }

    public function testTheTtlIsHandedToTheCacheOnEveryWrite(): void
    {
        $cache = new class implements CacheInterface {
            /** @var list<int|null> */
            public array $ttls = [];
            /** @var array<string, mixed> */
            private array $store = [];

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->store[$key] ?? $default;
            }

            public function set(string $key, mixed $value, ?int $ttl = null): bool
            {
                $this->ttls[] = $ttl;
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
        };

        $limiter = new RateLimiterService($cache);
        $limiter->increment('k', 900);
        $limiter->increment('k', 900);

        $this->assertSame([900, 900], $cache->ttls);
    }

    /**
     * A window that has aged out of the cache must read as a clean slate, not
     * as a permanently-tripped limit — otherwise an expired rate limit would
     * lock the user out forever.
     */
    public function testAnExpiredWindowReadsAsAFreshCounter(): void
    {
        $this->limiter->increment('login:1.2.3.4', 1);
        $this->cache->delete('login:1.2.3.4');

        $this->assertSame(0, $this->limiter->get('login:1.2.3.4'));
        $this->assertSame(1, $this->limiter->increment('login:1.2.3.4', 1));
    }

    public function testANonIntegerCachedValueIsCoercedRatherThanFatal(): void
    {
        $this->cache->set('weird', '7');

        $this->assertSame(7, $this->limiter->get('weird'));
        $this->assertSame(8, $this->limiter->increment('weird', 60));
    }
}
