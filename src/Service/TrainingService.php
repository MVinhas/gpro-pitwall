<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

class TrainingService
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Predicts the new stats of a pilot after a specific training.
     *
     * @param array<string, mixed> $currentStats Pilot stats ['stamina' => 10, ...]
     * @param string $trainingName 'Fitness', 'Yoga', etc.
     * @return array<string, mixed> New stats and cost
     */
    public function predictResult(array $currentStats, string $trainingName): array
    {

        $stmt = $this->db->prepare("SELECT * FROM trainings WHERE name = :name");
        $stmt->execute([':name' => $trainingName]);
        $training = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$training) {
            return $currentStats;
        }

        $newStats = $currentStats;
        $cost = $training['cost'];

        $keys = [
            'concentration', 'talent', 'aggressiveness', 'experience',
            'technical_insight', 'stamina', 'charisma', 'motivation', 'weight'
        ];

        foreach ($keys as $key) {
            $dbCol = 'gain_' . $key;
            $gain = $training[$dbCol] ?? 0;


            if (isset($newStats[$key])) {
                $newStats[$key] += $gain;


                if ($newStats[$key] < 0) {
                    $newStats[$key] = 0;
                }
            }
        }

        return [
            'stats' => $newStats,
            'cost' => $cost,
            'diff' => $training
        ];
    }

    /** @return list<array<string, mixed>> */
    public function getAllTrainings(): array
    {
        $stmt = $this->db->query("SELECT * FROM trainings");
        if ($stmt === false) {
            return [];
        }
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    /**
     * Projects the cumulative effect of a multi-program training plan.
     * Per-attribute deltas are summed across every (program × count)
     * combination first, then added to start stats and clamped once to
     * [0, 250]. This avoids the order-dependent bug where a negative
     * gain on a near-zero stat (e.g. Fitness −7.4 motivation when
     * motivation is 0) would otherwise be silently clipped before a
     * later positive gain (Psycho +16.7) raises it.
     *
     * @param array<string, int|float> $stats   driver's current attribute values
     * @param array<string, int|float> $schedule program-name → session count
     * @return array{
     *   start: array<string, int>,
     *   end:   array<string, int>,
     *   total_cost: int,
     *   per_program: list<array{name: string, count: int, cost: int, gains: array<string, float>}>
     * }
     */
    public function planSchedule(array $stats, array $schedule): array
    {
        $attrKeys = [
            'concentration', 'talent', 'aggressiveness', 'experience',
            'technical_insight', 'stamina', 'charisma', 'motivation', 'weight',
        ];

        $start = [];
        foreach ($attrKeys as $key) {
            $start[$key] = (int) ($stats[$key] ?? 0);
        }

        $totalDelta = array_fill_keys($attrKeys, 0.0);
        $totalCost  = 0;
        $perProgram = [];

        foreach ($schedule as $programName => $count) {
            $count = max(0, (int) $count);
            if ($count === 0) {
                continue;
            }

            $stmt = $this->db->prepare("SELECT * FROM trainings WHERE name = :name");
            $stmt->execute([':name' => (string) $programName]);
            $training = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$training) {
                continue;
            }

            $cost = (int) ($training['cost'] ?? 0) * $count;
            $totalCost += $cost;

            $gains = [];
            foreach ($attrKeys as $key) {
                $delta = (float) ($training['gain_' . $key] ?? 0) * $count;
                $totalDelta[$key] += $delta;
                if ($delta !== 0.0) {
                    $gains[$key] = $delta;
                }
            }

            $perProgram[] = [
                'name'  => (string) $programName,
                'count' => $count,
                'cost'  => $cost,
                'gains' => $gains,
            ];
        }

        $end = [];
        foreach ($attrKeys as $key) {
            $end[$key] = (int) max(0, min(250, round($start[$key] + $totalDelta[$key])));
        }

        return [
            'start'       => $start,
            'end'         => $end,
            'total_cost'  => $totalCost,
            'per_program' => $perProgram,
        ];
    }
}
