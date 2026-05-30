<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Reads the GPRO RaceSetup 'weather' block into a simple rain assessment:
 * whether Q1/Q2 are wet and the race's average rain probability.
 *
 * The race-start wet call uses the *unrounded* average so it matches the
 * threshold exactly; race_rain_avg is rounded for display only.
 */
final class RaceWeatherService
{
    /** Race quarters carrying rain-probability low/high pairs. */
    private const array RACE_QUARTERS = ['Q1', 'Q2', 'Q3', 'Q4'];

    /** Average rain probability (%) at or above which the race starts wet. */
    public const int WET_THRESHOLD = 50;

    /**
     * @param array<string, mixed> $w the RaceSetup 'weather' sub-array
     * @return array{q1_wet: bool, q2_wet: bool, race_rain_avg: float, race_start_wet: bool}
     */
    public function assess(array $w): array
    {
        $q1Wet = isset($w['q1Weather']) && stripos((string) $w['q1Weather'], 'Rain') !== false;
        $q2Wet = isset($w['q2Weather']) && stripos((string) $w['q2Weather'], 'Rain') !== false;

        $sum = 0.0;
        foreach (self::RACE_QUARTERS as $q) {
            $sum += (float) ($w["race{$q}RainPLow"] ?? 0) + (float) ($w["race{$q}RainPHigh"] ?? 0);
        }
        // Two probability readings (low + high) per quarter.
        $avg = $sum / (count(self::RACE_QUARTERS) * 2);

        return [
            'q1_wet'         => $q1Wet,
            'q2_wet'         => $q2Wet,
            'race_rain_avg'  => round($avg, 1),
            'race_start_wet' => $avg >= self::WET_THRESHOLD,
        ];
    }
}
