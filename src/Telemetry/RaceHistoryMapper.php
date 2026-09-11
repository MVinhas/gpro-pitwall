<?php

declare(strict_types=1);

namespace App\Telemetry;

/**
 * Turns one RaceAnalysis payload into the manager's OWN detailed race record.
 *
 * This is the deliberate counterpart to RaceTelemetryMapper: that one strips
 * every trace of identity so the shared corpus stays anonymous, while this one
 * is explicitly keyed to the user who fetched it and keeps the full report —
 * stints, pit stops, per-part wear, both qualifying runs, driver deltas.
 *
 * The split is the privacy boundary. Nothing produced here may ever be written
 * to race_telemetry, and nothing here is ever shown to another manager.
 *
 * Blocks with no fixed width (stints, pits, parts, qualifying) are stored as
 * JSON rather than exploded into columns: they are read back whole for one
 * race at a time and never filtered on, so columns would buy nothing.
 */
final class RaceHistoryMapper
{
    use PayloadReader;

    /** GPRO points per finishing position (1st..10th); 0 outside the top 10. */
    private const array POINTS_TABLE = [
        1 => 25, 2 => 18, 3 => 15, 4 => 12, 5 => 10,
        6 => 8, 7 => 6, 8 => 4, 9 => 2, 10 => 1,
    ];

    /** Lap weather strings GPRO uses for a wet track. */
    private const array WET_WEATHER = ['Rain', 'Heavy Rain', 'Light Rain'];

    /** Car parts, in the order GPRO's own race report lists them. */
    private const array PARTS = [
        'chassis'     => 'Chassis',
        'engine'      => 'Engine',
        'FWing'       => 'Front wing',
        'RWing'       => 'Rear wing',
        'underbody'   => 'Underbody',
        'sidepods'    => 'Sidepods',
        'cooling'     => 'Cooling',
        'gear'        => 'Gearbox',
        'brakes'      => 'Brakes',
        'susp'        => 'Suspension',
        'electronics' => 'Electronics',
    ];

    /** Driver attributes, keyed by payload field. */
    private const array DRIVER_ATTRS = [
        'OA'  => 'Overall',
        'con' => 'Concentration',
        'tal' => 'Talent',
        'agr' => 'Aggressiveness',
        'exp' => 'Experience',
        'tei' => 'Technical insight',
        'sta' => 'Stamina',
        'cha' => 'Charisma',
        'mot' => 'Motivation',
        'rep' => 'Reputation',
        'wei' => 'Weight',
    ];

