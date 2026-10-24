<?php

declare(strict_types=1);

namespace App\Sync;

final readonly class SyncPlan
{
    /**
     * @param list<array{endpoint: string, params: string}> $phase1
     * @param list<array{endpoint: string, params: string}> $phase2
     */
    public function __construct(
        public array $phase1,
        public array $phase2,
    ) {
    }
}
