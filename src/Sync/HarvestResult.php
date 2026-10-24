<?php

declare(strict_types=1);

namespace App\Sync;

final readonly class HarvestResult
{
    /**
     * @param string $status done | more | busy | locked
     * @param int $calls GPRO fetches attempted, failed ones included
     * @param int $projected races projected out of the archive
     * @param int $ms wall time of the slice
     */
    public function __construct(
        public string $status,
        public int $calls = 0,
        public int $projected = 0,
        public int $ms = 0,
    ) {
    }
}
