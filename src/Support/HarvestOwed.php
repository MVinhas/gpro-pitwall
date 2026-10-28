<?php

declare(strict_types=1);

namespace App\Support;

use App\Service\AuthService;
use App\Service\HarvestService;

/**
 * Whether a page should start the background harvest, read from the session
 * flag a successful sync sets (AuthService::HARVEST_OWED).
 */
final class HarvestOwed
{
    /**
     * An owed harvest with nothing left to do is settled here, as a 'done'
     * slice settles it: otherwise the flag outlives the click, and a later page
     * would start slices once failure memos or the backfill window expire.
     *
     * @param array<string, mixed> $user
     */
    public static function due(HarvestService $harvest, array $user): bool
    {
        $owed = !empty($_SESSION[AuthService::HARVEST_OWED]);
        $due = $harvest->isDueSafely($user, $owed);
        if ($owed && !$due) {
            unset($_SESSION[AuthService::HARVEST_OWED]);
        }

        return $due;
    }
}
