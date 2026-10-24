<?php

declare(strict_types=1);

namespace App\Archive;

use App\Repository\ApiArchiveRepository;
use App\Repository\WeekendStateRepository;
use App\Telemetry\PayloadReader;

/**
 * Projects the state a manager entered one race weekend with: driver, TD,
 * staff, facilities, car, cash and championship place. Taken from the snapshot
 * rows stamped with that race; when there are none, from the SFHistory and
 * CCPHistory entries of the season, flagged source 'history'.
 */
final class WeekendStateProjector
{
    use PayloadReader;

    private const array FACILITIES = [
        'windtunnel' => 'lvlWindtunnel', 'pitstop' => 'lvlPitstop', 'rdWorkshop' => 'lvlRDWorkshop',
        'rdDesign' => 'lvlRDDesign', 'engineering' => 'lvlEngineering', 'lab' => 'lvlLab',
        'commercial' => 'lvlCommercial',
    ];

    private const array FACILITIES_FROM_HISTORY = [
        'windtunnel' => 'wind', 'pitstop' => 'pit', 'rdWorkshop' => 'rdWorkshop', 'rdDesign' => 'design',
        'engineering' => 'engWorkshop', 'lab' => 'lab', 'commercial' => 'commercial',
    ];

    private const array STAFF = [
        'overall' => 'overall', 'concentration' => 'concentration', 'experience' => 'experience',
        'technical' => 'technical', 'stressHandling' => 'stressHandling', 'motivation' => 'motivation',
        'efficiency' => 'efficiency', 'salary' => 'salary',
    ];

    private const array STAFF_FROM_HISTORY = [
        'overall' => 'oa', 'concentration' => 'conc', 'experience' => 'exp', 'technical' => 'tech',
        'stressHandling' => 'stress', 'motivation' => 'mot', 'efficiency' => 'eff',
    ];

    private const array CAR_PARTS = [
        'chassis' => 'Chassis', 'engine' => 'Engine', 'fWing' => 'FWing', 'rWing' => 'RWing',
        'underbody' => 'Underbody', 'sidepods' => 'Sidepods', 'cooling' => 'Cooling', 'gear' => 'Gear',
        'brakes' => 'Brakes', 'susp' => 'Susp', 'electronics' => 'Electronics',
    ];

    private const array CAR_POINTS = [
        'power' => 'carPower', 'handling' => 'carHandl', 'accel' => 'carAccel',
        'tPower' => 'tPower', 'tHandling' => 'tHandl', 'tAccel' => 'tAccel',
    ];

    private const array CAR_POINTS_FROM_HISTORY = [
        'power' => 'power', 'handling' => 'handl', 'accel' => 'accel',
        'tPower' => 'tPower', 'tHandling' => 'tHandl', 'tAccel' => 'tAccel',
    ];

    private const array DRIVER_PROFILE = [
        'overall' => 'overall', 'age' => 'age', 'energy' => 'energy', 'concentration' => 'concentration',
        'talent' => 'talent', 'aggressiveness' => 'aggressiveness', 'experience' => 'experience',
        'techInsight' => 'techInsight', 'stamina' => 'stamina', 'charisma' => 'charisma',
        'motivation' => 'motivation', 'reputation' => 'reputation', 'weight' => 'weight',
        'races' => 'races', 'wins' => 'wins', 'podiums' => 'podiums', 'points' => 'points',
        'poles' => 'poles', 'fastLaps' => 'fastLaps',
    ];

    private const array TD_PROFILE = [
        'overall' => 'overall', 'age' => 'age', 'leadership' => 'leadership', 'mechanics' => 'mechanics',
        'electronics' => 'electronics', 'experience' => 'experience', 'aerodynamics' => 'aerodynamics',
        'pitCoord' => 'pitCoord', 'motivation' => 'motivation',
    ];

    public function __construct(
        private readonly ApiArchiveRepository $archive,
        private readonly WeekendStateRepository $repository,
        private readonly ?RaceSnapshotReader $snapshots = null,
    ) {
    }

    public function project(int $userId, int $season, int $race): void
    {
        $snapshot = ($this->snapshots ?? new RaceSnapshotReader($this->archive))->read($userId, $season, $race);
        $row = $snapshot->present
            ? $this->fromSnapshot($userId, $season, $race, $snapshot)
            : $this->fromHistory($userId, $season, $race);

        if ($row === null) {
            $this->repository->delete($userId, $season, $race);

            return;
        }
        $this->repository->save($row);
    }

