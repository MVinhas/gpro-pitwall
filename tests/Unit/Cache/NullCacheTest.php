<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cache;

use App\Cache\Adapter\NullCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NullCache::class)]
final class NullCacheTest extends TestCase
{
    private NullCache $cache;

    protected function setUp(): void
    {
        $this->cache = new NullCache();
    }

    public function testGetAlwaysReturnsTheDefault(): void
    {
        $this->assertNull($this->cache->get('anything'));
        $this->assertSame('fallback', $this->cache->get('anything', 'fallback'));
    }

    /** Writes must report success so callers don't treat "no cache" as an error. */
    public function testSetReportsSuccessWithoutStoringAnything(): void
    {
        $this->assertTrue($this->cache->set('k', 'v', 60));
        $this->assertNull($this->cache->get('k'));
    }

    public function testHasIsAlwaysFalseEvenAfterASet(): void
    {
        $this->cache->set('k', 'v');

        $this->assertFalse($this->cache->has('k'));
    }

    public function testDeleteReportsSuccess(): void
    {
        $this->assertTrue($this->cache->delete('k'));
    }

    public function testClearReportsSuccess(): void
    {
        $this->assertTrue($this->cache->clear());
    }
}
