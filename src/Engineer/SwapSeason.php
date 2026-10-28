<?php

declare(strict_types=1);

namespace App\Engineer;

/**
 * The season a swap is judged over: the objective, the plan the swap is compared against, and the inputs that
 * plan was forecast from.
 *
 * @phpstan-import-type Plan from CtrPlanner
 * @phpstan-import-type Round from SeasonBudget
 * @phpstan-import-type Demand from UpgradeRanker
 */
final readonly class SwapSeason
{
    /**
     * @param Plan $plan
     * @param list<Round> $rounds the remaining races the plan was built from
     * @param array<int, ?Demand> $demands PHA demand by race number; null when the track is unknown
     * @param array<string, array{level: int, start: int|float, training?: int|float}> $parts
     * @param array<string, ?int> $prices
     */
    public function __construct(
        public string $objective,
        public array $plan,
        public array $rounds,
        public array $demands,
        public array $parts,
        public array $prices,
        public float $driverFactor,
        public int $cash,
        public int $committed,
        public int $income,
        public StrengthFloor $floor,
    ) {
    }
}
