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
        string $supplierName = 'Pipirelli',
        ?int $supplierDurability = null
    ): array {
        // TrackProfile (next-race feed) carries no id — only the cockpit path
        // injects id => 0. Default to 0 so the name match still resolves the row.
        $trackId = $trackData['id'] ?? 0;
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

        // GPRO publishes no wet-consumption rule, so every wet rate is measured
        // race by race — and tracks that have never run a wet race have no
        // sample at all (0 in the CSV). Left alone, the max(0.1, …) clamp below
        // turns that into ~0.1 L/km and under-fuels the race roughly 7-fold.
        // The dry rate is the safe substitute: wet is always the lighter of the
        // two, so it over-fuels rather than stranding the car. Flagged to the
        // caller so the UI can say the number is an estimate.
        $wetRateEstimated = $fplBaseWet <= 0.0;
        if ($wetRateEstimated) {
            $fplBaseWet = $fplBaseDry;
        }

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


        // Supplier durability drives the wear exponent. The live API
        // (TyreSuppliers feed) is the source of truth — GPRO can re-tune
        // these per season. Fall back to the secrets snapshot only when the
        // caller couldn't resolve it from the API (feed unavailable).
        $durability = $supplierDurability
            ?? ($this->secrets['tyre_suppliers_durabilities'][$supplierName] ?? 1);
        $f_Dur = ($factors['tyre_durability']) ** $durability;


        $cSusp = (int)($carData['lvlSusp']);
        $f_Susp = ($factors['suspension']) ** $cSusp;

        $f_Agg = ($factors['aggressiveness']) ** $dAgg;
        $f_Exp = ($factors['experience']) ** $dExp;

        $dWgt = (float)($driver['weight']);
        $f_Wgt = ($factors['weight']) ** $dWgt;

        $factors_val = $f_Track * $f_Temp * $f_Dur * $f_Susp * $f_Agg * $f_Exp * $f_Wgt;

        $compounds = ['Extra Soft', 'Soft', 'Medium', 'Hard', 'Rain'];

        // Lap time bought back per point of Clear Track Risk. The gain is a
        // fraction of LAP TIME, not of distance — two measured tracks fix the
        // constant, and a per-km or per-corner law mispredicts the second one
        // badly (see config/secrets.php for the derivation).
        //
        // Both the constant and the track's average speed can be absent
        // (Grobnik ships avg_speed = 0), and either way there is no gain to
        // state — report none rather than inventing one.
        $ctrGainFraction = (float) ($tyreSecrets['clear_track_risk_gain_lap_fraction'] ?? 0.0);
        $trackAvgSpeed = (float) ($trackDb['avg_speed'] ?? 0.0);
        $lapTimeSec = $trackAvgSpeed > 0.0 ? ($lapLen / $trackAvgSpeed) * 3600.0 : 0.0;
        $ctrGainPerLap = $lapTimeSec * $ctrGainFraction;

        /**
         * Every compound's plan at one risk level. Extracted so the CTR sweep
         * below can re-run the identical maths at each risk instead of
         * approximating it — the whole point is telling the manager where the
         * plan actually flips.
         *
         * @return array<string, array<string, mixed>>
         */
        $planAtRisk = function (int $risk) use (
            $compounds,
            $tyreSecrets,
            $factors,
            $factors_val,
            $trackDb,
            $targetWear,
            $lapLen,
            $laps,
            $temp,
            $totalFuelWet,
            $totalFuelDry,
            $td,
            $staff,
            $supplierName,
            $ff,
            $dConc,
            $dAgg,
            $dExp,
            $dTe,
            $cEng,
            $cEle,
            $lkmWet,
            $lkmDry,
            $fplBaseWet,
            $fplBaseDry,
            $boostLaps,
            $boostCoeffWet,
            $boostCoeffDry,
            $ctrGainPerLap
        ): array {
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

            // Tyre life sets the floor: fewer stops than this and the set is
            // worn past target before the stint ends.
                $minStopsForWear = (int)ceil($laps / $lapsPerSet) - 1;
                if ($minStopsForWear < 0) {
                    $minStopsForWear = 0;
                }


                $relevantTotalFuel = ($comp === 'Rain') ? $totalFuelWet : $totalFuelDry;

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

                $pitTimeFixed = $baseTime
                     + ($vStaffConc * $fSConc)
                     + ($vStaffStress * $fSStress)
                     + ($vTdExp * ($pc['factor_td_exp']))
                     + ($vTdPit * ($pc['factor_td_pit']));

                $pitLaneLoss = (float)$trackDb['pit_time'];

                $fuel_per_lap_val = ($comp === 'Rain') ? $fplBaseWet : $fplBaseDry;

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

            // Fuel-weight loss for a whole race run in ($stops + 1) stints.
                $fuelCostFor = static fn (int $stops): float => 0.005 * (
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

            // Search the stop counts the tyres allow and keep the cheapest.
            // Pit losses climb with every stop while fuel-weight loss falls,
            // so the total has an interior minimum: assuming fewer stops is
            // always better silently overshoots it. The tank cap is a
            // feasibility filter on the *recommended* load — the figure the
            // manager actually fuels — not on the bare minimum, so boost fuel
            // and the safety lap can force an extra stop on their own.
                $maxFuel = 180.0;

            /**
             * @return array{stops: int, feasible: bool, total: float,
             *     stint_fuel: float, recommended: float, pit_time: float,
             *     lost_pits: float, fuel_cost: float}
             */
                $planFor = function (int $stops) use (
                    $relevantTotalFuel,
                    $boostExtraTotal,
                    $fuelPerLapAdj,
                    $fFuel,
                    $pitTimeFixed,
                    $pitLaneLoss,
                    $fuelCostFor,
                    $tcdVal,
                    $maxFuel
                ): array {
                    $fuelPerStint = $relevantTotalFuel / ($stops + 1);
                    $stintFuel = $fuelPerStint + ($boostExtraTotal / ($stops + 1));
                    $recommended = ceil($stintFuel + $fuelPerLapAdj);
                    $pitTime = max(15.0, ($fuelPerStint * $fFuel) + $pitTimeFixed);
                    $lostPits = $stops * ($pitTime + $pitLaneLoss);
                    $fuelCost = $fuelCostFor($stops);

                    return [
                    'stops' => $stops,
                    'feasible' => $recommended <= $maxFuel,
                    'total' => $lostPits + $fuelCost + $tcdVal,
                    'stint_fuel' => $stintFuel,
                    'recommended' => $recommended,
                    'pit_time' => $pitTime,
                    'lost_pits' => $lostPits,
                    'fuel_cost' => $fuelCost,
                    ];
                };

                $best = $planFor($minStopsForWear);

                for ($cand = $minStopsForWear + 1; $cand <= $laps; $cand++) {
                    $plan = $planFor($cand);
                    $hadFeasible = $best['feasible'];

                    // Prefer any feasible plan over an infeasible one; among plans
                    // of equal feasibility, the cheapest total wins.
                    if (
                        ($plan['feasible'] && !$best['feasible'])
                        || ($plan['feasible'] === $best['feasible'] && $plan['total'] < $best['total'])
                    ) {
                        $best = $plan;
                        continue;
                    }

                    // Once a feasible plan is in hand and adding a stop stopped
                    // paying off, further stops only cost more: pit losses grow
                    // linearly while the fuel saving shrinks as 1/(stops+1).
                    if ($plan['feasible'] && $hadFeasible) {
                        break;
                    }
                }

                $stops = $best['stops'];
                $lapsPerSetForced = floor($laps / ($stops + 1));

                $totalLost = round($best['lost_pits'] + $best['fuel_cost'] + $tcdVal, 2);

            // The gain is whole-race: seconds per lap x laps x risk. It is a
            // clear-air figure — a driver stuck behind someone banks none of
            // it, which is why the UI keeps the blocking caveat.
                $ctrGain = round($ctrGainPerLap * $laps * $risk, 2);

                $tyreResults[$comp] = [
                'stops' => $stops,
                'laps_set' => ($stops > 0 && $lapsPerSetForced < $lapsPerSet) ? $lapsPerSetForced : $lapsPerSet,
                'fuel_load' => ceil($best['stint_fuel']),
                'fuel_recommended' => $best['recommended'],
                'fuel_over_capacity' => !$best['feasible'],
                'pit_time_est' => round($best['pit_time'], 2),
                'lost_pits' => round($best['lost_pits'], 2),
                'lost_fuel' => round($best['fuel_cost'], 2),
                'lost_tcd' => round($tcdVal, 2),
                'total_lost' => $totalLost,
                'ctr_gain' => $ctrGain,
                'net_lost' => round($totalLost - $ctrGain, 2),
                ];
            }

            return $tyreResults;
        };

        $tyreResults = $planAtRisk($risk);


        return [
            'track' => $trackDb['name'],
            'overtaking' => $trackDb['overtaking'] ?? null,
            'track_grip' => $trackDb['grip'] ?? null,
            'track_tyre_wear' => $trackDb['tyre_wear'] ?? null,
            'track_distance' => (float)($trackDb['distance'] ?? 0),
            'track_pit_time' => (float)($trackDb['pit_time'] ?? 0),
            'fuel' => [
                'dry' => ceil($totalFuelDry),
                'wet' => ceil($totalFuelWet),
                'wet_estimated' => $wetRateEstimated,
                'l_per_lap' => round($lapLen * $lkmDry, 2)
            ],
            'tyres' => $tyreResults,
            'ctr_gain_per_lap' => $ctrGainPerLap,
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
