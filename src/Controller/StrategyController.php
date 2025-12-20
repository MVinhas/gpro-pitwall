<?php

namespace App\Controller;

use App\Http\Request;
use App\Service\StrategyService;
use App\Service\SetupCalculatorService;
use App\Service\GproApiClient;
use App\Service\GproDataMapper;
use App\Repository\UserRepository;

class StrategyController
{
    public function __construct(
        private readonly StrategyService $strategyService,
        private readonly GproApiClient $api,
        private readonly GproDataMapper $mapper,
        private readonly SetupCalculatorService $setupService,
        private readonly UserRepository $userRepo
    ) {
    }

    public function handle(Request $request): void
    {
        $isLoggedIn = !empty($_SESSION['user_id']);
        $userId = $isLoggedIn ? (int) $_SESSION['user_id'] : 0;
        $user = $userId ? $this->userRepo->findById($userId) : null;

        if ($isLoggedIn && !$user) {
            session_destroy();
            $isLoggedIn = false;
            $user = null;
        }

        $hasToken  = !empty($user['api_token']);
        $isPremium = !empty($user['is_premium']);
        $isAdmin   = !empty($user['is_admin']);

        if ($isLoggedIn && $hasToken) {
            $this->api->setToken($user['api_token']);
        }

        $action = $request->post('action');
        if ($action === 'flush_cache') {
            $this->flushCache();
            return;
        }

        if ($action === 'calculate_strategy') {
            $this->calculate($request);
            return;
        }

        header("Location: /");
        exit;
    }

    private function flushCache(): void
    {
        unset($_SESSION['api_cache']);
        unset($_SESSION['imported_driver']);
        unset($_SESSION['wear_results']);
        unset($_SESSION['strategy_results']);
        $_SESSION['flash_message'] = "Cache cleared.";
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }

