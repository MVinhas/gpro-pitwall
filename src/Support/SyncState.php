<?php

declare(strict_types=1);

namespace App\Support;

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