    /**
     * @param array<string, mixed> $analysis RaceAnalysis payload
     * @param array<string, mixed> $td       TDProfile payload ([] when none)
     * @param array<string, mixed> $staff    StaffAndFacilities payload ([] when none)
     * @param float|null           $lapKm    Track lap length, for per-km rates
     * @return array<string, mixed>|null     null when the payload is unusable
     */
    public function map(
        int $userId,
        array $analysis,
        array $td = [],
        array $staff = [],
        ?float $lapKm = null,
    ): ?array {
        $laps = $this->listOf($analysis['laps'] ?? null);
        $driver = $this->arrayOf($analysis['driver'] ?? null);
        if ($laps === [] || $driver === []) {
            return null;
        }

        $season = $this->int($analysis['selSeasonNb'] ?? null);
        $race = $this->int($analysis['selRaceNb'] ?? null);
        if ($season === null || $race === null) {
            return null;
        }

        $pits = $this->listOf($analysis['pits'] ?? null);
        $problems = $this->listOf($analysis['problems'] ?? null);
        $setups = $this->listOf($analysis['setupsUsed'] ?? null);
        $weather = $this->arrayOf($analysis['weather'] ?? null);
        $tyre = $this->arrayOf($analysis['tyreSupplier'] ?? null);

        $result = $this->resultFrom($laps);
        $conditions = $this->conditionsFrom($laps, $problems);
        $raceSetup = $this->setupFor($setups, 'Race');
        $stints = $this->stintsFrom($laps, $pits, $problems, $analysis, $lapKm);
        $boost = $this->boostLapNumbers($laps);
        $group = $this->str($analysis['group'] ?? null);

        return [
            'user_id'     => $userId,
            'season'      => $season,
            'race'        => $race,
            'track_id'    => $this->int($analysis['trackId'] ?? null),
            'track_name'  => $this->str($analysis['trackName'] ?? null),
            'group_label' => $group,
            'level'       => $this->levelFrom($group),

            'laps_total'     => $conditions['laps'],
            'laps_completed' => $result['laps_completed'],
            'grid_pos'       => $result['start_pos'],
            'final_pos'      => $result['final_pos'],
            'points'         => $result['final_pos'] === null
                ? null
                : (self::POINTS_TABLE[$result['final_pos']] ?? 0),
            'positions_gained' => ($result['start_pos'] === null || $result['final_pos'] === null)
                ? null
                : $result['start_pos'] - $result['final_pos'],
            'dnf'          => $result['dnf'] ? 1 : 0,
            'best_lap_ms'  => $this->bestLapMs($laps),
            'best_pit_ms'  => $this->bestPitMs($pits),

            'q1_pos'     => $this->int($analysis['q1Pos'] ?? null),
            'q2_pos'     => $this->int($analysis['q2Pos'] ?? null),
            'q1_time_ms' => $this->lapTimeMs($this->str($analysis['q1Time'] ?? null)),
            'q2_time_ms' => $this->lapTimeMs($this->str($analysis['q2Time'] ?? null)),

            'pit_stops'      => count($pits),
            'start_fuel'     => $this->int($analysis['startFuel'] ?? null),
            'finish_fuel'    => $this->int($analysis['finishFuel'] ?? null),
            'finish_tyres'   => $this->int($analysis['finishTyres'] ?? null),
            'problems_count' => count($problems),

            'avg_temp'     => $conditions['avg_temp'],
            'avg_humidity' => $conditions['avg_humidity'],
            'dry_laps'     => $conditions['dry'],
            'rain_laps'    => $conditions['rain'],
            'mist_laps'    => $conditions['mist'],
            'problem_laps' => $conditions['problem'],
            'was_wet'      => $conditions['rain'] > 0 ? 1 : 0,
            'fuel_per_km'  => $this->rateFor($stints, 'fuel_per_km'),
            'tyre_per_km'  => $this->rateFor($stints, 'tyre_per_km'),

            'race_tyre'     => $raceSetup['tyres'] ?? $this->dominantTyre($laps),
            'tyre_supplier' => $this->str($tyre['name'] ?? null),

            'setup_fwing'  => $raceSetup['fwing'],
            'setup_rwing'  => $raceSetup['rwing'],
            'setup_engine' => $raceSetup['engine'],
            'setup_brakes' => $raceSetup['brakes'],
            'setup_gear'   => $raceSetup['gear'],
            'setup_susp'   => $raceSetup['susp'],

            'q1_risk'        => $this->str($analysis['q1Risk'] ?? null),
            'q2_risk'        => $this->str($analysis['q2Risk'] ?? null),
            'start_risk'     => $this->str($analysis['startRisk'] ?? null),
            'overtake_risk'  => $this->int($analysis['overtakeRisk'] ?? null),
            'defend_risk'    => $this->int($analysis['defendRisk'] ?? null),
            'clear_dry_risk' => $this->int($analysis['clearDryRisk'] ?? null),
            'clear_wet_risk' => $this->int($analysis['clearWetRisk'] ?? null),
            'problem_risk'   => $this->int($analysis['problemRisk'] ?? null),

            'boost_lap_1' => $boost[0] ?? null,
            'boost_lap_2' => $boost[1] ?? null,
            'boost_lap_3' => $boost[2] ?? null,

            'ot_attempts'        => $this->int($analysis['otAttempts'] ?? null),
            'overtakes'          => $this->int($analysis['overtakes'] ?? null),
            'ot_attempts_on_you' => $this->int($analysis['otAttemptsOnYou'] ?? null),
            'overtakes_on_you'   => $this->int($analysis['overtakesOnYou'] ?? null),

            'car_power'    => $this->int($analysis['carPower'] ?? null),
            'car_handling' => $this->int($analysis['carHandl'] ?? null),
            'car_accel'    => $this->int($analysis['carAccel'] ?? null),

            'energy_from' => $this->int($this->arrayOf($analysis['raceEnergy'] ?? null)['from'] ?? null),
            'energy_to'   => $this->int($this->arrayOf($analysis['raceEnergy'] ?? null)['to'] ?? null),

            // Variable-width blocks, read back whole for one race at a time.
            'qualifying_json' => $this->json($this->qualifyingFrom($analysis, $setups, $weather, $tyre)),
            'stints_json'     => $this->json($stints),
            'pits_json'       => $this->json($this->pitsFrom($pits, $analysis)),
            'parts_json'      => $this->json($this->partsFrom($analysis)),
            'driver_json'     => $this->json($this->driverFrom($analysis)),
            'td_json'         => $this->json($this->tdFrom($td)),
            'staff_json'      => $this->json($this->staffFrom($staff)),
            'problems_json'   => $this->json($this->problemsFrom($problems)),
        ];
    }

