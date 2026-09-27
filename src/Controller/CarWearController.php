<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Service\CarWearPlannerService;
use App\Service\CarWearService;
use App\Service\GproApiClient;
use App\Service\GproDataMapper;
use App\Service\SeasonCalendarService;
use App\Support\RaceSettings;

/**
 * Drives the Car Wear tab: the planner for the next five races and the
 * season-long wear table.
 *
 * Every input starts from the last sync and can be edited through the GET form
 * to try a what-if (a new part at 0%, a level up, a riskier race). Nothing is
 * saved except the next race's clear-track risk, which is the same setting the
 * Cockpit and Strategy use.
 */
class CarWearController
{
    public const string NO_CALENDAR_MESSAGE =
        'No season calendar yet. Sync from GPRO, then come back.';

    /** GPRO driver attributes run 0..250. */
    private const int MAX_DRIVER_ATTRIBUTE = 250;

    private const array DRIVER_KEYS = ['concentration', 'talent', 'experience'];

    public function __construct(
        private readonly GproApiClient $api,
        private readonly GproDataMapper $mapper,
        private readonly CarWearService $carWear,
        private readonly CarWearPlannerService $planner,
        private readonly SeasonCalendarService $calendar,
    ) {
    }

    /**
     * Builds the Car Wear view model, or an `['error' => '...']` array.
     *
     * @return array<string, mixed>
     */
    public function runCalc(Request $request): array
    {
        try {
            if (!$this->api->hasPilot()) {
                return ['error' => StrategyController::NO_PILOT_MESSAGE];
            }

            $office    = $this->api->getOfficeData();
            $nextRace  = (int) ($office['raceNb'] ?? 0);
            $season    = $this->calendar->season(
                $this->api->getCalendar(),
                $this->api->getAllTracksPreview(),
                $nextRace,
            );
            if ($season === []) {
                return ['error' => self::NO_CALENDAR_MESSAGE];
            }

            $bases = $this->carWear->trackBaseWearByName(array_values(array_filter(
                array_map(static fn (array $row): string => (string) $row['track_name'], $season),
                static fn (string $name): bool => $name !== '',
            )));
            $baseFor = static fn (?string $name): ?array => $name !== null ? ($bases[$name] ?? null) : null;

            $syncedDriver = $this->mapper->mapDriver($this->api->getMyPilotDetails());
            $driver = $this->driverFrom($syncedDriver, $request);
            $driverFactor = $this->carWear->driverFactor($driver);

            $carData = $this->api->getCarData();
            $parts = $this->planner->partsFrom(
                $carData,
                self::arrayParam($request, 'level'),
                self::arrayParam($request, 'wear'),
            );

            $requestedCtr = self::arrayParam($request, 'ctr');
            $races = [];
            foreach ($this->planner->upcoming($season, $nextRace) as $i => $row) {
                $asked = $requestedCtr[$row['race']] ?? null;
                if ($i === 0) {
                    // The next race's CTR is the shared race setting.
                    $ctr = RaceSettings::resolve($_SESSION, RaceSettings::CTR, is_numeric($asked) ? $asked : null, 100);
                } else {
                    $ctr = is_numeric($asked)
                        ? max(0, min(100, (int) $asked))
                        : RaceSettings::resolve($_SESSION, RaceSettings::CTR, null, 100);
                }
                $races[] = [
                    'race'       => $row['race'],
                    'track_name' => $row['track_name'],
                    'ctr'        => $ctr,
                    'base'       => $baseFor($row['track_name']),
                ];
            }

            $rounds = array_map(static fn (array $row): array => [
                'race'       => $row['race'],
                'track_name' => $row['track_name'],
                'base'       => $baseFor($row['track_name']),
            ], $season);

            return [
                'season_nb'  => $office['seasonNb'] ?? null,
                'next_race'  => $nextRace,
                'plan'       => $races === [] ? null : $this->planner->plan($races, $parts, $driverFactor),
                // Prices are for the synced levels: a what-if level change
                // shifts the wear the advice weighs, not what the parts cost.
                'season'     => $this->planner->seasonWear(
                    $rounds,
                    $nextRace,
                    $driverFactor,
                    $parts,
                    $this->planner->partPrices($carData),
                ),
                'parts'      => $parts,
                'driver'     => $driver,
                'slugs'      => CarWearPlannerService::slugs(),
                'max_level'  => CarWearPlannerService::MAX_LEVEL,
                'max_wear'   => CarWearPlannerService::MAX_WEAR,
                'max_driver' => self::MAX_DRIVER_ATTRIBUTE,
                // A what-if in the URL keeps the inputs open on the next render.
                'edited'     => self::arrayParam($request, 'level') !== []
                    || self::arrayParam($request, 'wear') !== []
                    || self::arrayParam($request, 'ctr') !== []
                    || array_filter(
                        self::DRIVER_KEYS,
                        static fn (string $k): bool => $request->get($k) !== null,
                    ) !== [],
            ];
        } catch (\Throwable $e) {
            error_log('[CarWear] ' . $e::class . ': ' . $e->getMessage());

            return ['error' => StrategyController::GENERIC_ERROR_MESSAGE];
        }
    }

    /**
     * @param array<string, mixed> $synced
     * @return array{concentration: int, talent: int, experience: int}
     */
    private function driverFrom(array $synced, Request $request): array
    {
        $out = [];
        foreach (self::DRIVER_KEYS as $key) {
            $asked = $request->get($key);
            $value = is_numeric($asked) ? (int) $asked : (int) ($synced[$key] ?? 0);
            $out[$key] = max(0, min(self::MAX_DRIVER_ATTRIBUTE, $value));
        }
        /** @var array{concentration: int, talent: int, experience: int} $out */
        return $out;
    }

    /** @return array<mixed> */
    private static function arrayParam(Request $request, string $key): array
    {
        $value = $request->get($key);
        return is_array($value) ? $value : [];
    }
}