    /**
     * @return array{
     *     user_id: int, season: int, race: int, source: string,
     *     driver_json: ?string, td_json: ?string, staff_json: ?string,
     *     facilities_json: ?string, car_json: ?string,
     *     cash: ?int, pts: ?int, pos: ?int
     * }
     */
    private function fromSnapshot(int $userId, int $season, int $race, RaceSnapshot $s): array
    {
        $office = $s->office;

        return [
            'user_id' => $userId, 'season' => $season, 'race' => $race, 'source' => 'snapshot',
            'driver_json' => $s->hasDriver ? $this->driverJson($office, $s->driver) : null,
            'td_json' => $this->tdJson($office, $s->td),
            'staff_json' => $this->json($this->pick($s->staff, self::STAFF)),
            'facilities_json' => $this->json($this->pick($s->staff, self::FACILITIES)),
            'car_json' => $this->carJson($s->car),
            'cash' => $this->int($office['cash'] ?? null),
            'pts' => $this->int($office['pts'] ?? null),
            'pos' => $this->int($office['pos'] ?? null),
        ];
    }

    /**
     * @return array{
     *     user_id: int, season: int, race: int, source: string,
     *     driver_json: ?string, td_json: ?string, staff_json: ?string,
     *     facilities_json: ?string, car_json: ?string,
     *     cash: ?int, pts: ?int, pos: ?int
     * }|null
     */
    private function fromHistory(int $userId, int $season, int $race): ?array
    {
        $sf = $this->entryFor($this->readHistory($userId, 'SFHistory', $season), $race);
        $ccp = $this->entryFor($this->readHistory($userId, 'CCPHistory', $season), $race);
        if ($sf === null && $ccp === null) {
            return null;
        }

        return [
            'user_id' => $userId, 'season' => $season, 'race' => $race, 'source' => 'history',
            'driver_json' => null,
            'td_json' => null,
            'staff_json' => $sf === null ? null : $this->json($this->pick($sf, self::STAFF_FROM_HISTORY)),
            'facilities_json' => $sf === null ? null : $this->json($this->pick($sf, self::FACILITIES_FROM_HISTORY)),
            'car_json' => $ccp === null ? null : $this->json($this->pick($ccp, self::CAR_POINTS_FROM_HISTORY)),
            'cash' => null,
            'pts' => null,
            'pos' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readHistory(int $userId, string $endpoint, int $season): array
    {
        $payload = $this->archive->latest($userId, $endpoint, 'season=' . $season);

        return $payload === null || SupporterGate::isUnavailable($endpoint, $payload) ? [] : $payload;
    }

    /**
     * @param array<string, mixed> $history
     *
     * @return array<string, mixed>|null
     */
    private function entryFor(array $history, int $race): ?array
    {
        foreach ($this->listOf($history['data'] ?? null) as $entry) {
            if ($this->int($entry['race'] ?? null) === $race) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $office
     * @param array<string, mixed> $profile
     */
    private function driverJson(array $office, array $profile): ?string
    {
        $driver = [
            'id' => $this->int($office['driId'] ?? null),
            'name' => $this->str($office['driName'] ?? null) ?? $this->str($profile['driName'] ?? null),
            'overall' => $this->int($office['driOA'] ?? null),
            'racesLeft' => $this->int($office['driRacesLeft'] ?? null),
            'salary' => $this->int($office['driSalary'] ?? null),
            'energy' => $this->int($office['driEnergy'] ?? null),
            'profile' => $profile === [] ? null : $this->pick($profile, self::DRIVER_PROFILE),
        ];

        return $this->json($driver);
    }

    /**
     * @param array<string, mixed> $office
     * @param array<string, mixed> $profile
     */
    private function tdJson(array $office, array $profile): ?string
    {
        if ($profile === []) {
            return null;
        }
        $td = [
            'id' => $this->int($office['tdId'] ?? null),
            'name' => $this->str($profile['tdName'] ?? null),
            'overall' => $this->int($office['tdOA'] ?? null),
            'racesLeft' => $this->int($office['tdRacesLeft'] ?? null),
            'salary' => $this->int($office['tdSalary'] ?? null),
            'profile' => $this->pick($profile, self::TD_PROFILE),
        ];

        return $this->json($td);
    }

    /**
     * @param array<string, mixed> $car
     */
    private function carJson(array $car): ?string
    {
        if ($car === []) {
            return null;
        }
        $out = $this->pick($car, self::CAR_POINTS);
        $parts = [];
        foreach (self::CAR_PARTS as $name => $suffix) {
            $parts[$name] = [
                'level' => $this->int($car['lvl' . $suffix] ?? null),
                'wear' => $this->int($car['usa' . $suffix] ?? null),
            ];
        }
        $out['parts'] = $parts;

        return $this->json($out);
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, string> $map output key => source key
     *
     * @return array<string, int|string|null>
     */
    private function pick(array $source, array $map): array
    {
        $out = [];
        foreach ($map as $key => $from) {
            $value = $source[$from] ?? null;
            $out[$key] = is_numeric($value) ? $this->int($value) : $this->str($value);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data): ?string
    {
        if (array_filter($data, static fn (mixed $v): bool => $v !== null) === []) {
            return null;
        }

        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
