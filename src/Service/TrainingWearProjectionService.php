<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Answers "if I train N laps and then race, where does my wear end up?".
 *
 * Chains the two existing calculators rather than reinventing either: training
 * laps age the car at the testing per-lap rate (CarWearService::testingWearRates),
 * and the race is then projected from that already-aged car. The within-limits
 * verdict reuses WearAdvisorService's thresholds — there is deliberately no
 * second set of limits in the codebase.
 */
final readonly class TrainingWearProjectionService
{
    /** GPRO caps a training/testing session at 100 laps. */
    public const int MAX_LAPS = 100;

    public function __construct(
        private CarWearService $wear,
        private WearAdvisorService $advisor,
    ) {
    }

    /**
     * @param array<string, mixed> $trackData
     * @param array<string, mixed> $carData
     * @param array<string, mixed> $driver
     * @return array<string, mixed>
     */
    public function project(
        array $trackData,
        array $carData,
        array $driver,
        int $risk,
        int $trainingLaps,
    ): array {
        $trainingLaps = max(0, min(self::MAX_LAPS, $trainingLaps));

        $rates = $this->wear->testingWearRates($trackData, $carData, $driver);
        if (isset($rates['error'])) {
            return ['error' => $rates['error']];
        }

        $trainingWear = [];
        foreach (CarWearService::PARTS_MAP as $label => $map) {
            $perLap = (float) ($rates['parts'][$label]['per_lap'] ?? 0.0);
            $trainingWear[$label] = round($perLap * $trainingLaps, 1);
        }

        // The race projection is run against the *unaged* car and its training
        // wear added afterwards, rather than feeding an aged car back through
        // calculateWear(). Start wear is an integer percentage there, so a
        // round-trip would silently truncate the training fraction — and the
        // race increment itself doesn't depend on start wear anyway.
        $race = $this->wear->calculateWear($trackData, $carData, $driver, $risk);
        if (isset($race['error'])) {
            return ['error' => $race['error']];
        }

        $parts = [];
        foreach ($race['parts'] as $label => $row) {
            $training = $trainingWear[$label] ?? 0.0;
            $raceWear = (float) $row['est'];

            $parts[$label] = [
                'level'    => $row['level'],
                'start'    => (int) $row['start'],
                'training' => $training,
                'race'     => $raceWear,
                // WearAdvisorService::classify() reads `est` as "what happens
                // between now and the flag". Here that is training plus race.
                'est'      => round($training + $raceWear, 1),
                'end'      => round((float) $row['start'] + $training + $raceWear, 1),
            ];
        }

        $advisor = $this->advisor->classify($parts);

        return [
            'track_name'    => $race['track_name'],
            'laps'          => $race['laps'],
            'training_laps' => $trainingLaps,
            'parts'         => $parts,
            'advisor'       => $advisor,
            'within_limits' => $advisor['swap'] === [] && $advisor['risky'] === [],
        ];
    }
}
