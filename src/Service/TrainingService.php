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
     * * @param array $currentStats Pilot stats ['stamina' => 10, ...]
     * @param string $trainingName 'Fitness', 'Yoga', etc.
     * @return array New stats and cost
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

    public function getAllTrainings(): array
    {
        return $this->db->query("SELECT * FROM trainings")->fetchAll(PDO::FETCH_ASSOC);
    }
}
