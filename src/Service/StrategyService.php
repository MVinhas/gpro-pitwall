<?php

declare(strict_types=1);

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
     * @return array<string, mixed>
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
        $trackId = $trackData['id'];
        $stmt = $this->db->prepare("SELECT * FROM tracks WHERE id = :id OR name = :name");
        $stmt->execute([':id' => $trackId, ':name' => $trackData['name'] ?? '']);

        $trackDb = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$trackDb) {
            return ['error' => 'Track not found in DB. Please re-seed tracks.'];
        }


        $laps = (int)($inputs['laps'] ?? $trackDb['laps']);
        $targetWear = (int)($inputs['target_wear']);
        $temp = (float)$inputs['temp'];
        $risk = (int)($inputs['risk']);

        // Boost lap stints: 0..3. Each stint runs 3 boost laps (richer
        // engine map). Total boost laps = stints × 3, capped 0..9.
        // Extra fuel per the GPRO formula: laps × lap_length × boost_coeff,
        // ceil-rounded; spread evenly across race stints below.
        $boostStints = max(0, min(3, (int) ($inputs['boost_stints'] ?? 0)));
        $boostLaps = $boostStints * 3;
        $boostCoeffDry = (float) ($trackDb['boost_dry'] ?? 0.0);
        $boostCoeffWet = (float) ($trackDb['boost_wet'] ?? $boostCoeffDry);


        $trackTotalDist = (float)$trackDb['distance'];
        $trackTotalLaps = (int)$trackDb['laps'];
        $lapLen = ($trackTotalLaps > 0) ? ($trackTotalDist / $trackTotalLaps) : 4.5;


        $fplBaseDry = (float)$trackDb['fuel_per_lap'];
        $fplBaseWet = (float)$trackDb['fuel_per_lap_wet'];

        $ff = $this->secrets['fuel_factors'] ?? [];

        $dConc = (float)($driver['concentration']);
        $dAgg  = (float)($driver['aggressiveness']);
        $dExp  = (float)($driver['experience']);
        $dTe   = (float)($driver['technical_insight']);
        $cEng  = (int)($carData['lvlEngine']);
        $cEle  = (int)($carData['lvlElectronics']);

        $fuelAdj = ($dConc * ($ff['conc'])) +
                   ($dAgg  * ($ff['agg'])) +
                   ($dExp  * ($ff['exp'])) +
                   ($dTe   * ($ff['te'])) +
                   ($cEng  * ($ff['eng_lvl'])) +
                   ($cEle  * ($ff['ele_lvl'])) +
                   ($ff['constant']);

        $lkmDry = max(0.1, $fplBaseDry + $fuelAdj);
        $lkmWet = max(0.1, $fplBaseWet + $fuelAdj);

        $simulatedDistance = $laps * $lapLen;

        $totalFuelDry = $simulatedDistance * $lkmDry;
        $totalFuelWet = $simulatedDistance * $lkmWet;


        $tyreSecrets = $this->secrets['tyre_calc'] ?? [];

        $factors = $tyreSecrets['factors'] ?? [];


        $trackWearKey = (string)($trackDb['tyre_wear'] ?? 'Medium');
        $trackWearVal = $tyreSecrets['track_wear_values'][$trackWearKey] ?? 2.0;
        $f_Track = ($factors['track_wear']) ** $trackWearVal;

        $f_Temp = ($factors['avg_temp']) ** $temp;


        $supplierMap = $this->secrets['tyre_suppliers_durabilities'] ?? [];
        $supplierId = $supplierMap[$supplierName] ?? 1;
        $f_Dur = ($factors['tyre_durability']) ** $supplierId;


        $cSusp = (int)($carData['lvlSusp']);
        $f_Susp = ($factors['suspension']) ** $cSusp;

        $f_Agg = ($factors['aggressiveness']) ** $dAgg;
        $f_Exp = ($factors['experience']) ** $dExp;

        $dWgt = (float)($driver['weight']);
        $f_Wgt = ($factors['weight']) ** $dWgt;

        $factors_val = $f_Track * $f_Temp * $f_Dur * $f_Susp * $f_Agg * $f_Exp * $f_Wgt;

        $compounds = ['Extra Soft', 'Soft', 'Medium', 'Hard', 'Rain'];
        $tyreResults = [];

        foreach ($compounds as $comp) {
            $typeVal = $tyreSecrets['tyre_type_values'][$comp];
            $f_Type = ($factors['tyre_type_base']) ** $typeVal;

            $riskBase = $tyreSecrets['tyre_risk_factors'][$comp];
            $f_Risk = $riskBase ** $risk;

            $tyreWearMultiplier = $factors_val * $f_Type * $f_Risk;

            $trackBaseWear = (float)$trackDb['tyre_wear_factor'];
            $rainMod = ($comp === 'Rain') ? 0.73 : 1.0;
            $constant = $tyreSecrets['base_wear_constant'];

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

            $fFuel = $hasTd ? ($pc['factor_fuel_td']) : ($pc['factor_fuel_no_td']);
            $fSConc = $hasTd ? ($pc['factor_staff_conc_td']) : ($pc['factor_staff_conc_no_td']);
            $fSStress = $hasTd ? ($pc['factor_staff_stress_td']) : ($pc['factor_staff_stress_no_td']);
            $baseTime = $pc['base_time'];

            $pitTime = ($fuelPerStint * $fFuel)
                     + $baseTime
                     + ($vStaffConc * $fSConc)
                     + ($vStaffStress * $fSStress)
                     + ($vTdExp * ($pc['factor_td_exp']))
                     + ($vTdPit * ($pc['factor_td_pit']));

            $pitTime = max(15.0, $pitTime);


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

            // Per-lap fuel cost already includes the car+driver adjustment;
            // 'fuel_recommended' is the minimum-per-stint plus one extra
            // lap's worth, ceil-rounded once. Gives the user a 1-lap
            // safety net without re-running on a different worst case.
            $fuelPerLapAdj = (($comp === 'Rain') ? $lkmWet : $lkmDry) * $lapLen;

            // Boost laps add extra fuel. We don't know which race stint will
            // host them, so spread evenly across stints — gives the manager
            // enough buffer regardless of when they actually press the boost.
            $boostCoeff = ($comp === 'Rain') ? $boostCoeffWet : $boostCoeffDry;
            $boostExtraTotal = ($boostLaps > 0 && $boostCoeff > 0 && $lapLen > 0)
                ? $boostLaps * $lapLen * $boostCoeff
                : 0.0;
            $boostExtraPerStint = $boostExtraTotal / ($stops + 1);
            $stintFuelInclusive = $fuelPerStint + $boostExtraPerStint;

            $tyreResults[$comp] = [
                'stops' => $stops,
                'laps_set' => ($stops > 0 && $lapsPerSetForced < $lapsPerSet) ? $lapsPerSetForced : $lapsPerSet,
                'fuel_load' => ceil($stintFuelInclusive),
                'fuel_recommended' => ceil($stintFuelInclusive + $fuelPerLapAdj),
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
