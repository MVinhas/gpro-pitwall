<?php

declare(strict_types=1);

namespace App\Support;

use Closure;

/**
 * Decides when a screen is sent as a skeleton first and filled by a follow-up
 * request, so a manager never stares at a blank tab while GPRO answers.
 *
 * The first response (the skeleton) does no GPRO work and returns at once. The
 * page then requests the same URL with `wait=1`, which runs the pending sync
 * and renders the real screen; the page swaps that in.
 */
final class DeferredRender
{
    public const string WAIT_PARAM = 'wait';

    /**
     * Screens that read GPRO payloads while rendering. Recruitment reads the
     * shared driver market rather than the manager's own payloads, so the
     * caller's warm probe is the market's for that screen.
     */
    private const array DATA_SECTIONS = [
        'Cockpit',
        'Race Strategy',
        'Car Wear Planner',
        'Testing',
        'Training Planner',
        'Recruitment Analyzer',
    ];

    /**
     * @param Closure(): bool $isWarm probed only when the answer depends on it
     */
    public static function shouldDefer(bool $wait, bool $syncPending, string $section, Closure $isWarm): bool
    {
        if ($wait) {
            return false;
        }
        // A login sync is owed. Warm, the page renders now and the sync runs
        // when the browser is idle (see idleSync); cold, whatever screen this
        // is has nothing to show until the sync has run, so it waits.
        if ($syncPending) {
            return !$isWarm();
        }
        if (!in_array($section, self::DATA_SECTIONS, true)) {
            return false;
        }

        return !$isWarm();
    }

    /**
     * Whether a page that rendered from warm data should ask the browser to
     * run the owed login sync once idle, instead of syncing inline. The
     * follow-up request (`wait`) always syncs inline: it exists to do the work.
     * So does any page after one that already offered the idle sync ($offered):
     * a browser that never ran it (no JavaScript) must not stay unsynced for
     * as long as the cache stays warm.
     *
     * @param Closure(): bool $isWarm probed only when the answer depends on it
     */
    public static function idleSync(
        bool $wait,
        bool $syncPending,
        bool $deferred,
        bool $offered,
        Closure $isWarm,
    ): bool {
        return $syncPending && !$wait && !$deferred && !$offered && $isWarm();
    }

    /** Whether the page itself runs the owed login sync before rendering. */
    public static function syncsInline(bool $syncPending, bool $deferred, bool $idleSync): bool
    {
        return $syncPending && !$deferred && !$idleSync;
    }

    /**
     * The URL the skeleton requests to fetch the real screen.
     *
     * @param array<string, mixed> $query
     */
    public static function followUpUrl(string $path, array $query): string
    {
        unset($query[self::WAIT_PARAM]);
        $query[self::WAIT_PARAM] = '1';

        return $path . '?' . http_build_query($query);
    }
}