    /**
     * GPRO reports the group as "Rookie - 31", "Elite", "Pro - 4". The level is
     * the part before the dash.
     */
    private function levelFrom(?string $group): ?string
    {
        if ($group === null || $group === '') {
            return null;
        }

        $head = trim(explode('-', $group)[0]);
        foreach (['Rookie', 'Amateur', 'Pro', 'Master', 'Elite'] as $level) {
            if (strcasecmp($head, $level) === 0) {
                return $level;
            }
        }

        return null;
    }

    /**
     * Lap idx 0 is the grid slot, so it doubles as the starting position; the
     * last lap carrying a position is the finish.
     *
     * @param list<array<string, mixed>> $laps
     * @return array{final_pos: int|null, start_pos: int|null, laps_completed: int, dnf: bool}
     */
    private function resultFrom(array $laps): array
    {
        $startPos = null;
        $finalPos = null;
        $completed = 0;

        foreach ($laps as $lap) {
            $idx = $this->int($lap['idx'] ?? null);
            $pos = $this->int($lap['pos'] ?? null);

            if ($idx === 0) {
                $startPos = $pos;
                continue;
            }

            $completed = max($completed, $idx ?? 0);
            if ($pos !== null) {
                $finalPos = $pos;
            }
        }

        $last = $laps[count($laps) - 1];
        $dnf = false;
        foreach ($this->listOf($last['events'] ?? null) as $event) {
            $text = strtolower($this->str($event['event'] ?? null) ?? '');
            if (str_contains($text, 'retire') || str_contains($text, 'did not finish')) {
                $dnf = true;
            }
        }

        return [
            'final_pos'      => $finalPos,
            'start_pos'      => $startPos,
            'laps_completed' => $completed,
            'dnf'            => $dnf,
        ];
    }

    /**
     * Lap-condition tally: how many racing laps ran dry, in rain, in mist, and
     * how many carried a technical problem. Mirrors the counts GPRO prints in
     * its own race summary header.
     *
     * @param list<array<string, mixed>> $laps
     * @param list<array<string, mixed>> $problems
     * @return array{
     *     laps: int, dry: int, rain: int, mist: int, problem: int,
     *     avg_temp: float|null, avg_humidity: float|null
     * }
     */
    private function conditionsFrom(array $laps, array $problems): array
    {
        $counted = 0;
        $dry = 0;
        $rain = 0;
        $mist = 0;
        $tempSum = 0.0;
        $humSum = 0.0;

        foreach ($laps as $lap) {
            if (($this->int($lap['idx'] ?? null) ?? 0) === 0) {
                continue;
            }

            $counted++;
            $sky = $this->str($lap['weather'] ?? null);
            if (in_array($sky, self::WET_WEATHER, true)) {
                $rain++;
            } elseif ($sky !== null && stripos($sky, 'mist') !== false) {
                $mist++;
            } else {
                $dry++;
            }

            $tempSum += (float) ($this->int($lap['temp'] ?? null) ?? 0);
            $humSum += (float) ($this->int($lap['hum'] ?? null) ?? 0);
        }

        return [
            'laps'         => $counted,
            'dry'          => $dry,
            'rain'         => $rain,
            'mist'         => $mist,
            'problem'      => count($problems),
            'avg_temp'     => $counted > 0 ? round($tempSum / $counted, 2) : null,
            'avg_humidity' => $counted > 0 ? round($humSum / $counted, 2) : null,
        ];
    }