    private function calculate(Request $request): void
    {

        try {
            // 1. FETCH ALL API DATA
            $trackProfile = $this->api->getNextRaceProfile();
            $office = $this->api->getOfficeData();
            $pilotRaw = $this->api->getMyPilotDetails();
            $carRaw   = $this->api->getCarData();
            $staffRaw = $this->api->getStaffAndFacilities();
            $tdRaw    = $this->api->getTechnicalDirector();
            $weatherData = $this->api->getRaceSetup();
            // 2. RESOLVE SUPPLIER
            $supplierId = (int)($office['tyreSupplierId'] ?? 0);

            // Invert the mapping once
            $tyreSuppliers = [
                1 => 'Pipirelli',
                9 => 'Avonn',
                2 => 'Yokomama',
                3 => 'Dunnolop',
                8 => 'Contimental',
                4 => 'Badyear',
                7 => 'Hancock',
                5 => 'Michelini',
                6 => 'Bridgerock',
            ];

            $supplierName = $tyreSuppliers[$supplierId] ?? 'Pipirelli';


            // 3. PREPARE DEFAULTS
            $driver = $this->mapper->mapDriver($pilotRaw);

            $car = [
                'lvlEngine' => (int)($carRaw['lvlEngine'] ?? 1),
                'lvlSusp' => (int)($carRaw['lvlSusp'] ?? 1),
                'lvlElectronics' => (int)($carRaw['lvlElectronics'] ?? 1),
                'lvlChassis' => (int)($carRaw['lvlChassis'] ?? 1),
                'lvlFWing' => (int)($carRaw['lvlFWing'] ?? 1),
                'lvlRWing' => (int)($carRaw['lvlRWing'] ?? 1),
                'lvlUnderbody' => (int)($carRaw['lvlUnderbody'] ?? 1),
                'lvlSidepods' => (int)($carRaw['lvlSidepods'] ?? 1),
                'lvlCooling' => (int)($carRaw['lvlCooling'] ?? 1),
                'lvlGear' => (int)($carRaw['lvlGear'] ?? 1),
                'lvlBrakes' => (int)($carRaw['lvlBrakes'] ?? 1),

                'usaChassis' => (int)($carRaw['usaChassis'] ?? 0),
                'usaEngine' => (int)($carRaw['usaEngine'] ?? 0),
                'usaFWing' => (int)($carRaw['usaFWing'] ?? 0),
                'usaRWing' => (int)($carRaw['usaRWing'] ?? 0),
                'usaUnderbody' => (int)($carRaw['usaUnderbody'] ?? 0),
                'usaSidepods' => (int)($carRaw['usaSidepods'] ?? 0),
                'usaCooling' => (int)($carRaw['usaCooling'] ?? 0),
                'usaGear' => (int)($carRaw['usaGear'] ?? 0),
                'usaBrakes' => (int)($carRaw['usaBrakes'] ?? 0),
                'usaSusp' => (int)($carRaw['usaSusp'] ?? 0),
                'usaElectronics' => (int)($carRaw['usaElectronics'] ?? 0),
            ];

            $staff = [
                'concentration' => (float)($staffRaw['concentration'] ?? 0),
                'stressHandling' => (float)($staffRaw['stressHandling'] ?? 0)
            ];

            $td = [
                'id' => $tdRaw['id'] ?? 0,
                'ownTD' => $tdRaw['ownTD'] ?? 0,
                'experience' => (float)($tdRaw['experience'] ?? 0),
                'pitCoordination' => (float)($tdRaw['pitCoord'] ?? ($tdRaw['pitCoordination'] ?? 0))
            ];

            // 4. MERGE USER INPUTS
            $has = fn($k): bool => ($request->post($k) !== null && $request->post($k) !== '');

            // Driver
            if ($has('d_conc')) {
                $driver['concentration'] = (int)$request->post('d_conc');
            }

            if ($has('d_tal')) {
                $driver['talent']        = (int)$request->post('d_tal');
            }

            if ($has('d_agg')) {
                $driver['aggressiveness'] = (int)$request->post('d_agg');
            }

            if ($has('d_exp')) {
                $driver['experience']    = (int)$request->post('d_exp');
            }

            if ($has('d_tech')) {
                $driver['technical_insight'] = (int)$request->post('d_tech');
            }

            if ($has('d_wgt')) {
                $driver['weight']        = (int)$request->post('d_wgt');
            }

            // Car
            if ($has('c_eng')) {
                $car['lvlEngine']      = (int)$request->post('c_eng');
            }

            if ($has('c_susp')) {
                $car['lvlSusp']        = (int)$request->post('c_susp');
            }

            if ($has('c_elec')) {
                $car['lvlElectronics'] = (int)$request->post('c_elec');
            }

            // Staff
            if ($has('s_conc')) {
                $staff['concentration']  = (float)$request->post('s_conc');
            }

            if ($has('s_stress')) {
                $staff['stressHandling'] = (float)$request->post('s_stress');
            }

            // TD
            if ($has('td_exp')) {
                $td['experience'] = (float)$request->post('td_exp');
                if ($td['experience'] > 0) {
                    $td['ownTD'] = 1;
                }
            }

            if ($has('td_pit')) {
                $td['pitCoordination'] = (float)$request->post('td_pit');
            }


            // 5. WEATHER & ENV
            $w = $weatherData['weather'] ?? [];
            $q1IsWet = isset($w['q1Weather']) && stripos($w['q1Weather'], 'Rain') !== false;
            $q2IsWet = isset($w['q2Weather']) && stripos($w['q2Weather'], 'Rain') !== false;

            $raceRainSum = 0;
            $raceRainCount = 0;
            $quarters = ['Q1', 'Q2', 'Q3', 'Q4'];
            foreach ($quarters as $q) {
                // API provides 'raceQ1RainPLow', 'raceQ1RainPHigh', etc.
                $low  = $w["race{$q}RainPLow"] ?? 0;
                $high = $w["race{$q}RainPHigh"] ?? 0;
                $raceRainSum += ($low + $high);
                $raceRainCount += 2;
            }

            $raceRainAvg = ($raceRainSum / $raceRainCount);

            $raceStartIsWet = $raceRainAvg >= 50;

            $defQ1W = $q1IsWet ? 'Wet' : 'Dry';
            $defQ2W = $q2IsWet ? 'Wet' : 'Dry';
            $defRaceW = $raceStartIsWet ? 'Wet' : 'Dry';

            $avgTemp = $this->calculateAvgWeather($weatherData, 'Temp');
            $avgHum = $this->calculateAvgWeather($weatherData, 'Hum');
            $q1Temp = $w['q1Temp'];
            $q2Temp = $w['q2Temp'];

            $finalRaceTemp = $has('temp') ? (float)$request->post('temp') : $avgTemp;
            $finalRaceHum  = $has('humidity') ? (int)$request->post('humidity') : $avgHum;

            $inputs = [
                'laps' => (int)$request->post('laps', $trackProfile['laps'] ?? 0),
                'temp' => $finalRaceTemp,
                'hum'  => $finalRaceHum,
                'risk' => (int)$request->post('risk', 0),
                'target_wear' => (int)$request->post('target_wear', 15),
            ];

            if (empty($trackProfile['name']) && !empty($office['trackName'])) {
                $trackProfile['name'] = $office['trackName'];
            }

            // 6. CALCULATE STRATEGY
            $strategyResults = $this->strategyService->calculateStrategy(
                $trackProfile,
                $car,
                $driver,
                $staff,
                $td,
                $inputs,
                $supplierName
            );

            // 7. CALCULATE SETUPS
            $setupWeatherInputs = [
                'Q1' => [
                    'temp' => $request->post('q1_temp') ?: $q1Temp,
                    'weather' => $request->post('q1_weather') ?: $defQ1W
                ],
                'Q2' => [
                    'temp' => $request->post('q2_temp') ?: $q2Temp,
                    'weather' => $request->post('q2_weather') ?: $defQ2W
                ],
                'Race' => [
                    'temp' => $finalRaceTemp,
                    'weather' => $request->post('race_weather') ?: $defRaceW
                ]
            ];

            $setupResults = $this->setupService->calculateSetups(
                $trackProfile,
                $car,
                $driver,
                $setupWeatherInputs
            );

            $strategyResults['setups'] = $setupResults;
            $strategyResults['weather_inputs'] = $setupWeatherInputs;

            // Redundant is_array check removed
            $strategyResults['season'] = $office['seasonNb'] ?? '?';
            $strategyResults['race'] = $office['raceNb'] ?? '?';

            $_SESSION['strategy_results'] = $strategyResults;
        } catch (\Exception $exception) {
            $_SESSION['strategy_error'] = "Calculation Error: " . $exception->getMessage();
        }

        session_write_close();
        header("Location: /?main_tab=Race Strategy");
        exit;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function calculateAvgWeather(array $data, string $type, int $startQ = 1, int $endQ = 3): float
    {
        if (!isset($data['weather'])) {
            return 0;
        }

        $w = $data['weather'];
        $sum = 0;
        $count = 0;
        for ($i = $startQ; $i <= $endQ; $i++) {
            $low = $w["raceQ{$i}{$type}Low"] ?? 0;
            $high = $w["raceQ{$i}{$type}High"] ?? 0;
            if ($low || $high) {
                $sum += ($low + $high) / 2;
                $count++;
            }
        }

        return $count > 0 ? round($sum / $count, 1) : 20;
    }
}
