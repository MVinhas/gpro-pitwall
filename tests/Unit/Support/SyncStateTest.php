<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\SyncState;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SyncState::class)]
final class SyncStateTest extends TestCase
{
    private const array TUE_FRI = [2, 5];

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

    private function lisbon(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone('Europe/Lisbon'));
    }

    public function testASyncBeforeRaceDayIsFreshUntilTheRaceRuns(): void
    {
        // Monday 09:39 Lisbon (08:39 UTC); Tuesday 07:42 Lisbon — Tuesday's race hasn't run yet.
        $this->assertFalse(SyncState::isStale('2026-09-14 08:39:00', $this->lisbon('2026-09-15 07:42'), self::TUE_FRI));
    }

    public function testTheWarningWaitsForTheRaceToFinish(): void
    {
        // The race runs 20:00–22:00 CET (19:00–21:00 Lisbon); re-syncing mid-race gets nothing new.
        $this->assertFalse(SyncState::isStale('2026-09-14 08:39:00', $this->lisbon('2026-09-15 20:30'), self::TUE_FRI));
        $this->assertTrue(SyncState::isStale('2026-09-14 08:39:00', $this->lisbon('2026-09-15 21:00'), self::TUE_FRI));
    }

    public function testASyncBeforeTheLastRaceIsStale(): void
    {
        // Tuesday-morning sync, viewed on Wednesday: Tuesday's race has run since.
        $this->assertTrue(SyncState::isStale('2026-09-15 09:00:00', $this->lisbon('2026-09-16 10:00'), self::TUE_FRI));
    }

    public function testASyncDuringTheRaceIsStillStale(): void
    {
        // 19:30 UTC is 21:30 CEST: the simulation was still running, so the data is pre-race.
        $this->assertTrue(SyncState::isStale('2026-09-15 19:30:00', $this->lisbon('2026-09-16 10:00'), self::TUE_FRI));
    }

    public function testStoredTimestampsAreReadAsUtc(): void
    {
        // 20:30 UTC is 22:30 CEST, after the race. Read as Lisbon time it would be 21:30 CEST, mid-race.
        $this->assertFalse(SyncState::isStale('2026-09-15 20:30:00', $this->lisbon('2026-09-16 10:00'), self::TUE_FRI));
    }

    public function testTheRaceEndFollowsCentralEuropeanWinterTime(): void
    {
        // Friday 2026-11-06, after the clocks change: the race ends 22:00 CET = 21:00 UTC.
        $saturday = $this->lisbon('2026-11-07 10:00');

        $this->assertTrue(SyncState::isStale('2026-11-06 20:55:00', $saturday, self::TUE_FRI));
        $this->assertFalse(SyncState::isStale('2026-11-06 21:05:00', $saturday, self::TUE_FRI));
    }

    public function testNeverSyncedOrWindowingDisabledIsNotReportedStale(): void
    {
        $now = $this->lisbon('2026-09-16 10:00');

        $this->assertFalse(SyncState::isStale(null, $now, self::TUE_FRI));
        $this->assertFalse(SyncState::isStale('', $now, self::TUE_FRI));
        $this->assertFalse(SyncState::isStale('2026-01-01 00:00:00', $now, []));
    }

    public function testForUserReadsTheStoredColumnsAndTokenPresence(): void
    {
        $user = ['sync_status' => 'failed', 'last_synced_at' => '2026-09-13 13:45:59', 'api_token' => 'tok'];

        $this->assertSame(SyncState::FAILED, SyncState::forUser($user));
        $this->assertSame(SyncState::NO_TOKEN, SyncState::forUser(['api_token' => ''] + $user));
        $this->assertSame(SyncState::NO_TOKEN, SyncState::forUser(['sync_status' => 'idle']));
    }
}