    /**
     * One setup row from setupsUsed, by session name ("Q1", "Q2", "Race").
     *
     * @param list<array<string, mixed>> $setups
     * @return array{
     *     fwing: int|null, rwing: int|null, engine: int|null,
     *     brakes: int|null, gear: int|null, susp: int|null, tyres: string|null
     * }
     */
    private function setupFor(array $setups, string $session): array
    {
        foreach ($setups as $setup) {
            if (strcasecmp($this->str($setup['session'] ?? null) ?? '', $session) !== 0) {
                continue;
            }

            return [
                'fwing'  => $this->int($setup['setFWing'] ?? null),
                'rwing'  => $this->int($setup['setRWing'] ?? null),
                'engine' => $this->int($setup['setEng'] ?? null),
                'brakes' => $this->int($setup['setBra'] ?? null),
                'gear'   => $this->int($setup['setGear'] ?? null),
                'susp'   => $this->int($setup['setSusp'] ?? null),
                'tyres'  => $this->str($setup['setTyres'] ?? null),
            ];
        }

        return [
            'fwing' => null, 'rwing' => null, 'engine' => null,
            'brakes' => null, 'gear' => null, 'susp' => null, 'tyres' => null,
        ];
    }

    /**
     * Both qualifying runs side by side, with the setup delta between them —
     * the comparison a manager actually wants when reading back a weekend.
     *
     * @param array<string, mixed>       $analysis
     * @param list<array<string, mixed>> $setups
     * @param array<string, mixed>       $weather
     * @param array<string, mixed>       $tyre
     * @return array<string, mixed>
     */
    private function qualifyingFrom(array $analysis, array $setups, array $weather, array $tyre): array
    {
        $supplier = $this->str($tyre['name'] ?? null);
        $sessions = [];

        foreach ([1, 2] as $n) {
            $setup = $this->setupFor($setups, 'Q' . $n);
            $sessions['q' . $n] = [
                'session'  => 'Q' . $n,
                'temp'     => $this->int($weather['q' . $n . 'Temp'] ?? null),
                'humidity' => $this->int($weather['q' . $n . 'Hum'] ?? null),
                'weather'  => $this->str($weather['q' . $n . 'Weather'] ?? null),
                'compound' => $setup['tyres'],
                'supplier' => $supplier,
                'setup'    => $setup,
                'lap_time' => $this->str($analysis['q' . $n . 'Time'] ?? null),
                'lap_ms'   => $this->lapTimeMs($this->str($analysis['q' . $n . 'Time'] ?? null)),
                'position' => $this->int($analysis['q' . $n . 'Pos'] ?? null),
                'risk'     => $this->str($analysis['q' . $n . 'Risk'] ?? null),
                'energy'   => $this->int($this->arrayOf($analysis['q' . $n . 'Energy'] ?? null)['to'] ?? null),
            ];
        }

        $delta = [];
        foreach (['fwing', 'rwing', 'engine', 'brakes', 'gear', 'susp'] as $key) {
            $a = $sessions['q1']['setup'][$key];
            $b = $sessions['q2']['setup'][$key];
            $delta[$key] = ($a === null || $b === null) ? null : $b - $a;
        }

        $q1Ms = $sessions['q1']['lap_ms'];
        $q2Ms = $sessions['q2']['lap_ms'];
        $delta['lap_ms'] = ($q1Ms === null || $q2Ms === null) ? null : $q2Ms - $q1Ms;

        return ['sessions' => array_values($sessions), 'delta' => $delta];
    }

