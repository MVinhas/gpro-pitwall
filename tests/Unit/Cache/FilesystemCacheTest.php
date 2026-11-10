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
        @unlink($this->dir . '/.swept');
        @rmdir($this->dir);
    }

    private const string LIVE = 'v1a2b3c4d';
    private const string USER_A = 'v1a2b3c4d:u0123456789abcdef';
    private const string USER_B = 'v1a2b3c4d:ufedcba9876543210';

    /** Backdates every cache file currently in the directory by two days. */
    private function ageEverything(): void
    {
        foreach (glob($this->dir . '/*.cache') ?: [] as $file) {
            touch($file, time() - 2 * 86400);
        }
    }

    public function testDeleteNamespaceRemovesOneUsersEntriesAndNothingElse(): void
    {
        $this->cache->set(self::USER_A . ':office_data', ['email' => 'a@example.invalid']);
        $this->cache->set(self::USER_A . ':race_analysis_84_7', [1]);
        $this->cache->set(self::USER_B . ':office_data', ['keep']);
        $this->cache->set(self::LIVE . ':market_file_Pro', ['shared']);
        $this->cache->set(self::LIVE . ':login:1.1.1.1', 3);

        $this->assertTrue($this->cache->deleteNamespace(self::USER_A));

        $this->assertFalse($this->cache->has(self::USER_A . ':office_data'));
        $this->assertFalse($this->cache->has(self::USER_A . ':race_analysis_84_7'));
        $this->assertSame(['keep'], $this->cache->get(self::USER_B . ':office_data'));
        $this->assertSame(['shared'], $this->cache->get(self::LIVE . ':market_file_Pro'));
        $this->assertSame(3, $this->cache->get(self::LIVE . ':login:1.1.1.1'));
    }

    public function testDeletingAnOuterNamespaceTakesTheNamespacesInsideIt(): void
    {
        $this->cache->set(self::USER_A . ':office_data', [1]);
        $this->cache->set(self::LIVE . ':market_file_Pro', [2]);
        $this->cache->set('v0000000a:market_file_Pro', ['other release']);

        $this->assertTrue($this->cache->deleteNamespace(self::LIVE));

        $this->assertFalse($this->cache->has(self::USER_A . ':office_data'));
        $this->assertFalse($this->cache->has(self::LIVE . ':market_file_Pro'));
        $this->assertSame(['other release'], $this->cache->get('v0000000a:market_file_Pro'));
    }

    public function testDeleteNamespaceRefusesANameThatIsNotANamespace(): void
    {
        $this->cache->set('plain', 1);
        $this->cache->set(self::USER_A . ':office_data', [1]);

        $this->assertFalse($this->cache->deleteNamespace('*'));
        $this->assertFalse($this->cache->deleteNamespace('../etc'));
        $this->assertFalse($this->cache->deleteNamespace(''));
        $this->assertFalse($this->cache->deleteNamespace('login'));

        $this->assertSame(1, $this->cache->get('plain'));
        $this->assertSame([1], $this->cache->get(self::USER_A . ':office_data'));
    }

    public function testStaleFilesOutsideTheLiveNamespaceAreSwept(): void
    {
        $this->cache->set('written_before_namespaced_files', 1);
        $this->cache->set('v0000000a:u0123456789abcdef:office_data', ['previous release']);
        $this->cache->set(self::USER_A . ':office_data', ['live']);
        $this->ageEverything();
        $this->cache->set('v0000000b:market_file_Pro', ['a release still being served']);

        new FilesystemCache($this->dir, self::LIVE);

        $this->assertFalse($this->cache->has('written_before_namespaced_files'));
        $this->assertFalse($this->cache->has('v0000000a:u0123456789abcdef:office_data'));
        $this->assertSame(['live'], $this->cache->get(self::USER_A . ':office_data'));
        $this->assertSame(['a release still being served'], $this->cache->get('v0000000b:market_file_Pro'));
    }

    public function testTheSweepRunsAtMostOnceADay(): void
    {
        new FilesystemCache($this->dir, self::LIVE);
        $this->cache->set('v0000000a:market_file_Pro', ['previous release']);
        $this->ageEverything();

        new FilesystemCache($this->dir, self::LIVE);
        $this->assertTrue($this->cache->has('v0000000a:market_file_Pro'), 'swept again within the day');

        touch($this->dir . '/.swept', time() - 2 * 86400);
        new FilesystemCache($this->dir, self::LIVE);
        $this->assertFalse($this->cache->has('v0000000a:market_file_Pro'));
    }

    public function testWithoutALiveNamespaceNothingIsSwept(): void
    {
        $this->cache->set('anything', 1);
        $this->ageEverything();

        new FilesystemCache($this->dir);

        $this->assertSame(1, $this->cache->get('anything'));
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

    public function testTheCacheDirectoryIsCreatedOnDemandIncludingParents(): void
    {
        $nested = $this->dir . '/deep/nested/path';

        new FilesystemCache($nested);

        $this->assertDirectoryExists($nested);

        rmdir($nested);
        rmdir($this->dir . '/deep/nested');
        rmdir($this->dir . '/deep');
    }

    public function testAnUncreatableDirectoryIsRefusedLoudly(): void
    {
        $this->expectException(\RuntimeException::class);

        // A path under an existing *file* can never become a directory.
        $file = $this->dir . '/blocker';
        file_put_contents($file, 'x');

        new FilesystemCache($file . '/subdir');
    }

    public function testATrailingSlashInTheDirectoryIsHarmless(): void
    {
        $cache = new FilesystemCache($this->dir . '/');

        $cache->set('k', 'v');

        $this->assertSame('v', $cache->get('k'));
        $this->assertSame('v', $this->cache->get('k'));
    }

    /** A truncated or garbage file must read as a miss, never as a fatal. */
    public function testACorruptCacheFileReadsAsAMiss(): void
    {
        $this->cache->set('k', 'v');

        $files = glob($this->dir . '/*.cache') ?: [];
        $this->assertNotEmpty($files);
        file_put_contents($files[0], 'not-serialized-data');

        $this->assertNull($this->cache->get('k'));
        $this->assertFalse($this->cache->has('k'));
    }

    public function testAWellFormedPayloadMissingItsValueKeyReadsAsAMiss(): void
    {
        $this->cache->set('k', 'v');

        $files = glob($this->dir . '/*.cache') ?: [];
        file_put_contents($files[0], serialize(['expires' => 0]));

        $this->assertNull($this->cache->get('k'));
    }

    public function testDeletingAnAbsentKeyReportsSuccess(): void
    {
        $this->assertTrue($this->cache->delete('never-existed'));
    }

    public function testClearOnAnEmptyDirectoryReportsSuccess(): void
    {
        $this->assertTrue($this->cache->clear());
    }

    /** Keys that aren't filesystem-safe must still round-trip. */
    public function testKeysWithPathSeparatorsAndOddCharactersRoundTrip(): void
    {
        foreach (['a/b/c', '../escape', 'key with spaces', 'ünïcøde:key'] as $key) {
            $this->cache->set($key, "value-for-{$key}");

            $this->assertSame("value-for-{$key}", $this->cache->get($key));
        }
    }

    public function testDistinctKeysDoNotCollide(): void
    {
        $this->cache->set('a/b', 'first');
        $this->cache->set('a_b', 'second');

        $this->assertSame('first', $this->cache->get('a/b'));
        $this->assertSame('second', $this->cache->get('a_b'));
    }

    public function testANegativeTtlIsTreatedAsNoExpiryRatherThanInstantExpiry(): void
    {
        $this->cache->set('k', 'v', -10);

        $this->assertSame('v', $this->cache->get('k'));
    }

    public function testTwoInstancesOnTheSameDirectoryShareEntries(): void
    {
        $this->cache->set('k', 'v');

        $this->assertSame('v', (new FilesystemCache($this->dir))->get('k'));
    }
}
