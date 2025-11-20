<?php
namespace App\Service;

use App\Repository\PilotRepository;

class IdealPilotService
{
    public function __construct(
        private PilotRepository $pilotRepo,
        private PilotCalculatorService $calculator,
        private array $statsSchema
    ) {}

    public function getIdealPilot(string $division): array
    {
        $pilots = $this->pilotRepo->getPilotsByDivision($division);
        $count = count($pilots);

        if ($count === 0) {
            return ['stats' => [], 'count' => 0];
        }

        $columnNames = array_values($this->statsSchema);
        $totalStats = array_fill_keys($columnNames, 0);
        $totalOverall = 0.0;

        foreach ($pilots as $pilot) {
            foreach ($columnNames as $col) {
                $totalStats[$col] += (int)$pilot[$col];
            }
            // Calculate OA for this specific pilot row
            $totalOverall += $this->calculator->calculateOverall($pilot);
        }

        $averageStats = [];
        foreach ($this->statsSchema as $display => $col) {
            $val = $totalStats[$col] / $count;
            $averageStats[$display] = ($col === 'age' || $col === 'weight')
                ? round($val)
                : round($val, 0);
        }

        // Add Average Overall Ability
        $averageStats = array_merge(
            ['Overall Ability' => round($totalOverall / $count, 1)],
            $averageStats
        );

        return ['stats' => $averageStats, 'count' => $count];
    }
}