    /**
     * Stints, split at each pit stop.
     *
     * Fuel and tyre burn are reported per kilometre rather than per lap, which
     * is what makes two tracks comparable — it is also the form GPRO's own race
     * report uses. Without a lap length both rates stay null rather than
     * silently becoming per-lap numbers wearing a per-km label.
     *
     * @param list<array<string, mixed>> $laps
     * @param list<array<string, mixed>> $pits
     * @param list<array<string, mixed>> $problems
     * @param array<string, mixed>       $analysis
     * @return list<array<string, mixed>>
     */
    private function stintsFrom(
        array $laps,
        array $pits,
        array $problems,
        array $analysis,
        ?float $lapKm,
    ): array {
        $pitLaps = [];
        foreach ($pits as $pit) {
            $lap = $this->int($pit['lap'] ?? null);
            if ($lap !== null) {
                $pitLaps[] = $lap;
            }
        }
        sort($pitLaps);

        $problemLaps = [];
        foreach ($problems as $problem) {
            $lap = $this->int($problem['lap'] ?? null);
            if ($lap !== null) {
                $problemLaps[] = $lap;
            }
        }

        // Fuel at the start of each stint: the race start, then each refill.
        $fuelIn = [$this->int($analysis['startFuel'] ?? null)];
        $tyreOut = [];
        foreach ($pits as $pit) {
            $fuelIn[] = $this->int($pit['refilledTo'] ?? null);
            $tyreOut[] = $this->int($pit['tyreCond'] ?? null);
        }
        $tyreOut[] = $this->int($analysis['finishTyres'] ?? null);

        $fuelOut = [];
        foreach ($pits as $pit) {
            $fuelOut[] = $this->int($pit['fuelLeft'] ?? null);
        }
        $fuelOut[] = $this->int($analysis['finishFuel'] ?? null);

        $lastLap = 0;
        foreach ($laps as $lap) {
            $lastLap = max($lastLap, $this->int($lap['idx'] ?? null) ?? 0);
        }

        $bounds = [];
        $from = 1;
        foreach ($pitLaps as $pitLap) {
            $bounds[] = [$from, $pitLap];
            $from = $pitLap + 1;
        }
        $bounds[] = [$from, $lastLap];

        $stints = [];
        foreach ($bounds as $i => [$start, $end]) {
            if ($end < $start) {
                continue;
            }

            $window = [];
            foreach ($laps as $lap) {
                $idx = $this->int($lap['idx'] ?? null);
                if ($idx !== null && $idx >= $start && $idx <= $end) {
                    $window[] = $lap;
                }
            }

            $count = count($window);
            $tempSum = 0.0;
            $humSum = 0.0;
            $dry = 0;
            $rain = 0;
            $mist = 0;
            $compound = null;

            foreach ($window as $lap) {
                $tempSum += (float) ($this->int($lap['temp'] ?? null) ?? 0);
                $humSum += (float) ($this->int($lap['hum'] ?? null) ?? 0);
                $sky = $this->str($lap['weather'] ?? null);
                if (in_array($sky, self::WET_WEATHER, true)) {
                    $rain++;
                } elseif ($sky !== null && stripos($sky, 'mist') !== false) {
                    $mist++;
                } else {
                    $dry++;
                }
                $compound ??= $this->str($lap['tyres'] ?? null);
            }

            $km = ($lapKm === null || $lapKm <= 0.0) ? null : $lapKm * $count;

            $fuelUsed = ($fuelIn[$i] ?? null) === null || ($fuelOut[$i] ?? null) === null
                ? null
                : $fuelIn[$i] - $fuelOut[$i];

            // Tyres start each stint fresh at 100% condition and are handed
            // back at tyreCond, so the wear of a stint is the drop from 100.
            $tyreEnd = $tyreOut[$i] ?? null;
            $tyreUsed = $tyreEnd === null ? null : 100 - $tyreEnd;

            $problemsHere = 0;
            foreach ($problemLaps as $lap) {
                if ($lap >= $start && $lap <= $end) {
                    $problemsHere++;
                }
            }

            $stints[] = [
                'number'      => $i + 1,
                'lap_from'    => $start,
                'lap_to'      => $end,
                'laps'        => $count,
                'avg_temp'    => $count > 0 ? round($tempSum / $count, 2) : null,
                'avg_humidity' => $count > 0 ? round($humSum / $count, 2) : null,
                'dry'         => $dry,
                'rain'        => $rain,
                'mist'        => $mist,
                'problems'    => $problemsHere,
                'compound'    => $compound,
                'fuel_used'   => $fuelUsed,
                'tyre_used'   => $tyreUsed,
                'fuel_per_km' => ($fuelUsed === null || $km === null || $km <= 0.0)
                    ? null
                    : round($fuelUsed / $km, 3),
                'tyre_per_km' => ($tyreUsed === null || $km === null || $km <= 0.0)
                    ? null
                    : round($tyreUsed / $km, 3),
            ];
        }

        return $stints;
    }

