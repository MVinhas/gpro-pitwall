<?php

declare(strict_types=1);

namespace App\Support;

use Closure;

/**
 * Decides when a screen is sent as a skeleton first and filled by a follow-up
 * request, so a manager never stares at a blank tab while GPRO answers.
 *
 * The first response (the skeleton) does no GPRO work and returns at once. The
 * page then requests the same URL with `wait=1`, which does the slow work and
 * renders the real screen; the page swaps that in.
 *
 * Pages never spend the manager's API budget, so only two kinds of slow work
 * remain: the first sync a never-synced account owes, and the shared driver
 * market downloaded on a miss by a screen that reads it (the caller says
 * whether this one does). Every other screen renders straight from what is
 * stored, cold or not: a follow-up would have nothing more to show.
 */
final class DeferredRender
{
    public const string WAIT_PARAM = 'wait';

    /**
     * @param bool $readsMarket whether the screen reads the shared driver market, so $isWarm is the market's
     * @param Closure(): bool $isWarm probed only when the answer depends on it
     */
    public static function shouldDefer(bool $wait, bool $syncPending, bool $readsMarket, Closure $isWarm): bool
    {
        if ($wait) {
            return false;
        }
        // A first sync is owed (a never-synced account). Warm, the page syncs
        // inline and renders; cold, whatever screen this is has nothing to show
        // until the sync has run, so it waits behind a skeleton.
        if ($syncPending || $readsMarket) {
            return !$isWarm();
        }

        return false;
    }

    /** Whether the page itself runs the owed first sync before rendering. */
    public static function syncsInline(bool $syncPending, bool $deferred): bool
    {
        return $syncPending && !$deferred;
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
