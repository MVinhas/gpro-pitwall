<?php

declare(strict_types=1);

namespace App\Engineer;

/**
 * @phpstan-import-type Round from SeasonBudget
 * @phpstan-import-type Demand from UpgradeRanker
 */
final class SeasonPha
{
    private const array ATTRIBUTES = ['power', 'handling', 'acceleration'];

    /**
     * The PHA $levels more (or fewer, when negative) of a part add over the remaining races, each race weighted
     * by its track's demand. A race whose demand is unknown, or all zero, adds nothing.
     *
     * @param Demand $contribution PHA points one level adds
     * @param list<Round> $rounds
     * @param array<int, ?Demand> $demands PHA demand by race number; null when the track is unknown
     */
    public static function gain(array $contribution, int $levels, array $rounds, array $demands): float
    {
        $gain = 0.0;
        foreach ($rounds as $round) {
            $demand = $demands[$round['race']] ?? null;
            if ($demand === null) {
                continue;
            }
            $total = 0.0;
            foreach (self::ATTRIBUTES as $attribute) {
                $total += $demand[$attribute];
            }
            if ($total <= 0) {
                continue;
            }
            foreach (self::ATTRIBUTES as $attribute) {
                $gain += $contribution[$attribute] * $levels * $demand[$attribute] / $total;
            }
        }

        return $gain;
    }
}
