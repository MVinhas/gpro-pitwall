<?php

namespace App\Service;

use PDO;

class StrategyService
{
    /**
     * @param array<string, mixed> $secrets
     */
    public function __construct(private readonly PDO $db, private array $secrets)
    {
    }

    /**
     * @param array<string, mixed> $trackData
     * @param array<string, mixed> $carData
     * @param array<string, mixed> $driver
     * @param array<string, mixed> $staff
     * @param array<string, mixed> $td
     * @param array<string, mixed> $inputs
     */
    public function calculateStrategy(
        array $trackData,
        array $carData,
        array $driver,
        array $staff,
        array $td,
        array $inputs,
        string $supplierName = 'Pipirelli'
    ): array {
        $trackId = $trackData['id'] ?? 0;
        $stmt = $this->db->prepare("SELECT * FROM tracks WHERE id = :id OR name = :name");
        $stmt->execute([':id' => $trackId, ':name' => $trackData['name'] ?? '']);

        $trackDb = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$trackDb) {
            return ['error' => 'Track not found in DB. Please re-seed tracks.'];
        }

        // --- 1. INPUTS ---
        $laps = (int)($inputs['laps'] ?? $trackDb['laps']);
        $targetWear = (int)($inputs['target_wear'] ?? 15);
        $temp = (float)$inputs['temp'];
        $risk = (int)($inputs['risk'] ?? 0);

        // Calculate Lap Length (km)
        $trackTotalDist = (float)$trackDb['distance'];
        $trackTotalLaps = (int)$trackDb['laps'];
        $lapLen = ($trackTotalLaps > 0) ? ($trackTotalDist / $trackTotalLaps) : 4.5;

        // --- 2. FUEL CALCULATION ---
        $fplBaseDry = (float)$trackDb['fuel_per_lap'];
        $fplBaseWet = (float)$trackDb['fuel_per_lap_wet'];

        $ff = $this->secrets['fuel_factors'] ?? [];

        $dConc = (float)($driver['concentration'] ?? 0);
        $dAgg  = (float)($driver['aggressiveness'] ?? 0);
        $dExp  = (float)($driver['experience'] ?? 0);
        $dTe   = (float)($driver['technical_insight'] ?? 0);
        $cEng  = (int)($carData['lvlEngine'] ?? 1);
        $cEle  = (int)($carData['lvlElectronics'] ?? 1);

        $fuelAdj = ($dConc * ($ff['conc'] ?? 0)) +
                   ($dAgg  * ($ff['agg']  ?? 0)) +
                   ($dExp  * ($ff['exp']  ?? 0)) +
                   ($dTe   * ($ff['te']   ?? 0)) +
                   ($cEng  * ($ff['eng_lvl'] ?? 0)) +
                   ($cEle  * ($ff['ele_lvl'] ?? 0)) +
                   ($ff['constant'] ?? 0);

        $lkmDry = max(0.1, $fplBaseDry + $fuelAdj);
        $lkmWet = max(0.1, $fplBaseWet + $fuelAdj);

        $simulatedDistance = $laps * $lapLen;

        $totalFuelDry = $simulatedDistance * $lkmDry;
        $totalFuelWet = $simulatedDistance * $lkmWet;

        // --- 3. TYRE CALCULATION ---
        $tyreSecrets = $this->secrets['tyre_calc'] ?? [];

        $factors = $tyreSecrets['factors'] ?? [];

        // Track Wear
        $trackWearKey = (string)($trackDb['tyre_wear'] ?? 'Medium');
        $trackWearVal = $tyreSecrets['track_wear_values'][$trackWearKey] ?? 2.0;
        $f_Track = ($factors['track_wear'] ?? 0.896) ** $trackWearVal;

        $f_Temp = ($factors['avg_temp'] ?? 0.988) ** $temp;

        // Supplier
        $supplierMap = $this->secrets['tyre_suppliers_durabilities'] ?? [];
        $supplierId = $supplierMap[$supplierName] ?? 1;
        $f_Dur = ($factors['tyre_durability'] ?? 1.049) ** $supplierId;

        // Car/Driver
        $cSusp = (int)($carData['lvlSusp'] ?? 1);
        $f_Susp = ($factors['suspension'] ?? 1.009) ** $cSusp;

        $f_Agg = ($factors['aggressiveness'] ?? 0.999) ** $dAgg;
        $f_Exp = ($factors['experience'] ?? 1.000) ** $dExp;

        $dWgt = (float)($driver['weight'] ?? 80);
        $f_Wgt = ($factors['weight'] ?? 0.999) ** $dWgt;

        $factors_val = $f_Track * $f_Temp * $f_Dur * $f_Susp * $f_Agg * $f_Exp * $f_Wgt;

        $compounds = ['Extra Soft', 'Soft', 'Medium', 'Hard', 'Rain'];
        $tyreResults = [];

