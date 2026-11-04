<?php

declare(strict_types=1);

namespace App\Engineer;

final class CtrPace
{
    /**
     * Lap time bought back per point of Clear Track Risk. The gain is a fraction of lap time, not of
     * distance. A track without an average speed (Grobnik ships 0) has no lap time to take it from.
     */
    public static function secondsPerPointPerLap(float $lapLengthKm, float $avgSpeedKmh, float $fraction): float
    {
        if ($avgSpeedKmh <= 0.0) {
            return 0.0;
        }

        return ($lapLengthKm / $avgSpeedKmh) * 3600.0 * $fraction;
    }
}
