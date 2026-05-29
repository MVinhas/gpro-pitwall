<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Extra fuel cost of running boost laps, per the GPRO formula:
 *   extra_fuel = ROUNDUP(boost_laps * lap_length_km * coeff)
 * where coeff is the track's dry/wet boost coefficient (Tracks AR/AS).
 *
 * Boost is decided per stint; up to MAX_SETS_PER_RACE sets per race.
 */
final class BoostFuelService
{
    public const int MAX_SETS_PER_RACE = 3;

    public function extraFuel(int $boostLaps, float $lapLengthKm, float $coeff): int
    {
        if ($boostLaps <= 0 || $lapLengthKm <= 0.0 || $coeff <= 0.0) {
            return 0;
        }

        return (int) ceil($boostLaps * $lapLengthKm * $coeff);
    }

    /**
     * Extra fuel for 1..MAX_SETS_PER_RACE boost laps.
     *
     * @return array<int, int> laps => extra litres
     */
    public function costTable(float $lapLengthKm, float $coeff): array
    {
        $table = [];
        for ($n = 1; $n <= self::MAX_SETS_PER_RACE; $n++) {
            $table[$n] = $this->extraFuel($n, $lapLengthKm, $coeff);
        }

        return $table;
    }
}
