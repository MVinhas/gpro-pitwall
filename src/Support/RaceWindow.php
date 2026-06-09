<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Deterministic race-window identifier derived from the clock alone — no API
 * call. GPRO races run on a fixed weekly schedule (Tuesdays and Fridays by
 * default), so the window a given moment falls into can be computed without
 * fetching anything. Used to namespace race-critical cache keys (car wear,
 * race setup, next-track profile) so they auto-refresh exactly once when a
 * new race weekend opens, instead of serving last-window data behind a stale
 * "last sync" timestamp.
 *
 * The id is the calendar date (in GPRO's timezone) of the most recent race-day
 * boundary at or before the given instant — e.g. "2026-06-09". It changes once
 * per race day and never mid-window, which is exactly the cache epoch we want.
 */
final class RaceWindow
{
    /**
     * Identifier of the race window containing $now, or '' when windowing is
     * disabled (empty $raceDays) — callers treat '' as "no epoch, use the
     * plain TTL".
     *
     * @param list<int> $raceDays ISO-8601 weekday numbers (1=Mon … 7=Sun)
     */
    public static function idFor(
        DateTimeImmutable $now,
        array $raceDays,
        int $boundaryHour,
        string $timezone,
    ): string {
        if ($raceDays === []) {
            return '';
        }

        $cursor = $now->setTimezone(new DateTimeZone($timezone));

        // Walk back up to a week to find the most recent race-day boundary
        // (race day at $boundaryHour) that is at or before $cursor. With two
        // race days a week this lands within at most a few iterations.
        for ($daysBack = 0; $daysBack < 7; $daysBack++) {
            $boundary = $cursor->modify("-{$daysBack} days")->setTime($boundaryHour, 0, 0);
            if ($boundary <= $cursor && in_array((int) $boundary->format('N'), $raceDays, true)) {
                return $boundary->format('Y-m-d');
            }
        }

        // Unreachable for a non-empty $raceDays, but keeps the function total.
        return $cursor->format('Y-m-d');
    }
}
