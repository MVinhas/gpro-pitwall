<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cache;

use App\Cache\Adapter\ApcuCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ApcuCache::class)]
final class ApcuCacheTest extends TestCase
{
    private ApcuCache $cache;

    protected function setUp(): void
    {
        if (!extension_loaded('apcu') || !apcu_enabled()) {
            $this->markTestSkipped('APCu extension is not loaded or enabled.');
        }

        apcu_clear_cache();
        $this->cache = new ApcuCache();
    }

    protected function tearDown(): void
    {
        if (extension_loaded('apcu') && apcu_enabled()) {
            apcu_clear_cache();
        }
    }

    public function testSetThenGetRoundTrips(): void
    {
        $this->cache->set('k', ['shape' => 'v1']);

        $this->assertSame(['shape' => 'v1'], $this->cache->get('k'));
    }

    public function testAMissReturnsTheDefault(): void
    {
        $this->assertNull($this->cache->get('absent'));
        $this->assertSame('fallback', $this->cache->get('absent', 'fallback'));
    }

    /** A stored false must not be mistaken for a miss. */
    public function testAStoredFalseIsReturnedRatherThanTheDefault(): void
    {
        $this->cache->set('flag', false);

        $this->assertFalse($this->cache->get('flag', 'fallback'));
    }

    public function testHasReflectsPresence(): void
    {
        $this->assertFalse($this->cache->has('k'));

        $this->cache->set('k', 'v');

        $this->assertTrue($this->cache->has('k'));
    }

    public function testDeleteRemovesTheEntry(): void
    {
        $this->cache->set('k', 'v');
        $this->cache->delete('k');

        $this->assertFalse($this->cache->has('k'));
    }

    public function testClearEmptiesTheSegment(): void
    {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);

        $this->cache->clear();

        $this->assertFalse($this->cache->has('a'));
        $this->assertFalse($this->cache->has('b'));
    }

    public function testANullTtlMeansNoExpiryRatherThanImmediateExpiry(): void
    {
        $this->cache->set('k', 'v', null);

        $this->assertSame('v', $this->cache->get('k'));
    }

    public function testTheConstructorRefusesToRunWithoutTheExtension(): void
    {
        if (extension_loaded('apcu') && apcu_enabled()) {
            $this->markTestSkipped('APCu is available, so the guard cannot be exercised here.');
        }

        $this->expectException(RuntimeException::class);
        new ApcuCache();
    }
}
