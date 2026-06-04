<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Cache\Adapter\FilesystemCache;
use App\Service\GproApiClient;
use App\Service\GproApiFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Guards the cross-user cache-isolation invariant: per-user GPRO data is
 * namespaced by token, so one manager's cached payload can never be read
 * under another manager's session. Regression test for the production leak
 * where users saw each other's driver/car/sponsor data.
 */
#[CoversClass(GproApiClient::class)]
final class GproApiClientTest extends TestCase
{
    private string $cacheDir;
    private FilesystemCache $cache;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/gpro_api_' . bin2hex(random_bytes(6));
        $this->cache = new FilesystemCache($this->cacheDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->cacheDir);
    }

    private function client(): GproApiClient
    {
        // Unreachable host: we never exercise a real fetch here, only the
        // cache-read side, which short-circuits before any HTTP.
        return new GproApiClient(
            new GproApiFetcher(['base_url' => 'http://127.0.0.1:9', 'version' => 'test']),
            $this->cache,
        );
    }

    public function testScopeDiffersPerToken(): void
    {
        $this->assertNotSame(
            GproApiClient::scopeFor('token-a'),
            GproApiClient::scopeFor('token-b'),
        );
    }

    public function testScopeIsStableForSameToken(): void
    {
        $this->assertSame(
            GproApiClient::scopeFor('token-a'),
            GproApiClient::scopeFor('token-a'),
        );
    }

    public function testRawTokenNeverAppearsInTheCacheKey(): void
    {
        $this->assertStringNotContainsString('token-a', GproApiClient::budgetKeyForToken('token-a'));
    }

    public function testBudgetCounterIsIsolatedAcrossUsers(): void
    {
        // User A's client records a budget; it lands under A's namespace.
        $this->cache->set(GproApiClient::budgetKeyForToken('token-a'), 42, 3600);

        $b = $this->client();
        $b->setToken('token-b');

        // User B must NOT see A's counter — proves keys are namespaced.
        $this->assertNull($b->lastKnownRemaining());

        $a = $this->client();
        $a->setToken('token-a');
        $this->assertSame(42, $a->lastKnownRemaining());
    }

    public function testCachedCalendarIsIsolatedAcrossUsers(): void
    {
        // Seed A's calendar under A's namespace by addressing the same key
        // shape the client uses (u<scope>:calendar).
        $aKey = 'u' . GproApiClient::scopeFor('token-a') . ':calendar';
        $this->cache->set($aKey, ['events' => [['eventType' => 'R', 'trackId' => '25']]], 3600);

        $b = $this->client();
        $b->setToken('token-b');
        $this->assertSame([], $b->getCachedCalendar(), 'B must not read A cached calendar');

        $a = $this->client();
        $a->setToken('token-a');
        $this->assertNotSame([], $a->getCachedCalendar(), 'A still reads its own calendar');
    }
}
