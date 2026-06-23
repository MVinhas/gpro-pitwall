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
use App\Service\RiskAdvisorService;
use App\Service\PhaMatchService;
use App\Service\CarWearService;
use Twig\Environment;

class StrategyController
{
    public const string END_OF_SEASON_MESSAGE =
        'Season finished — no next race scheduled yet. Come back tomorrow!';

    public const string NO_SUPPLIER_MESSAGE =
        'No tyre supplier selected. Pick one in GPRO (Tyres office), '
        . 're-sync, then race strategy will be available.';

    public const string NO_PILOT_MESSAGE =
        'No driver under contract. Hire a pilot in GPRO, then re-sync.';

    public function __construct(
        private readonly StrategyService $strategyService,
        private readonly GproApiClient $api,
        private readonly GproDataMapper $mapper,
        private readonly SetupCalculatorService $setupService,
        private readonly Authorize $authorize,
        private readonly RaceWeatherService $weather,
        private readonly RiskAdvisorService $riskAdvisor,
        private readonly PhaMatchService $phaMatch,
        private readonly CarWearService $carWear,
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
            $office = $this->api->getOfficeData();
            $trackProfile = $this->api->getNextRaceProfile();

            // endOfSeason is the authoritative "no next race" signal. Bail only
            // on that — a strategy on a phantom race is worse than none (#21).
            //
            // trackNotFoundNote alone is NOT reliable: GPRO sets it at the start
            // of a new season (race 1) while the track + laps are already known
            // but pre-race setup (incl. tyre supplier) isn't done yet. Treating
            // it as end-of-season hid the real cause — a missing supplier —
            // behind the wrong message. Only honour the note when there's also
            // genuinely no scheduled race.
            $endOfSeason = !empty($office['endOfSeason']);
            $noRaceScheduled = (int)($office['raceNb'] ?? 0) === 0;
            if ($endOfSeason || ($noRaceScheduled && !empty($trackProfile['trackNotFoundNote']))) {
                return ['error' => self::END_OF_SEASON_MESSAGE];
            }

            $supplierId = (int)($office['tyreSupplierId'] ?? 0);

            // No supplier picked yet (id 0): don't silently assume Pipirelli —
            // the wear/strategy would be wrong. Tell the user to choose one (#22).
            if ($supplierId === 0) {
                return ['error' => self::NO_SUPPLIER_MESSAGE];
            }

            // Calendar + supplier present but no driver under contract: point the
            // user at the Recruitment Analyzer (rendered as a special notice).
            if (!$this->api->hasPilot()) {
                return ['error' => self::NO_PILOT_MESSAGE];
            }

            $pilotRaw = $this->api->getMyPilotDetails();
            $carRaw   = $this->api->getCarData();
            $staffRaw = $this->api->getStaffAndFacilities();
            $tdRaw    = $this->api->getTechnicalDirector();
            $weatherData = $this->api->getRaceSetup();

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

            $has = fn(string $k): bool => ($request->post($k) !== null && $request->post($k) !== '');

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
            $strategyResults['supplier_info'] = $supplier === null ? null : [
                'dry' => isset($supplier['dryPerformance']) ? (int)$supplier['dryPerformance'] : null,
                'wet' => isset($supplier['rainPerformance']) ? (int)$supplier['rainPerformance'] : null,
                'temp' => isset($supplier['peakTemperature']) ? (int)$supplier['peakTemperature'] : null,
            ];

            $raceIsWet = $setupWeatherInputs['Race']['weather'] === 'Wet';

            $strategyResults['risk_advice'] = $this->riskAdvisor->suggest(
                $driver,
                [
                    'name'          => $strategyResults['track'] ?? null,
                    'overtaking'    => $strategyResults['overtaking'] ?? null,
                    'grip'          => $strategyResults['track_grip'] ?? null,
                    'tyre_wear'     => $strategyResults['track_tyre_wear'] ?? null,
                    'distance'      => (float)($strategyResults['track_distance'] ?? 0),
                    'pit_lane_loss' => (float)($strategyResults['track_pit_time'] ?? 0),
                ],
                $raceIsWet,
                $rain['race_rain_avg'],
            );

            $strategyResults['push_signals'] = $this->buildPushSignals(
                $weatherData,
                $carRaw,
                $pilotRaw,
                $strategyResults['supplier_info'],
                $raceIsWet,
                $finalRaceTemp,
                // The group feeds only power the advisory card — never let a
                // hiccup there fail the whole strategy calc. Degrade to no
                // relative signals instead.
                $this->fetchQuietly(fn(): array => $this->api->getMenu()),
                $this->fetchQuietly(fn(): array => $this->api->getMoneyLevels()),
                $this->fetchQuietly(fn(): array => $this->api->getGroupStaff()),
                $this->maxEndWearAtPushRisk(
                    [
                        'id'   => 0,
                        'name' => $strategyResults['track'] ?? ($trackProfile['name'] ?? ''),
                        'laps' => $inputs['laps'],
                    ],
                    $carRaw,
                    $driver,
                ),
            );

            $strategyResults['season'] = $office['seasonNb'] ?? '?';
            $strategyResults['race'] = $office['raceNb'] ?? '?';
            $strategyResults['best_compound'] = $this->pickBestCompound(
                $strategyResults['tyres'] ?? [],
                $rain['race_start_wet'],
            );

            $bestTyre = $strategyResults['tyres'][$strategyResults['best_compound']] ?? null;
            $strategyResults['risk_advice']['boost'] = $this->riskAdvisor->suggestBoostLaps(
                (int)$inputs['laps'],
                (int)($bestTyre['stops'] ?? 0),
                $strategyResults['overtaking'] ?? null,
                $raceIsWet,
                $rain['race_rain_avg'],
            );

            return $strategyResults;
        } catch (\Exception $exception) {
            return ['error' => 'Calculation Error: ' . $exception->getMessage()];
        }
    }

    /**
     * Push checklist shown under the Race Engineer: binary signals that argue
     * for a higher Clear Track Risk. Heuristic, not a game formula.
     *
     * Tyre signals are hidden in Rookie/Amateur (no supplier choice there).
     * Relative-performance signals (car level + driver OA ranked against the
     * group) are added when the group feeds are available.
     *
     * @param array<string, mixed> $raceSetup    GPRO RaceSetup (track + car P/H/A)
     * @param array<string, mixed> $carRaw        GPRO UpdateCar
     * @param array<string, mixed> $pilotRaw      GPRO DriProfile (favourite tracks)
     * @param array{dry: ?int, wet: ?int, temp: ?int}|null $supplierInfo
     * @param array<string, mixed> $menu          GPRO Menu (own IDM + division)
     * @param array<string, mixed> $moneyLevels   GPRO MoneyLevels (group car levels)
     * @param array<string, mixed> $groupStaff    GPRO ViewStaff (group driver OA)
     * @param ?float $wearMaxAtPushRisk           Worst part end-wear at CTR 50, or null
     * @return array{
     *   pha_match: bool, pha_level: string, favourite: bool,
     *   show_tyres: bool, tyres_weather: bool, tyre_perf: ?int, race_wet: bool,
     *   temp_match: bool, race_temp: float, ideal_temp: ?int,
     *   car_rank: ?int, car_total: ?int, car_above: ?bool,
     *   driver_rank: ?int, driver_total: ?int, driver_above: ?bool,
     *   wear_ok: ?bool, wear_max: ?float, wear_risk: int
     * }
     */
    private function buildPushSignals(
        array $raceSetup,
        array $carRaw,
        array $pilotRaw,
        ?array $supplierInfo,
        bool $raceIsWet,
        float $raceTemp,
        array $menu,
        array $moneyLevels,
        array $groupStaff,
        ?float $wearMaxAtPushRisk,
    ): array {
        $matchLevel = $this->phaMatch->matchLevel(
            [
                'power'        => $raceSetup['trackPower'] ?? 0,
                'handling'     => $raceSetup['trackHandl'] ?? 0,
                'acceleration' => $raceSetup['trackAccel'] ?? 0,
            ],
            [
                'power'        => $carRaw['carPower'] ?? 0,
                'handling'     => $carRaw['carHandl'] ?? 0,
                'acceleration' => $carRaw['carAccel'] ?? 0,
            ],
        );

        $tyrePerf = $raceIsWet ? ($supplierInfo['wet'] ?? null) : ($supplierInfo['dry'] ?? null);
        $idealTemp = $supplierInfo['temp'] ?? null;

        $myIdm = (int)($menu['IDM'] ?? 0);
        $car    = self::groupStanding($myIdm, $moneyLevels['managers'] ?? [], 'carLevel');
        $driver = self::groupStanding($myIdm, $groupStaff['managers'] ?? [], 'driOA');

        return [
            'pha_match'     => $matchLevel !== PhaMatchService::MATCH_NONE,
            'pha_level'     => $matchLevel,
            'favourite'     => $this->isFavouriteTrack($pilotRaw, (int)($raceSetup['trackId'] ?? 0)),
            'show_tyres'    => !self::isSupplierlessDivision((string)($menu['group'] ?? '')),
            'tyres_weather' => $tyrePerf !== null && $tyrePerf >= 4,
            'tyre_perf'     => $tyrePerf,
            'race_wet'      => $raceIsWet,
            'temp_match'    => $idealTemp !== null && abs($raceTemp - $idealTemp) <= 3,
            'race_temp'     => $raceTemp,
            'ideal_temp'    => $idealTemp,
            'car_rank'      => $car['rank'],
            'car_total'     => $car['total'],
            'car_above'     => $car['above'],
            'driver_rank'   => $driver['rank'],
            'driver_total'  => $driver['total'],
            'driver_above'  => $driver['above'],
            'wear_ok'       => $wearMaxAtPushRisk === null ? null : $wearMaxAtPushRisk <= self::WEAR_HEADROOM_LIMIT,
            'wear_max'      => $wearMaxAtPushRisk,
            'wear_risk'     => self::PUSH_RISK,
        ];
    }

    /** Reference Clear Track Risk for the wear-headroom signal. */
    private const int PUSH_RISK = 50;

    /** A part projected past this end-wear at PUSH_RISK is a failure risk. */
    private const float WEAR_HEADROOM_LIMIT = 90.0;

    /**
     * Highest projected end-of-race part wear (%) at the reference push risk
     * (CTR 50). Null when wear can't be projected (e.g. the track isn't in our
     * DB) so the signal hides cleanly.
     *
     * @param array<string, mixed> $trackData id=0 forces name-only resolution
     * @param array<string, mixed> $carRaw
     * @param array<string, mixed> $driver
     */
    private function maxEndWearAtPushRisk(array $trackData, array $carRaw, array $driver): ?float
    {
        $wear = $this->carWear->calculateWear($trackData, $carRaw, $driver, self::PUSH_RISK);
        $parts = $wear['parts'] ?? null;
        if (isset($wear['error']) || !is_array($parts) || $parts === []) {
            return null;
        }

        $max = 0.0;
        foreach ($parts as $part) {
            $max = max($max, (float)($part['end'] ?? 0));
        }
        return $max;
    }

    /**
     * Runs a cache-backed API fetch, swallowing any failure to an empty array.
     * For optional, non-critical data (the push card's group feeds) so a feed
     * outage degrades the card instead of failing the strategy.
     *
     * @param callable(): array<string, mixed> $fetch
     * @return array<string, mixed>
     */
    private function fetchQuietly(callable $fetch): array
    {
        try {
            return $fetch();
        } catch (\Throwable) {
            return [];
        }
    }

    /** Rookie and Amateur don't pick a tyre supplier, so tyre fit is moot. */
    public static function isSupplierlessDivision(string $group): bool
    {
        $tier = strtolower(trim(explode('-', $group, 2)[0]));
        return $tier === 'rookie' || $tier === 'amateur';
    }

    /**
     * Ranks the manager's own value for `$field` against the whole group.
     * Standard competition ranking (1 = best, ties share the better rank);
     * "above" is strictly above the group arithmetic mean. Returns nulls when
     * the manager can't be located or no group values exist, so callers can
     * hide the signal cleanly.
     *
     * @param list<array<string, mixed>> $managers
     * @return array{rank: ?int, total: ?int, above: ?bool}
     */
    public static function groupStanding(int $myIdm, array $managers, string $field): array
    {
        $none = ['rank' => null, 'total' => null, 'above' => null];
        if ($myIdm <= 0) {
            return $none;
        }

        $mine = null;
        $values = [];
        foreach ($managers as $m) {
            $value = (int)($m[$field] ?? 0);
            if ($value <= 0) {
                continue;
            }
            $values[] = $value;
            if ((int)($m['IDM'] ?? 0) === $myIdm) {
                $mine = $value;
            }
        }

        if ($mine === null || $values === []) {
            return $none;
        }

        $better = array_filter($values, static fn(int $v): bool => $v > $mine);
        $mean = array_sum($values) / count($values);

        return [
            'rank'  => count($better) + 1,
            'total' => count($values),
            'above' => $mine > $mean,
        ];
    }

    /**
     * Is the next race on one of the driver's three favourite tracks?
     * DriProfile exposes favTrack1/2/3 as {name, id} objects.
     *
     * @param array<string, mixed> $pilot
     */
    private function isFavouriteTrack(array $pilot, int $trackId): bool
    {
        if ($trackId <= 0) {
            return false;
        }

        foreach (['favTrack1', 'favTrack2', 'favTrack3'] as $key) {
            $fav = $pilot[$key] ?? null;
            if (is_array($fav) && (int)($fav['id'] ?? 0) === $trackId) {
                return true;
            }
        }
        return false;
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
