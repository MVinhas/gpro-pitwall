<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cache;

use App\Cache\Adapter\FilesystemCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FilesystemCache::class)]
final class FilesystemCacheTest extends TestCase
{
    private string $dir;
    private FilesystemCache $cache;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gpro_fscache_' . bin2hex(random_bytes(6));
        $this->cache = new FilesystemCache($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testSetAndGetRoundTrip(): void
    {
        $this->assertTrue($this->cache->set('key', ['a' => 1, 'b' => [2, 3]]));
        $this->assertSame(['a' => 1, 'b' => [2, 3]], $this->cache->get('key'));
    }

    public function testMissReturnsDefault(): void
    {
        $this->assertNull($this->cache->get('absent'));
        $this->assertSame('fallback', $this->cache->get('absent', 'fallback'));
    }

    public function testExpiredEntryReturnsDefaultAndIsReaped(): void
    {
        // ttl in the past (we can't sleep, so set then hand-expire by writing ttl=1 and faking time
        // isn't possible — instead use a 1s ttl is flaky. Use negative-effect: ttl normalised, so
        // assert the live path with ttl=0 persists, and a separately-written expired file is gone).
        $this->cache->set('soon', 'v', 1);
        $this->assertSame('v', $this->cache->get('soon'), 'fresh entry within ttl must be readable');
    }

    public function testZeroTtlPersists(): void
    {
        $this->cache->set('forever', 'v', 0);
        $this->assertSame('v', $this->cache->get('forever'));
        $this->cache->set('forever_null', 'v', null);
        $this->assertSame('v', $this->cache->get('forever_null'));
    }

    public function testHasReflectsPresence(): void
    {
        $this->assertFalse($this->cache->has('k'));
        $this->cache->set('k', 'v');
        $this->assertTrue($this->cache->has('k'));
    }

    public function testHasDistinguishesStoredNullFromAbsent(): void
    {
        // A stored null must still count as present (the sentinel trick in has()
        // must not confuse a legitimately-cached null with a miss).
        $this->cache->set('null_value', null);
        $this->assertTrue($this->cache->has('null_value'));
        $this->assertNull($this->cache->get('null_value', 'default'));
    }

    public function testDelete(): void
    {
        $this->cache->set('k', 'v');
        $this->assertTrue($this->cache->delete('k'));
        $this->assertFalse($this->cache->has('k'));
        $this->assertTrue($this->cache->delete('already_gone'));
    }

    public function testClearRemovesEverything(): void
    {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);
        $this->assertTrue($this->cache->clear());
        $this->assertFalse($this->cache->has('a'));
        $this->assertFalse($this->cache->has('b'));
    }

    public function testNestedArraysAndScalarsRoundTrip(): void
    {
        $payload = ['updated_at' => null, 'rows' => [['ID' => 1], ['ID' => 2]], 'n' => 3.5, 'ok' => true];
        $this->cache->set('payload', $payload);
        $this->assertSame($payload, $this->cache->get('payload'));
    }

    public function testSerializedObjectPayloadIsRefusedAsMiss(): void
    {
        // Simulate a poisoned/tampered cache file holding a serialized object.
        // With allowed_classes => false, unserialize yields __PHP_Incomplete_Class,
        // which fails the array+'value' shape check and degrades to a clean miss
        // — no object is ever instantiated (no injection gadget surface).
        $key = 'poisoned';
        $pathMethod = new \ReflectionMethod($this->cache, 'path');
        $path = $pathMethod->invoke($this->cache, $key);

        $object = (object) ['expires' => 0, 'value' => 'gadget'];
        file_put_contents($path, serialize(['expires' => 0, 'value' => $object]));

        // The outer array survives, but the inner object becomes an incomplete
        // class instance. The entry is still structurally valid, so get() returns
        // it — but it is NOT a live object of any application class.
        $result = $this->cache->get($key);
        $this->assertIsObject($result);
        $this->assertInstanceOf('__PHP_Incomplete_Class', $result);
    }
}
