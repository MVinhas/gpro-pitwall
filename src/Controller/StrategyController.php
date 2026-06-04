<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Security\Authorize;
use App\Service\StrategyService;
use App\Service\SetupCalculatorService;
use App\Service\GproApiClient;
use App\Service\GproDataMapper;
use App\Service\RaceWeatherService;
use Twig\Environment;

class StrategyController
{
    public function __construct(
        private readonly StrategyService $strategyService,
        private readonly GproApiClient $api,
        private readonly GproDataMapper $mapper,
        private readonly SetupCalculatorService $setupService,
        private readonly Authorize $authorize,
        private readonly RaceWeatherService $weather,
        private readonly Environment $twig,
    ) {
    }

    public function calculate(Request $request): void
    {
        $user = $this->authorize->requireAuth();
        $this->api->setToken($user['api_token']);

        $result = $this->runCalc($request);
        if (isset($result['error'])) {
            $_SESSION['strategy_error'] = $result['error'];
        } else {
            $_SESSION['strategy_results'] = $result;
        }
        session_write_close();
        header("Location: /?main_tab=Race Strategy");
        exit;
    }

    public function fragment(Request $request): void
    {
        $user = $this->authorize->requireAuth();
        $this->api->setToken($user['api_token']);

        $result = $this->runCalc($request);
        echo $this->twig->render('partials/_strategy_results.twig', [
            'strategy_error'   => $result['error'] ?? null,
            'strategy_results' => $result['error'] ?? null ? null : $result,
        ]);
    }

    /**
     * Runs the strategy calculation. Returns the result array, or an
     * `['error' => '...']` array on failure. No session writes here so
     * the same call powers the redirect-after-POST flow, the no-reload
     * fragment refresh, and the auto-populate on first tab open.
     *
     * @return array<string, mixed>
     */
    public function runCalc(Request $request): array
    {
        try {
            $trackProfile = $this->api->getNextRaceProfile();
            $office = $this->api->getOfficeData();
            $pilotRaw = $this->api->getMyPilotDetails();
            $carRaw   = $this->api->getCarData();
            $staffRaw = $this->api->getStaffAndFacilities();
            $tdRaw    = $this->api->getTechnicalDirector();
            $weatherData = $this->api->getRaceSetup();
            $supplierId = (int)($office['tyreSupplierId'] ?? 0);

            // The TyreSuppliers feed is the single source of truth for supplier
            // name + durability — GPRO can re-tune both per season, so neither
            // is hardcoded. If the feed is unavailable, supplier stays null and
            // StrategyService falls back to its secrets snapshot.
            $supplier = $this->api->findTyreSupplierById($supplierId);
            $supplierName = (string)($supplier['name'] ?? 'Pipirelli');
            $supplierDurability = isset($supplier['durability'])
                ? (int)$supplier['durability']
                : null;


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

            $has = fn($k): bool => ($request->post($k) !== null && $request->post($k) !== '');

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


            if ($has('c_eng')) {
                $car['lvlEngine']      = (int)$request->post('c_eng');
            }

            if ($has('c_susp')) {
                $car['lvlSusp']        = (int)$request->post('c_susp');
            }

            if ($has('c_elec')) {
                $car['lvlElectronics'] = (int)$request->post('c_elec');
            }


            if ($has('s_conc')) {
                $staff['concentration']  = (float)$request->post('s_conc');
            }

            if ($has('s_stress')) {
                $staff['stressHandling'] = (float)$request->post('s_stress');
            }


            if ($has('td_exp')) {
                $td['experience'] = (float)$request->post('td_exp');
                if ($td['experience'] > 0) {
                    $td['ownTD'] = 1;
                }
            }

            if ($has('td_pit')) {
                $td['pitCoordination'] = (float)$request->post('td_pit');
            }

            $w = $weatherData['weather'] ?? [];
            $rain = $this->weather->assess($w);

            $defQ1W = $rain['q1_wet'] ? 'Wet' : 'Dry';
            $defQ2W = $rain['q2_wet'] ? 'Wet' : 'Dry';
            $defRaceW = $rain['race_start_wet'] ? 'Wet' : 'Dry';

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
                'boost_stints' => (int)$request->post('boost_stints', 0),
            ];

            if (empty($trackProfile['name']) && !empty($office['trackName'])) {
                $trackProfile['name'] = $office['trackName'];
            }

            $strategyResults = $this->strategyService->calculateStrategy(
                $trackProfile,
                $car,
                $driver,
                $staff,
                $td,
                $inputs,
                $supplierName,
                $supplierDurability
            );

            $setupWeatherInputs = [
                'Q1' => [
                    'temp' => (float)($request->post('q1_temp') ?: $q1Temp),
                    'weather' => $request->post('q1_weather') ?: $defQ1W
                ],
                'Q2' => [
                    'temp' => (float)($request->post('q2_temp') ?: $q2Temp),
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

            $strategyResults['season'] = $office['seasonNb'] ?? '?';
            $strategyResults['race'] = $office['raceNb'] ?? '?';
            $strategyResults['best_compound'] = $this->pickBestCompound(
                $strategyResults['tyres'] ?? [],
                $rain['race_start_wet'],
            );

            return $strategyResults;
        } catch (\Exception $exception) {
            return ['error' => 'Calculation Error: ' . $exception->getMessage()];
        }
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

    /**
     * Pick the lowest-total-lost compound, excluding Rain unless the race itself starts wet.
     *
     * @param array<string, array<string, mixed>> $tyres
     */
    private function pickBestCompound(array $tyres, bool $raceWet): ?string
    {
        $best = null;
        $bestLost = INF;
        foreach ($tyres as $compound => $row) {
            if (!$raceWet && $compound === 'Rain') {
                continue;
            }
            $lost = (float)($row['total_lost'] ?? INF);
            if ($lost < $bestLost) {
                $bestLost = $lost;
                $best = $compound;
            }
        }
        return $best;
    }
}
