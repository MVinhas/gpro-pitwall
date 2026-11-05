<?php

declare(strict_types=1);

namespace App\Engineer;

final class SmokeCost
{
    /** A smoking part costs about 50 points of pace per lap; a break forfeits about half the race prize. */
    private const int SMOKE_POINTS = 50;
    private const float BREAK_PRIZE_SHARE = 0.5;

    /** @return array{time: int, break: int, total: int} */
    public static function price(
        SmokeRisk $risk,
        float $secondsPerPointPerLap,
        float $secondsPerPlace,
        int $perPlace,
        int $racePrize,
    ): array {
        $time = $secondsPerPlace > 0
            ? (int) round($risk->smokeLaps * self::SMOKE_POINTS * $secondsPerPointPerLap / $secondsPerPlace * $perPlace)
            : 0;
        $break = (int) round($risk->breakChance * self::BREAK_PRIZE_SHARE * $racePrize);

        return ['time' => $time, 'break' => $break, 'total' => $time + $break];
    }
}
