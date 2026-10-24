<?php

declare(strict_types=1);

namespace App\Sync;

final readonly class SyncContext
{
    /**
     * @param array<string, mixed>|null $previousOffice last archived office, null on a first sync
     * @param array<string, mixed> $office freshly fetched office payload
     * @param list<string> $archivedThisRace endpoints already archived for the office's current race
     * @param list<string> $archivedThisSeason endpoints already archived for the office's current season
     * @param list<string> $backfillPending 'season,race' pairs still missing an analysis
     */
    public function __construct(
        public ?array $previousOffice,
        public array $office,
        public int $remaining,
        public string $mode,
        public array $archivedThisRace,
        public array $archivedThisSeason,
        public array $backfillPending,
        public int $backfillDoneThisWindow = 0,
    ) {
    }
}
