<?php
namespace App\Service;

class PilotCalculatorService
{
    public function __construct(
        private array $factors,
        private array $caps
    ) {}

    public function calculateOverall(array $pilotData): float
    {
        $overall = 0.0;
        foreach ($this->factors as $column => $factor) {
            if (isset($pilotData[$column])) {
                $overall += (int)$pilotData[$column] * $factor;
            }
        }
        return $overall;
    }

    public function adjustPilotStats(array $pilotData, string $division): array
    {
        $cap = $this->caps[$division] ?? 999;
        $currentOA = $this->calculateOverall($pilotData);

        if ($currentOA <= $cap) {
            return $pilotData;
        }

        $adjusted = $pilotData;
        $reductionNeeded = $currentOA - $cap;

        // 1. Reduce Motivation first
        $motStat = $pilotData['motivation'] ?? 0;
        $motFactor = $this->factors['motivation'] ?? 0;

        if ($motFactor > 0 && $motStat > 0) {
            $maxMotReduct = $motStat * $motFactor;
            if ($reductionNeeded <= $maxMotReduct) {
                $newMot = $motStat - ($reductionNeeded / $motFactor);
                $adjusted['motivation'] = max(0, (int)round($newMot));
                return $adjusted;
            }
            $reductionNeeded -= $maxMotReduct;
            $adjusted['motivation'] = 0;
        }

        if ($reductionNeeded <= 0) return $adjusted;

        // 2. Reduce other stats proportionally
        $adjustableKeys = ['concentration', 'aggressiveness', 'experience', 'technical_insight', 'stamina', 'charisma'];
        $currentSecondaryContribution = 0;

        foreach ($adjustableKeys as $key) {
            if (isset($pilotData[$key])) {
                $currentSecondaryContribution += ($pilotData[$key] * ($this->factors[$key] ?? 0));
            }
        }

        if ($currentSecondaryContribution > 0) {
            $scale = max(0.0, 1.0 - ($reductionNeeded / $currentSecondaryContribution));
            foreach ($adjustableKeys as $key) {
                if (isset($pilotData[$key])) {
                    $adjusted[$key] = max(0, (int)round($pilotData[$key] * $scale));
                }
            }
        }

        return $adjusted;
    }
}