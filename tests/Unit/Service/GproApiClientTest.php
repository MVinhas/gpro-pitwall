<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Cache\Adapter\FilesystemCache;
use App\Service\GproApiClient;
use App\Service\GproApiFetcher;
use App\Support\RaceWindow;
use DateTimeImmutable;
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

    public function testCachedMenuReturnsEmptyArrayOnMiss(): void
    {
        // No cache seeded: the read-only accessor must return [] and NOT fetch
        // (the fetcher points at an unreachable host, so a fetch would fail).
        $client = $this->client();
        $client->setToken('token-a');

        $this->assertSame([], $client->getCachedMenu());
    }

    public function testCachedMenuReturnsCachedPayload(): void
    {
        $key = 'u' . GproApiClient::scopeFor('token-a') . ':manager_menu';
        $this->cache->set($key, ['cash' => 4213500, 'group' => 'Rookie - 39'], 3600);

        $client = $this->client();
        $client->setToken('token-a');

        $menu = $client->getCachedMenu();
        $this->assertSame(4213500, $menu['cash']);
        $this->assertSame('Rookie - 39', $menu['group']);
    }

    public function testCachedMenuIsIsolatedAcrossUsers(): void
    {
        $aKey = 'u' . GproApiClient::scopeFor('token-a') . ':manager_menu';
        $this->cache->set($aKey, ['cash' => 999], 3600);

        $b = $this->client();
        $b->setToken('token-b');
        $this->assertSame([], $b->getCachedMenu(), 'B must not read A cached menu');
    }

    public function testCachedOfficeReturnsEmptyArrayOnMiss(): void
    {
        $client = $this->client();
        $client->setToken('token-a');

        $this->assertSame([], $client->getCachedOfficeData());
    }

    public function testCachedOfficeReturnsCachedPayload(): void
    {
        $key = 'u' . GproApiClient::scopeFor('token-a') . ':office_data';
        $this->cache->set($key, ['trackName' => 'Mexico City', 'seasonNb' => 99, 'raceNb' => 11], 3600);

        $client = $this->client();
        $client->setToken('token-a');

        $office = $client->getCachedOfficeData();
        $this->assertSame('Mexico City', $office['trackName']);
        $this->assertSame(99, $office['seasonNb']);
    }

    private function clientWithOffice(string $token, array $office): GproApiClient
    {
        $key = 'u' . GproApiClient::scopeFor($token) . ':office_data';
        $this->cache->set($key, $office, 3600);
        $client = $this->client();
        $client->setToken($token);
        return $client;
    }

    public function testHasPilotTrueWithDriverUnderContract(): void
    {
        // driId is a string in the GPRO feed.
        $this->assertTrue($this->clientWithOffice('token-a', ['driId' => '30357'])->hasPilot());
    }

    public function testHasPilotFalseWhenDriIdEmpty(): void
    {
        // After a contract terminates GPRO returns an empty driId, not a missing key.
        $this->assertFalse($this->clientWithOffice('token-a', ['driId' => ''])->hasPilot());
    }

    public function testHasPilotFalseWhenDriIdMissing(): void
    {
        $this->assertFalse($this->clientWithOffice('token-a', ['trackName' => 'x'])->hasPilot());
    }

    public function testHasPilotFalseWhenDriIdZero(): void
    {
        $this->assertFalse($this->clientWithOffice('token-a', ['driId' => '0'])->hasPilot());
    }

    /**
     * Race-critical data (car, setup, next-track profile) is namespaced by the
     * current race window so it auto-rolls each race weekend. Proves the read
     * resolves the windowed key, not the bare one. Default schedule (Tue/Fri,
     * midnight UK) is pinned via env so client and test agree on the window.
     */
    public function testCarDataReadsFromTheRaceWindowedKey(): void
    {
        $prev = $_ENV['GPRO_RACE_DAYS'] ?? null;
        $_ENV['GPRO_RACE_DAYS'] = '2,5';

        try {
            $window = RaceWindow::idFor(new DateTimeImmutable('now'), [2, 5], 0, 'Europe/London');
            $windowedKey = 'u' . GproApiClient::scopeFor('token-a') . ':car_data:w' . $window;
            $this->cache->set($windowedKey, ['carClass' => 7], 3600);

            // The bare (un-windowed) key must be ignored — only the windowed
            // one counts, otherwise stale pre-roll data would leak through.
            $this->cache->set('u' . GproApiClient::scopeFor('token-a') . ':car_data', ['carClass' => 1], 3600);

            $client = $this->client();
            $client->setToken('token-a');

            // Cache hit on the windowed key short-circuits before any HTTP
            // (the fetcher points at an unreachable host).
            $this->assertSame(7, $client->getCarData()['carClass']);
        } finally {
            if ($prev === null) {
                unset($_ENV['GPRO_RACE_DAYS']);
            } else {
                $_ENV['GPRO_RACE_DAYS'] = $prev;
            }
        }
    }
}
