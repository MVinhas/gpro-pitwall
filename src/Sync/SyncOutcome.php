<?php

declare(strict_types=1);

namespace App\Sync;

final readonly class SyncOutcome
{
    /**
     * @param string $status needs_token | deferred_low_budget | in_progress | synced | failed
     * @param list<string> $fetched Endpoint constants fetched this run, office first
     * @param int $calls GPRO fetches attempted, failed ones included
     * @param int $ms wall time of the run
     */
    public function __construct(
        public string $status,
        public array $fetched = [],
        public int $calls = 0,
        public int $ms = 0,
    ) {
    }
}
