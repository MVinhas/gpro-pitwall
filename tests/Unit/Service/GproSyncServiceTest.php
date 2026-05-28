<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Cache\Adapter\FilesystemCache;
use App\Repository\UserRepository;
use App\Service\GproApiClient;
use App\Service\GproSyncService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GproSyncService::class)]
final class GproSyncServiceTest extends TestCase
{
    private string $cacheDir;
    private FilesystemCache $cache;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/gpro_sync_' . bin2hex(random_bytes(6));
        $this->cache = new FilesystemCache($this->cacheDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->cacheDir);
    }

    private function apiClient(): GproApiClient
    {
        // Pointed at an unreachable host so any real call fails fast. The
        // coalescing path never calls it; the "ran" path does and is expected
        // to land in the failed branch.
        return new GproApiClient(['base_url' => 'http://127.0.0.1:9'], $this->cache);
    }

    public function testNoTokenSetsNeedsTokenAndNeverSyncs(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())
            ->method('updateSyncStatus')
            ->with(7, 'needs_token');

        $svc = new GproSyncService($this->apiClient(), $users, $this->cache);
        $this->assertSame('needs_token', $svc->trySyncForUser(['id' => 7, 'api_token' => '']));
    }

    public function testConcurrentSyncIsCoalesced(): void
    {
        // Simulate a sync already in flight by pre-setting the lock.
        $this->cache->set('sync_lock_7', time(), 60);

        $users = $this->createMock(UserRepository::class);
        // The coalesced call must NOT touch status or start work.
        $users->expects($this->never())->method('updateSyncStatus');
        $users->expects($this->never())->method('markSynced');

        $svc = new GproSyncService($this->apiClient(), $users, $this->cache);
        $this->assertSame('in_progress', $svc->trySyncForUser(['id' => 7, 'api_token' => 'tok']));
    }

    public function testDefersWhenBudgetBelowMargin(): void
    {
        // 8 calls + margin 20 = needs 28; only 25 remaining → defer.
        $this->cache->set(GproApiClient::API_LIMIT_KEY, 25, 3600);

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())
            ->method('updateSyncStatus')
            ->with(7, 'deferred_low_budget');
        $users->expects($this->never())->method('markSynced');

        $svc = new GproSyncService($this->apiClient(), $users, $this->cache, 20);
        $this->assertSame('deferred_low_budget', $svc->trySyncForUser(['id' => 7, 'api_token' => 'tok']));

        // Deferring must not leave a lock behind.
        $this->assertFalse($this->cache->has('sync_lock_7'));
    }

    public function testProceedsWhenBudgetAboveMargin(): void
    {
        // 8 + 20 = 28; 50 remaining → comfortably above, sync proceeds (and
        // fails against the unreachable host, which is fine — it still ran).
        $this->cache->set(GproApiClient::API_LIMIT_KEY, 50, 3600);

        $users = $this->createMock(UserRepository::class);
        $statuses = [];
        $users->method('updateSyncStatus')
            ->willReturnCallback(function (int $id, string $status) use (&$statuses): void {
                $statuses[] = $status;
            });

        $svc = new GproSyncService($this->apiClient(), $users, $this->cache, 20);
        $result = $svc->trySyncForUser(['id' => 7, 'api_token' => 'tok']);

        $this->assertContains('running', $statuses, 'sufficient budget must start the sync');
        $this->assertNotContains('deferred_low_budget', $statuses);
        // Unreachable host → the sync body runs but ends in failure.
        $this->assertSame('failed', $result);
    }

    public function testFirstEverSyncProceedsWhenBudgetUnknown(): void
    {
        // No API_LIMIT_KEY in cache → null → allow (it's how we learn the budget).
        $users = $this->createMock(UserRepository::class);
        $statuses = [];
        $users->method('updateSyncStatus')
            ->willReturnCallback(function (int $id, string $status) use (&$statuses): void {
                $statuses[] = $status;
            });

        $svc = new GproSyncService($this->apiClient(), $users, $this->cache, 20);
        $svc->trySyncForUser(['id' => 7, 'api_token' => 'tok']);

        $this->assertContains('running', $statuses);
    }

    public function testLockIsReleasedAfterRun(): void
    {
        $users = $this->createMock(UserRepository::class);

        $svc = new GproSyncService($this->apiClient(), $users, $this->cache);
        $svc->trySyncForUser(['id' => 7, 'api_token' => 'tok']);

        // Whether the sync succeeded or failed, the lock must be gone so the
        // next attempt isn't blocked.
        $this->assertFalse(
            $this->cache->has('sync_lock_7'),
            'lock must be released in the finally block',
        );
    }

    public function testRunSetsRunningThenFailsOnUnreachableApi(): void
    {
        $users = $this->createMock(UserRepository::class);
        $statuses = [];
        $users->method('updateSyncStatus')
            ->willReturnCallback(function (int $id, string $status) use (&$statuses): void {
                $statuses[] = $status;
            });

        $svc = new GproSyncService($this->apiClient(), $users, $this->cache);
        $svc->trySyncForUser(['id' => 7, 'api_token' => 'tok']);

        $this->assertSame('running', $statuses[0] ?? null, 'first status must be running');
        $this->assertContains('failed', $statuses, 'unreachable API must end in failed');
    }
}