        foreach ($compounds as $comp) {
            $typeVal = $tyreSecrets['tyre_type_values'][$comp] ?? 0;
            $f_Type = ($factors['tyre_type_base'] ?? 1.355) ** $typeVal;

            $riskBase = $tyreSecrets['tyre_risk_factors'][$comp] ?? 0.998;
            $f_Risk = $riskBase ** $risk;

            $tyreWearMultiplier = $factors_val * $f_Type * $f_Risk;

            $trackBaseWear = (float)$trackDb['tyre_wear_factor'];
            $rainMod = ($comp === 'Rain') ? 0.73 : 1.0;
            $constant = $tyreSecrets['base_wear_constant'] ?? 129.776;

            $maxKm = $tyreWearMultiplier * $trackBaseWear * $constant * $rainMod;

            $usableKm = $maxKm * ((100 - $targetWear) / 100);

            $lapsPerSet = $usableKm / $lapLen;
            if ($lapsPerSet < 1) {
                $lapsPerSet = 1;
            }

            $stops = (int)ceil($laps / $lapsPerSet) - 1;
            if ($stops < 0) {
                $stops = 0;
            }

            // --- FUEL PER STINT ---
            $relevantTotalFuel = ($comp === 'Rain') ? $totalFuelWet : $totalFuelDry;
            $fuelPerStint = $relevantTotalFuel / ($stops + 1);
            $maxFuel = 180.0;

            $safety = 0;
            while ($fuelPerStint > $maxFuel && $safety < $laps) {
                $stops++;
                $fuelPerStint = $relevantTotalFuel / ($stops + 1);
                $safety++;
            }

            $lapsPerSetForced = floor($laps / ($stops + 1));

            // --- PIT TIME ---
            $hasTd = false;
            if (isset($td['ownTD']) && $td['ownTD'] == 1) {
                $hasTd = true;
            } elseif (isset($td['id']) && is_numeric($td['id']) && $td['id'] > 0) {
                $hasTd = true;
            }

            $vStaffConc = is_numeric($staff['concentration'] ?? null) ? (float)$staff['concentration'] : 0.0;
            $vStaffStress = is_numeric($staff['stressHandling'] ?? null) ? (float)$staff['stressHandling'] : 0.0;

            $vTdExp = 0.0;
            $vTdPit = 0.0;
            if ($hasTd) {
                $vTdExp = is_numeric($td['experience'] ?? null) ? (float)$td['experience'] : 0.0;
                $rawPit = $td['pitCoordination'] ?? ($td['pitCoord'] ?? 0);
                $vTdPit = is_numeric($rawPit) ? (float)$rawPit : 0.0;
            }

            $pc = $this->secrets['pit_stop'] ?? [];

            $fFuel = $hasTd ? ($pc['factor_fuel_td'] ?? 0.0315) : ($pc['factor_fuel_no_td'] ?? 0.0355);
            $fSConc = $hasTd ? ($pc['factor_staff_conc_td'] ?? -0.0945) : ($pc['factor_staff_conc_no_td'] ?? -0.0797);
            $fSStress = $hasTd ? ($pc['factor_staff_stress_td'] ?? -0.0355) : ($pc['factor_staff_stress_no_td'] ?? 0.0);
            $baseTime = $pc['base_time'] ?? 24.26;

            $pitTime = ($fuelPerStint * $fFuel)
                     + $baseTime
                     + ($vStaffConc * $fSConc)
                     + ($vStaffStress * $fSStress)
                     + ($vTdExp * ($pc['factor_td_exp'] ?? -0.0094))
                     + ($vTdPit * ($pc['factor_td_pit'] ?? -0.0112));

            $pitTime = max(15.0, $pitTime);

            // --- LOST TIME ---
            $pitLaneLoss = (float)$trackDb['pit_time'];
            $lostPits = $stops * ($pitTime + $pitLaneLoss);

            $fuel_per_lap_val =
                ($comp === 'Rain') ? (float)$trackDb['fuel_per_lap_wet']
                :
                (float)$trackDb['fuel_per_lap'];

            $tables_h47 =
                $ff['conc']
                * $dConc
                + $ff['agg']
                * $dAgg
                + $ff['exp']
                * $dExp
                + $ff['te']
                * $dTe
                + $ff['eng_lvl']
                * $cEng
                + $ff['ele_lvl']
                * $cEle;

            $fuelCost = 0.005 * (
                ((float)$trackDb['distance'] * ($fuel_per_lap_val + $tables_h47))
                * (float)$trackDb['distance'] / ($stops + 1)
            ) / 2;

            $tcdVal = 0.0;
            $tcdDiff = $tyreSecrets['tyre_compound_difference'][$supplierName] ?? 0.0;

            $lostTcd =
                $laps
                * ((int)$trackDb['corners']
                * (float)$trackDb['lap_length']
                * 0.00018
                * (50 - $temp)
                + $tcdDiff);

            if ($comp === 'Soft') {
                $tcdVal = $lostTcd;
            } elseif ($comp === 'Medium') {
                $tcdVal = $lostTcd * 2;
            } elseif ($comp === 'Hard') {
                $tcdVal = $lostTcd * 3;
            }

            $tyreResults[$comp] = [
                'stops' => $stops,
                'laps_set' => ($stops > 0 && $lapsPerSetForced < $lapsPerSet) ? $lapsPerSetForced : $lapsPerSet,
                'fuel_load' => ceil($fuelPerStint),
                'pit_time_est' => round($pitTime, 2),
                'lost_pits' => round($lostPits, 2),
                'lost_fuel' => round($fuelCost, 2),
                'lost_tcd' => round($tcdVal, 2),
                'total_lost' => round($lostPits + $fuelCost + $tcdVal, 2)
            ];
        }

        return [
            'track' => $trackDb['name'],
            'fuel' => [
                'dry' => ceil($totalFuelDry),
                'wet' => ceil($totalFuelWet),
                'l_per_lap' => round($lapLen * $lkmDry, 2)
            ],
            'tyres' => $tyreResults,
            'supplier' => $supplierName,
            'stats' => [
                'driver' => $driver,
                'car' => $carData,
                'staff' => $staff,
                'td' => $td
            ],
            'inputs' => $inputs
        ];
    }
}
