<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Extra fuel cost of running boost laps, per the GPRO formula:
 *   extra_fuel = ROUNDUP(boost_laps * lap_length_km * coeff)
 * where coeff is the track's dry/wet boost coefficient (Tracks AR/AS).
 */
final class BoostFuelService
{
    public function extraFuel(int $boostLaps, float $lapLengthKm, float $coeff): int
    {
        if ($boostLaps <= 0 || $lapLengthKm <= 0.0 || $coeff <= 0.0) {
            return 0;
        }

        return (int) ceil($boostLaps * $lapLengthKm * $coeff);
    }
}
