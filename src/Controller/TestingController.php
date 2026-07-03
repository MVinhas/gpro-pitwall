<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Service\CarWearService;
use App\Service\GproApiClient;
use App\Service\GproDataMapper;
use App\Service\SetupCalculatorService;

/**
 * Drives the Testing tab: the current testing track, the car's accumulated
 * test/R&D/engineering/car-character points, and the ideal setup for the
 * testing track (same setup engine as Race Strategy, single session).
 *
 * Read-only — no overrides. Testing weather + temp come straight from GPRO,
 * so there is nothing for the user to tune.
 */
class TestingController
{
    public const string END_OF_SEASON_MESSAGE =
        'Season finished — testing reopens next season. Come back soon!';

    public const string NO_TRACK_MESSAGE =
        'No testing track available yet. Open the Testing office in GPRO, then re-sync.';

    /** Default testing-session length the wear slider opens on. */
    public const int DEFAULT_LAPS = 5;

    /** GPRO caps a testing session at 100 laps. */
    public const int MAX_LAPS = 100;

    /**
     * @param array<string, array{power: float|int, handling: float|int, acceleration: float|int}> $priorityPoints
     */
    public function __construct(
        private readonly GproApiClient $api,
        private readonly GproDataMapper $mapper,
        private readonly SetupCalculatorService $setupService,
        private readonly CarWearService $carWear,
        private readonly array $priorityPoints,
    ) {
    }

    /**
     * Builds the Testing view model, or an `['error' => '...']` array. No
     * session writes — the tab auto-populates from the warmed cache on open.
     *
     * @return array<string, mixed>
     */
    public function runCalc(Request $request): array
    {
        try {
            $testing = $this->api->getTesting();

            if (!empty($testing['endOfSeason'])) {
                return ['error' => self::END_OF_SEASON_MESSAGE];
            }
            if (!empty($testing['showError'])) {
                return ['error' => (string) ($testing['errorMsg'] ?? self::NO_TRACK_MESSAGE)];
            }

            $trackName = (string) ($testing['trackName'] ?? '');
            if ($trackName === '') {
                return ['error' => self::NO_TRACK_MESSAGE];
            }

            if (!$this->api->hasPilot()) {
                return ['error' => StrategyController::NO_PILOT_MESSAGE];
            }

            $office = $this->api->getOfficeData();
            $driver = $this->mapper->mapDriver($this->api->getMyPilotDetails());
            $car = $this->buildCar($testing);

            // Testing runs a single session at the track's known weather; the
            // setup engine still expects a session map, so feed it one entry.
            $weatherLabel = $this->normaliseWeather((string) ($testing['weather'] ?? 'Dry'));
            $temp = (float) ($testing['temp'] ?? 0);

            // Resolve by name only: testing trackId is GPRO's id, not our
            // local autoincrement PK (same gotcha as the cockpit wear lookup).
            $track = ['id' => 0, 'name' => $trackName];

            $setups = $this->setupService->calculateSetups(
                $track,
                $car,
                $driver,
                ['Testing' => ['temp' => $temp, 'weather' => $weatherLabel]],
            );

            $wear = $this->carWear->testingWearRates($track, $car, $driver);

            return [
                'season' => $office['seasonNb'] ?? ($testing['seasonNb'] ?? '?'),
                'race'   => $office['raceNb'] ?? '?',
                'track'  => [
                    'name'         => $trackName,
                    'power'        => (float) ($testing['trackPower'] ?? 0),
                    'handling'     => (float) ($testing['trackHandl'] ?? 0),
                    'acceleration' => (float) ($testing['trackAccel'] ?? 0),
                ],
                'car' => [
                    'power'        => (float) ($testing['carPower'] ?? 0),
                    'handling'     => (float) ($testing['carHandl'] ?? 0),
                    'acceleration' => (float) ($testing['carAccel'] ?? 0),
                ],
                'points' => [
                    'test' => $this->triplet($testing, 'TestPPoints', 'TestHPoints', 'TestAPoints'),
                    'rd'   => $this->triplet($testing, 'RDPPoints', 'RDHPoints', 'RDAPoints'),
                    'eng'  => $this->triplet($testing, 'EngPPoints', 'EngHPoints', 'EngAPoints'),
                    'car_character' => $this->triplet($testing, 'TcarPower', 'TcarHandl', 'TcarAccel'),
                ],
                'weather' => [
                    'label' => $weatherLabel,
                    'temp'  => $temp,
                    'hum'   => (int) ($testing['hum'] ?? 0),
                ],
                'setup'          => $setups['Testing'] ?? null,
                'priority_gains' => $this->priorityPoints,
                'wear'           => isset($wear['error']) ? null : $wear,
                'default_laps'   => self::DEFAULT_LAPS,
                'max_laps'       => self::MAX_LAPS,
            ];
        } catch (\Throwable $e) {
            error_log('[Testing] ' . $e::class . ': ' . $e->getMessage());

            return ['error' => StrategyController::GENERIC_ERROR_MESSAGE];
        }
    }

    /**
     * @param array<string, mixed> $testing
     * @return array{power: float, handling: float, acceleration: float}
     */
    private function triplet(array $testing, string $p, string $h, string $a): array
    {
        return [
            'power'        => (float) ($testing[$p] ?? 0),
            'handling'     => (float) ($testing[$h] ?? 0),
            'acceleration' => (float) ($testing[$a] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $testing
     * @return array<string, int>
     */
    private function buildCar(array $testing): array
    {
        $parts = [
            'Engine', 'Susp', 'Electronics', 'Chassis', 'FWing', 'RWing',
            'Underbody', 'Sidepods', 'Cooling', 'Gear', 'Brakes',
        ];
        $car = [];
        foreach ($parts as $part) {
            $car['lvl' . $part] = (int) ($testing['lvl' . $part] ?? 1);
            $car['usa' . $part] = (int) ($testing['usa' . $part] ?? 0);
        }
        return $car;
    }

    private function normaliseWeather(string $weather): string
    {
        $w = strtolower($weather);
        foreach (['rain', 'storm', 'shower', 'wet'] as $needle) {
            if (str_contains($w, $needle)) {
                return 'Wet';
            }
        }
        return 'Dry';
    }
}
