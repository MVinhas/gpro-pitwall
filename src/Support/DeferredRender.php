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
        // The login sync has not run yet. Whatever screen this is, its status
        // line and data change once it does.
        if ($syncPending) {
            return true;
        }
        if (!in_array($section, self::DATA_SECTIONS, true)) {
            return false;
        }

        return !$isWarm();
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
