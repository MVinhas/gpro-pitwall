<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Forecasts where the car's PHA will land 3 races from now if a full
 * test session is run today, per GPRO research priority.
 *
 * GPRO test points decay through the pipeline:
 *   Test Points → R&D → Engineering → Car Character (tPower/tHandl/tAccel)
 * losing roughly half their value at each step, so only ~decay^3 of
 * the raw test gain actually lands in the car's PHA after race + 3.
 *
 * The advisor in v1 uses this read-only — the swap advisor still ranks
 * options against the *current* car, not the projected one.
 */
final class TestingProjectionService
{
    /** Number of races the test points take to land in `tPower/Handl/Accel`. */
    private const int RACES_TO_LAND = 3;

    /** GPRO testing page caps a session at 100 laps. */
    private const int MAX_LAPS = 100;

    /** Each priority row in the GPRO testing table is per 5 laps. */
    private const int TABLE_LAPS = 5;

    /**
     * @param array<string, array{power: float|int, handling: float|int, acceleration: float|int}> $priorityPoints
     */
    public function __construct(
        private readonly array $priorityPoints,
        private readonly float $decayFactor,
    ) {
    }

    /**
     * @param array{power: int|float, handling: int|float, acceleration: int|float} $car
     * @param int $laps total test laps (capped at MAX_LAPS, floored at 0)
     * @return array<string, array{
     *   priority: string,
     *   raw:    array{power: float, handling: float, acceleration: float},
     *   landed: array{power: float, handling: float, acceleration: float},
     *   new_car: array{power: float, handling: float, acceleration: float},
     * }>
     */
    public function project(array $car, int $laps = self::MAX_LAPS): array
    {
        $laps = max(0, min(self::MAX_LAPS, $laps));
        $netSurviving = $this->decayFactor ** self::RACES_TO_LAND;
        $scale = $laps / self::TABLE_LAPS;

        $out = [];
        foreach ($this->priorityPoints as $name => $points) {
            $raw = [
                'power'        => (float) $points['power']        * $scale,
                'handling'     => (float) $points['handling']     * $scale,
                'acceleration' => (float) $points['acceleration'] * $scale,
            ];
            $landed = [
                'power'        => $raw['power']        * $netSurviving,
                'handling'     => $raw['handling']     * $netSurviving,
                'acceleration' => $raw['acceleration'] * $netSurviving,
            ];
            $out[$name] = [
                'priority' => $name,
                'raw'      => $raw,
                'landed'   => $landed,
                'new_car'  => [
                    'power'        => (float) $car['power']        + $landed['power'],
                    'handling'     => (float) $car['handling']     + $landed['handling'],
                    'acceleration' => (float) $car['acceleration'] + $landed['acceleration'],
                ],
            ];
        }
        return $out;
    }
}
