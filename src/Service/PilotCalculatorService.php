<?php

declare(strict_types=1);

namespace App\Service;

class PilotCalculatorService
{
    public function __construct(
        private readonly array $factors,
        private readonly array $caps
    ) {
    }

    /**
     * @param array<string, mixed> $pilotData
     */
    public function calculateOverall(array $pilotData): float
    {
        $overall = 0.0;
        foreach ($this->factors as $column => $factor) {
            // Only calculate if the attribute exists in the pilot data
            if (isset($pilotData[$column])) {
                $overall += (int)$pilotData[$column] * $factor;
            }
        }

        return $overall;
    }

    /**
     * @param array<string, mixed> $pilotData
     */
    public function adjustPilotStats(array $pilotData, string $division): array
    {
        $cap = $this->caps[$division] ?? 999;
        $currentOA = $this->calculateOverall($pilotData);

        // If pilot is already under the cap, return as-is
        if ($currentOA <= $cap) {
            return $pilotData;
        }

        $adjusted = $pilotData;
        $reductionNeeded = $currentOA - $cap;

        // 1. Reduce Motivation first (Cheapest/Easiest stat to lose)
        $motStat = (int)($pilotData['motivation'] ?? 0);
        $motFactor = $this->factors['motivation'] ?? 0.0;

        if ($motFactor > 0 && $motStat > 0) {
            $maxMotReduct = $motStat * $motFactor;

            if ($reductionNeeded <= $maxMotReduct) {
                // We can achieve the cap just by reducing motivation
                $newMot = $motStat - ($reductionNeeded / $motFactor);
                $adjusted['motivation'] = max(0, (int)round($newMot));
                return $adjusted;
            }

            // Cap motivation at 0 and calculate remaining reduction needed
            $reductionNeeded -= $maxMotReduct;
            $adjusted['motivation'] = 0;
        }

        if ($reductionNeeded <= 0) {
            return $adjusted;
        }

        // 2. Reduce secondary stats proportionally
        // We define adjustable keys here (Talent and Weight are usually fixed or handled differently)
        $adjustableKeys = [
            'concentration',
            'aggressiveness',
            'experience',
            'technical_insight',
            'stamina',
            'charisma'
        ];

        $currentSecondaryContribution = 0.0;

        foreach ($adjustableKeys as $key) {
            if (isset($pilotData[$key])) {
                $currentSecondaryContribution += ((int)$pilotData[$key] * ($this->factors[$key] ?? 0.0));
            }
        }

        if ($currentSecondaryContribution > 0) {
            // Calculate scale factor (e.g., 0.95 to reduce everything by 5%)
            $scale = max(0.0, 1.0 - ($reductionNeeded / $currentSecondaryContribution));

            foreach ($adjustableKeys as $key) {
                if (isset($pilotData[$key])) {
                    $adjusted[$key] = max(0, (int)round((int)$pilotData[$key] * $scale));
                }
            }
        }

        return $adjusted;
    }
}
