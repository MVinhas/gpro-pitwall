<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The one answer to "is this manager's data in sync?", shared by every place
 * that reports it (the header strip, the Account page).
 *
 * It exists because those places used to read different inputs — the header
 * read `users.sync_status`, the Account page only whether a token was saved —
 * so a failed sync showed "⚠ Sync failed" in the header and "You're connected"
 * on the same page.
 */
final class SyncState
{
    public const string NO_TOKEN = 'no_token';
    public const string RUNNING  = 'running';
    public const string FAILED   = 'failed';
    public const string DEFERRED = 'deferred';
    public const string SYNCED   = 'synced';
    public const string NEVER    = 'never';

    public static function derive(?string $status, ?string $lastSyncedAt, bool $hasToken): string
    {
        if (!$hasToken) {
            return self::NO_TOKEN;
        }

        return match ($status) {
            'running'             => self::RUNNING,
            'failed'              => self::FAILED,
            'deferred_low_budget' => self::DEFERRED,
            default               => ($lastSyncedAt ?? '') !== '' ? self::SYNCED : self::NEVER,
        };
    }

    /**
     * GPRO simulates each race from 20:00 CET (GPRO rules) and it runs about two
     * hours; only a sync after it ends picks up the post-race car, driver and
     * next-race data. Europe/Paris tracks CET/CEST, so the hour holds year-round.
     */
    private const int RACE_END_HOUR = 22;
    private const string RACE_TZ    = 'Europe/Paris';

    /**
     * Whether a race has finished since the last sync, so the car, driver and
     * forecast on screen may be out of date. A race that hasn't run yet (race-day
     * morning, or mid-simulation) doesn't make an earlier sync stale: re-syncing
     * would fetch the same data. Stored timestamps are UTC. Never-synced and
     * disabled windowing are not "stale": the never state already says so.
     *
     * @param list<int> $raceDays ISO-8601 weekday numbers (see RaceWindow)
     */
    public static function isStale(?string $lastSyncedAt, DateTimeImmutable $now, array $raceDays): bool
    {
        if ($lastSyncedAt === null || $lastSyncedAt === '' || $raceDays === []) {
            return false;
        }
        $synced = new DateTimeImmutable($lastSyncedAt, new DateTimeZone('UTC'));

        return RaceWindow::idFor($synced, $raceDays, self::RACE_END_HOUR, self::RACE_TZ)
            < RaceWindow::idFor($now, $raceDays, self::RACE_END_HOUR, self::RACE_TZ);
    }

    /**
     * @param array<string, mixed> $user a `users` row, token not yet redacted
     */
    public static function forUser(array $user): string
    {
        $status = $user['sync_status'] ?? null;
        $last   = $user['last_synced_at'] ?? null;

        return self::derive(
            is_string($status) ? $status : null,
            is_string($last) ? $last : null,
            ($user['api_token'] ?? '') !== '',
        );
    }
}
