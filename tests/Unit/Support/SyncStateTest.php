<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\SyncState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SyncState::class)]
final class SyncStateTest extends TestCase
{
    /**
     * @return iterable<string, array{0: ?string, 1: ?string, 2: bool, 3: string}>
     */
    public static function states(): iterable
    {
        yield 'healthy sync'                => ['idle', '2026-09-13 13:45:59', true, SyncState::SYNCED];
        yield 'running'                     => ['running', '2026-09-13 13:45:59', true, SyncState::RUNNING];
        yield 'failed after a good sync'    => ['failed', '2026-09-13 13:45:59', true, SyncState::FAILED];
        yield 'failed with no sync ever'    => ['failed', null, true, SyncState::FAILED];
        yield 'paused for budget'           => ['deferred_low_budget', '2026-09-13 13:45:59', true, SyncState::DEFERRED];
        yield 'token saved, never synced'   => ['needs_token', null, true, SyncState::NEVER];
        yield 'idle, never synced'          => ['idle', null, true, SyncState::NEVER];
        yield 'no token'                    => ['needs_token', null, false, SyncState::NO_TOKEN];
        // A removed token outranks whatever the last sync attempt recorded:
        // nothing can sync until one is added again.
        yield 'no token beats a stale fail' => ['failed', '2026-09-13 13:45:59', false, SyncState::NO_TOKEN];
        yield 'unknown status, synced once' => ['something_new', '2026-09-13 13:45:59', true, SyncState::SYNCED];
        yield 'null status, never synced'   => [null, null, true, SyncState::NEVER];
        yield 'empty timestamp is no sync'  => ['idle', '', true, SyncState::NEVER];
    }

    #[DataProvider('states')]
    public function testDerivesOneStateFromStatusTimestampAndToken(
        ?string $status,
        ?string $lastSyncedAt,
        bool $hasToken,
        string $expected,
    ): void {
        $this->assertSame($expected, SyncState::derive($status, $lastSyncedAt, $hasToken));
    }

    public function testForUserReadsTheStoredColumnsAndTokenPresence(): void
    {
        $user = ['sync_status' => 'failed', 'last_synced_at' => '2026-09-13 13:45:59', 'api_token' => 'tok'];

        $this->assertSame(SyncState::FAILED, SyncState::forUser($user));
        $this->assertSame(SyncState::NO_TOKEN, SyncState::forUser(['api_token' => ''] + $user));
        $this->assertSame(SyncState::NO_TOKEN, SyncState::forUser(['sync_status' => 'idle']));
    }
}
