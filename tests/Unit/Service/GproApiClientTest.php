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
     * Race-critical data (car, setup, next-track profile) lives under one
     * un-windowed key that each sync overwrites, so a new race weekend still
     * reads the newest payload instead of rolling to an empty key. A stale
     * windowed key from an older release is ignored.
     */
    public function testCarDataReadsTheUnwindowedKeyWhateverTheRaceWindow(): void
    {
        $scope = 'u' . GproApiClient::scopeFor('token-a') . ':';
        $this->cache->set($scope . 'car_data', ['carClass' => 7], 3600);
        $this->cache->set($scope . 'car_data:w2026-10-23', ['carClass' => 1], 3600);

        $client = $this->client();
        $client->setToken('token-a');

        // Cache hit short-circuits before any HTTP (the fetcher points at an
        // unreachable host).
        $this->assertSame(7, $client->getCarData()['carClass']);
    }

    /**
     * Seeds every payload the screens read straight after a sync, under the
     * same keys the client uses.
     *
     * @param list<string> $skip base keys to leave out
     */
    private function seedWarm(string $token, string $driId = '30357', array $skip = []): void
    {
        $scope = 'u' . GproApiClient::scopeFor($token) . ':';
        $keys = [
            'office_data'       => $scope . 'office_data',
            'calendar'          => $scope . 'calendar',
            'driver_profile'    => $scope . 'driver_profile_' . $driId,
            'car_data'          => $scope . 'car_data',
            'race_setup'        => $scope . 'race_setup',
            'next_race_profile' => $scope . 'next_race_profile',
        ];
        foreach ($keys as $name => $key) {
            if (in_array($name, $skip, true)) {
                continue;
            }
            $this->cache->set($key, $name === 'office_data' ? ['driId' => $driId] : ['x' => 1], 3600);
        }
    }

    public function testIsWarmIsFalseOnAnEmptyCache(): void
    {
        $client = $this->client();
        $client->setToken('token-a');

        $this->assertFalse($client->isWarm());
    }

    public function testIsWarmIsTrueOnceASyncHasCachedEveryScreenPayload(): void
    {
        $this->seedWarm('token-a');
        $client = $this->client();
        $client->setToken('token-a');

        $this->assertTrue($client->isWarm());
    }

    public function testIsWarmIsFalseWhenARaceCriticalPayloadIsMissing(): void
    {
        $this->seedWarm('token-a', skip: ['car_data']);
        $client = $this->client();
        $client->setToken('token-a');

        $this->assertFalse($client->isWarm());
    }

    public function testIsWarmIsFalseWhenTheDriverProfileIsMissing(): void
    {
        $this->seedWarm('token-a', skip: ['driver_profile']);
        $client = $this->client();
        $client->setToken('token-a');

        $this->assertFalse($client->isWarm());
    }

    public function testIsWarmNeedsNoDriverProfileWithoutADriverUnderContract(): void
    {
        $this->seedWarm('token-a', driId: '', skip: ['driver_profile']);
        $client = $this->client();
        $client->setToken('token-a');

        $this->assertTrue($client->isWarm());
    }

    public function testIsWarmIsScopedToTheToken(): void
    {
        $this->seedWarm('token-a');
        $client = $this->client();
        $client->setToken('token-b');

        $this->assertFalse($client->isWarm());
    }
}
