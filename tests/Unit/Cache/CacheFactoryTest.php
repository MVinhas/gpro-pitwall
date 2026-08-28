<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cache;

use App\Cache\Adapter\FilesystemCache;
use App\Cache\Adapter\NullCache;
use App\Cache\CacheFactory;
use App\Cache\NamespacedCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(CacheFactory::class)]
final class CacheFactoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pitwall-cache-factory-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }

        foreach ((array) glob($this->dir . '/*') as $file) {
            if (is_string($file) && is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->dir);
    }

    /** @param array<string, mixed> $config */
    private function driverFor(array $config): object
    {
        $cache = CacheFactory::create($config + ['CACHE_DIR' => $this->dir]);

        $this->assertInstanceOf(NamespacedCache::class, $cache);

        $inner = new ReflectionProperty(NamespacedCache::class, 'inner');

        $driver = $inner->getValue($cache);
        $this->assertIsObject($driver);

        return $driver;
    }

    public function testEveryDriverIsWrappedSoNoCallerCanGetAnUnNamespacedCache(): void
    {
        $this->assertInstanceOf(
            NamespacedCache::class,
            CacheFactory::create(['CACHE_DRIVER' => 'none'])
        );
    }

    public function testTheDefaultDriverIsFilesystem(): void
    {
        $this->assertInstanceOf(FilesystemCache::class, $this->driverFor([]));
    }

    public function testFilesystemIsSelectedExplicitly(): void
    {
        $this->assertInstanceOf(
            FilesystemCache::class,
            $this->driverFor(['CACHE_DRIVER' => 'filesystem'])
        );
    }

    public function testTheDriverNameIsCaseInsensitive(): void
    {
        $this->assertInstanceOf(
            NullCache::class,
            $this->driverFor(['CACHE_DRIVER' => 'NONE'])
        );
    }

    public function testNoneSelectsTheNullCache(): void
    {
        $this->assertInstanceOf(NullCache::class, $this->driverFor(['CACHE_DRIVER' => 'none']));
    }

    /**
     * A typo in CACHE_DRIVER must not silently disable caching — that would
     * turn a config mistake into an unthrottled hammering of the upstream API.
     */
    public function testAnUnknownDriverDegradesToFilesystemNotToNoCaching(): void
    {
        $driver = $this->driverFor(['CACHE_DRIVER' => 'memcache-typo']);

        $this->assertInstanceOf(FilesystemCache::class, $driver);
        $this->assertNotInstanceOf(NullCache::class, $driver);
    }

    public function testAFailingDriverFallsBackToFilesystem(): void
    {
        if (extension_loaded('apcu') && apcu_enabled()) {
            $this->markTestSkipped('APCu is available, so it cannot be used to force a failure.');
        }

        // The factory error_logs the failure before falling back; silence it so
        // the expected diagnostic doesn't read as a broken test run.
        $previous = ini_set('error_log', '/dev/null');

        try {
            $driver = $this->driverFor(['CACHE_DRIVER' => 'apcu']);
        } finally {
            if (is_string($previous)) {
                ini_set('error_log', $previous);
            }
        }

        $this->assertInstanceOf(FilesystemCache::class, $driver);
    }

    public function testTheProducedCacheActuallyStoresAndReadsBack(): void
    {
        $cache = CacheFactory::create(['CACHE_DIR' => $this->dir]);

        $cache->set('probe', ['shape' => 'v1'], 60);

        $this->assertSame(['shape' => 'v1'], $cache->get('probe'));
    }

    /**
     * The namespace is what makes a release bump invalidate old-shape payloads;
     * two namespaces must not see each other's entries.
     */
    public function testDifferentNamespacesDoNotSeeEachOthersEntries(): void
    {
        $a = CacheFactory::create(['CACHE_DIR' => $this->dir, 'CACHE_NAMESPACE' => '1.0.0']);
        $b = CacheFactory::create(['CACHE_DIR' => $this->dir, 'CACHE_NAMESPACE' => '2.0.0']);

        $a->set('shared-key', 'from-a', 60);

        $this->assertSame('from-a', $a->get('shared-key'));
        $this->assertNull($b->get('shared-key'));
    }

    public function testTheSameNamespaceSharesEntriesAcrossInstances(): void
    {
        $a = CacheFactory::create(['CACHE_DIR' => $this->dir, 'CACHE_NAMESPACE' => '1.0.0']);
        $b = CacheFactory::create(['CACHE_DIR' => $this->dir, 'CACHE_NAMESPACE' => '1.0.0']);

        $a->set('shared-key', 'from-a', 60);

        $this->assertSame('from-a', $b->get('shared-key'));
    }
}
