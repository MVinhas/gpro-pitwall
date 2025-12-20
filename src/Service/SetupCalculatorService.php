<?php

namespace App\Service;

use PDO;

class SetupCalculatorService
{
    private array $secrets;

    /**
     * @param array<string, mixed> $secrets
     */
    public function __construct(private readonly PDO $db, array $secrets)
    {
        $this->secrets = $secrets['setup'] ?? [];
    }

    /**
     * @param array<string, mixed> $trackData
     * @param array<string, mixed> $driver
     * @param array<string, mixed> $weatherInputs
     */
    public function calculateSetups(array $trackData, array $carData, array $driver, array $weatherInputs): array
    {
        $trackId = $trackData['id'] ?? 0;
        $stmt = $this->db->prepare("SELECT * FROM tracks WHERE id = :id OR name = :name");
        $stmt->execute([':id' => $trackId, ':name' => $trackData['name'] ?? '']);
        $trackDb = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$trackDb) {
            return [];
        }

        $sessions = ['Q1', 'Q2', 'Race'];
        $results = [];

        // Helper for Special Tracks
        $trackName = $trackData['name'] ?? '';
        $isSpecialTrack = in_array($trackName, ['Indianapolis Oval', 'Rafaela Oval']);
        $trackMult = $isSpecialTrack ? ($this->secrets['driver']['special_track_mult'] ?? 0.39) : 1.0;

        foreach ($sessions as $session) {
            $weather = $weatherInputs[$session]['weather'] ?? 'Dry';
            $temp = round($weatherInputs[$session]['temp']);
            $isDry = ($weather === 'Dry');

            // --- 1. TRACK BASE & 2. WEATHER ---
            $components = [];
            foreach ($this->secrets['weather_coeffs'] as $part => $coeffs) {
                $baseVal = (float)$trackDb['base_' . strtolower((string) $part)];

                if ($isDry) {
                    $weaVal = $temp * $coeffs['dry'];
                } else {
                    $weaVal = ($temp * $coeffs['wet']) + $coeffs['wet_offset'];
                }

                if ($part === 'Wings') {
                    $baseVal *= 2;
                    $weaVal *= 2;
                }

                $components[$part] = [
                    'base' => $baseVal,
                    'wea' => $weaVal
                ];
            }

            // --- 3. CAR INFLUENCE ---
            $car = [];
            $getLvl  = fn($k): float => (float)($carData["lvl$k"] ?? 1);
            $getWear = fn($k): float => (float)($carData["usa$k"] ?? 0);

            foreach ($this->secrets['car'] as $part => $factors) {
                $sum = 0;
                if (isset($factors['lvl'])) {
                    foreach ($factors['lvl'] as $carPartName => $coeff) {
                        $sum += $getLvl($carPartName) * $coeff;
                    }
                }

                if (isset($factors['wear'])) {
                    foreach ($factors['wear'] as $carPartName => $coeff) {
                        $sum += $getWear($carPartName) * $coeff;
                    }
                }

                $car[$part] = $sum;
            }

            // --- 4. DRIVER INFLUENCE ---
            $dri = [];
            $dSec = $this->secrets['driver'];

            $wingsInput = floor($components['Wings']['base'] + $components['Wings']['wea']);
            $dri['Wings'] = ($driver['talent'] * $wingsInput * $dSec['wings_talent']) * $trackMult;

            $engInput = $components['Engine']['base'] + $components['Engine']['wea'];
            $engPart1 = $driver['aggressiveness'] * $dSec['eng_agg'] * $trackMult;
            $engPart2 = $driver['experience'] * ($engInput * $dSec['eng_exp_pow'] + $dSec['eng_exp_add']) * $trackMult;
            $dri['Engine'] = $engPart1 + $engPart2;

            $dri['Brakes'] = $driver['talent'] * $dSec['bra_talent'] * $trackMult;
            $dri['Gear'] = $driver['concentration'] * $dSec['gear_conc'] * $trackMult;
            $suspWet = (!$isDry) ? ($driver['technical_insight'] * $dSec['susp_tech']) : 0;
            $dri['Suspension'] = ($driver['experience'] * $dSec['susp_exp'] * $trackMult)
                               + ($driver['weight'] * $dSec['susp_wgt'])
                               + $suspWet;

            // --- 5. FINAL SUMMATION ---
            $s5 = [];
            foreach ($components as $part => $vals) {
                $total = $vals['base'] + $vals['wea'] + $car[$part] + $dri[$part];
                if ($part === 'Wings') {
                    $s5[$part] = $total / 2;
                } else {
                    $s5[$part] = $total;
                }
            }

            // --- 6. PERFECT SETUP ADJUSTMENTS ---
            $final = [];
            $fSec = $this->secrets['final'];

            $wingSplit = (float)$trackDb['wing_split'];
            $avgWingLvl = ($getLvl('FWing') + $getLvl('RWing')) / 2.0;
            $wetConst = (!$isDry) ? $fSec['wet_const'] : 0;

            $wingOffset = $wingSplit
                        + ($driver['talent'] * $fSec['a_talent'])
                        + ($fSec['b_wing_lvl'] * $avgWingLvl)
                        + ($s5['Wings'] * $fSec['c_step5'])
                        + ($temp * $fSec['d_temp'])
                        + $wetConst;

            $final['Front Wing'] = round($s5['Wings'] + $wingOffset);
            $final['Rear Wing']  = round($s5['Wings'] - $wingOffset);
            $final['Engine']     = round($s5['Engine']);
            $final['Brakes']     = round($s5['Brakes']);
            $final['Gearbox']    = round($s5['Gear']);
            $final['Suspension'] = round($s5['Suspension']);

            $results[$session] = $final;
        }

        return $results;
    }
}
