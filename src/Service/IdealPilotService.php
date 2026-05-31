<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PilotRepository;

class IdealPilotService
{
    /** @param array<string, string> $statsSchema */
    public function __construct(
        private readonly PilotRepository $pilotRepo,
        private readonly PilotCalculatorService $calculator,
        private readonly array $statsSchema
    ) {
    }

    /** @return array{stats: array<string, mixed>, count: int} */
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


            $totalOverall += $this->calculator->calculateOverall($pilot);
        }

        $averageStats = [];
        foreach ($this->statsSchema as $display => $col) {
            $val = $totalStats[$col] / $count;
            $averageStats[$display] = ($col === 'age' || $col === 'weight')
                ? round($val)
                : round($val, 0);
        }


        $averageStats = array_merge(
            ['Overall Ability' => round($totalOverall / $count, 1)],
            $averageStats
        );

        return ['stats' => $averageStats, 'count' => $count];
    }
}