    /**
     * Race-wide burn rate: the mean of the per-stint rates, weighted by stint
     * length so a two-lap splash-and-dash doesn't count as much as a long run.
     *
     * @param list<array<string, mixed>> $stints
     */
    private function rateFor(array $stints, string $key): ?float
    {
        $weighted = 0.0;
        $laps = 0;

        foreach ($stints as $stint) {
            $rate = $this->float($stint[$key] ?? null);
            $count = $this->int($stint['laps'] ?? null) ?? 0;
            if ($rate === null || $count <= 0) {
                continue;
            }
            $weighted += $rate * $count;
            $laps += $count;
        }

        return $laps > 0 ? round($weighted / $laps, 3) : null;
    }

    /**
     * @param list<array<string, mixed>> $pits
     * @param array<string, mixed>       $analysis
     * @return list<array<string, mixed>>
     */
    private function pitsFrom(array $pits, array $analysis): array
    {
        $out = [];
        foreach ($pits as $i => $pit) {
            $left = $this->int($pit['fuelLeft'] ?? null);
            $to = $this->int($pit['refilledTo'] ?? null);

            $out[] = [
                'number'    => $i + 1,
                'lap'       => $this->int($pit['lap'] ?? null),
                'reason'    => $this->str($pit['reason'] ?? null),
                'fuel_left' => $left,
                'refilled_to' => $to,
                'fuel_added' => ($left === null || $to === null) ? null : $to - $left,
                'tyre_cond' => $this->int($pit['tyreCond'] ?? null),
                'pit_time'  => $this->float($pit['pitTime'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * Per-part level and the wear it picked up over the race — the block that
     * drives every "what do I replace next" decision.
     *
     * @param array<string, mixed> $analysis
     * @return list<array<string, mixed>>
     */
    private function partsFrom(array $analysis): array
    {
        $out = [];
        foreach (self::PARTS as $key => $label) {
            $part = $this->arrayOf($analysis[$key] ?? null);
            if ($part === []) {
                continue;
            }

            $start = $this->int($part['startWear'] ?? null);
            $finish = $this->int($part['finishWear'] ?? null);

            $out[] = [
                'key'   => $key,
                'label' => $label,
                'level' => $this->int($part['lvl'] ?? null),
                'wear_start' => $start,
                'wear_end'   => $finish,
                'wear_gain'  => ($start === null || $finish === null) ? null : $finish - $start,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $analysis
     * @return array<string, mixed>
     */
    private function driverFrom(array $analysis): array
    {
        $driver = $this->arrayOf($analysis['driver'] ?? null);
        $changes = $this->arrayOf($analysis['driverChanges'] ?? null);
        $energy = $this->arrayOf($analysis['raceEnergy'] ?? null);

        $attrs = [];
        foreach (self::DRIVER_ATTRS as $key => $label) {
            $post = $this->int($driver[$key] ?? null);
            $delta = $this->int($changes[$key] ?? null);

            $attrs[] = [
                'key'   => $key,
                'label' => $label,
                'pre'   => ($post === null || $delta === null) ? null : $post - $delta,
                'post'  => $post,
                'delta' => $delta,
            ];
        }

        return [
            'id'         => $this->int($driver['id'] ?? null),
            'name'       => $this->str($driver['name'] ?? null),
            'age'        => $this->int($driver['age'] ?? null),
            'attributes' => $attrs,
            'energy'     => [
                'pre'  => $this->int($energy['from'] ?? null),
                'post' => $this->int($energy['to'] ?? null),
            ],
        ];
    }

    /**
     * The TD profile is the CURRENT one — GPRO exposes no historical TD — so it
     * is stored with the race only as the best available approximation, and the
     * UI labels it as such.
     *
     * @param array<string, mixed> $td
     * @return array<string, mixed>
     */
    private function tdFrom(array $td): array
    {
        if ($td === []) {
            return [];
        }

        $keys = [
            'overall' => 'Overall', 'leadership' => 'Leadership',
            'mechanics' => 'Mechanics', 'electronics' => 'Electronics',
            'aerodynamics' => 'Aerodynamics', 'experience' => 'Experience',
            'pitCoord' => 'Pit coordination', 'motivation' => 'Motivation',
        ];

        $attrs = [];
        foreach ($keys as $key => $label) {
            $attrs[] = ['label' => $label, 'value' => $this->int($td[$key] ?? null)];
        }

        return [
            'name'       => $this->str($td['name'] ?? null),
            'age'        => $this->int($td['age'] ?? null),
            'attributes' => $attrs,
        ];
    }

    /**
     * Facilities and staff as they stood when the race was captured. Like the
     * TD block this is a current-state snapshot, not a historical one.
     *
     * @param array<string, mixed> $staff
     * @return array<string, mixed>
     */
    private function staffFrom(array $staff): array
    {
        if ($staff === []) {
            return [];
        }

        $facilities = [
            'windTunnel' => 'Wind tunnel', 'pitStop' => 'Pit stop',
            'workshop' => 'Workshop', 'design' => 'Design',
            'engineering' => 'Engineering', 'chemical' => 'Chemical',
            'commercial' => 'Commercial',
        ];
        $people = [
            'experience' => 'Experience', 'motivation' => 'Motivation',
            'technical' => 'Technical', 'stress' => 'Stress',
            'concentration' => 'Concentration', 'efficiency' => 'Efficiency',
        ];

        $out = ['facilities' => [], 'staff' => []];
        foreach ($facilities as $key => $label) {
            $value = $this->int($staff[$key] ?? null);
            if ($value !== null) {
                $out['facilities'][] = ['label' => $label, 'value' => $value];
            }
        }
        foreach ($people as $key => $label) {
            $value = $this->int($staff[$key] ?? null);
            if ($value !== null) {
                $out['staff'][] = ['label' => $label, 'value' => $value];
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $problems
     * @return list<array<string, mixed>>
     */
    private function problemsFrom(array $problems): array
    {
        $out = [];
        foreach ($problems as $problem) {
            $out[] = [
                'lap'    => $this->int($problem['lap'] ?? null),
                'reason' => $this->str($problem['reason'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * Lap numbers the boost was used on. GPRO allows at most three, and a
     * manager reads them back as "which laps", not "how many".
     *
     * @param list<array<string, mixed>> $laps
     * @return list<int>
     */
    private function boostLapNumbers(array $laps): array
    {
        $out = [];
        foreach ($laps as $lap) {
            if (($this->int($lap['boostLap'] ?? null) ?? 0) > 0) {
                $idx = $this->int($lap['idx'] ?? null);
                if ($idx !== null) {
                    $out[] = $idx;
                }
            }
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $laps */
    private function bestLapMs(array $laps): ?int
    {
        $best = null;
        foreach ($laps as $lap) {
            if (($this->int($lap['idx'] ?? null) ?? 0) === 0) {
                continue;
            }
            $ms = $this->lapTimeMs($this->str($lap['lapTime'] ?? null));
            if ($ms !== null && ($best === null || $ms < $best)) {
                $best = $ms;
            }
        }

        return $best;
    }

    /** @param list<array<string, mixed>> $pits */
    private function bestPitMs(array $pits): ?int
    {
        $best = null;
        foreach ($pits as $pit) {
            $seconds = $this->float($pit['pitTime'] ?? null);
            if ($seconds === null) {
                continue;
            }
            $ms = (int) round($seconds * 1000);
            if ($best === null || $ms < $best) {
                $best = $ms;
            }
        }

        return $best;
    }

    /** @param list<array<string, mixed>> $laps */
    private function dominantTyre(array $laps): ?string
    {
        /** @var array<string, int> $tally */
        $tally = [];
        foreach ($laps as $lap) {
            $tyre = $this->str($lap['tyres'] ?? null);
            if ($tyre === null) {
                continue;
            }
            $tally[$tyre] = ($tally[$tyre] ?? 0) + 1;
        }

        if ($tally === []) {
            return null;
        }

        arsort($tally);
        return (string) array_key_first($tally);
    }

    private function json(mixed $value): string
    {
        $encoded = json_encode($value, JSON_THROW_ON_ERROR);
        return $encoded;
    }
}
