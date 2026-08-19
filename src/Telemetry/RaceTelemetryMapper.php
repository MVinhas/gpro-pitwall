<?php

declare(strict_types=1);

namespace App\Telemetry;

/**
 * Turns a raw GPRO RaceAnalysis payload (plus the already-cached TD profile)
 * into a flat, anonymous telemetry row.
 *
 * Anonymity is a property of this class's output, not of a downstream filter:
 * nothing user-identifying is ever produced here, so nothing user-identifying
 * can reach the table. The driver id is a GPRO *driver* id (a market entity
 * many managers can have owned in turn), not a manager id.
 *
 * Everything comes from payloads the app already fetches — no extra API call.
 */
final class RaceTelemetryMapper
{
    /** GPRO points per finishing position (1st..10th); 0 outside the top 10. */
    private const array POINTS_TABLE = [
        1 => 25, 2 => 18, 3 => 15, 4 => 12, 5 => 10,
        6 => 8, 7 => 6, 8 => 4, 9 => 2, 10 => 1,
    ];

    /** Lap weather strings GPRO uses for a wet track. */
    private const array WET_WEATHER = ['Rain', 'Heavy Rain', 'Light Rain'];

    /**
     * @param array<string, mixed> $analysis RaceAnalysis payload
     * @param array<string, mixed> $td       TDProfile payload ([] when none)
     * @return array<string, mixed>|null     null when the payload is unusable
     */
    public function map(array $analysis, array $td = []): ?array
    {
        // The NA payload (non-supporter, or no race run) has no driver block
        // and no laps. Nothing to learn from it.
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

        $group = $this->str($analysis['group'] ?? null);
        $level = $this->levelFrom($group);
        if ($level === null) {
            return null;
        }

        $result = $this->resultFrom($laps);
        $weather = $this->weatherFrom($laps, $this->arrayOf($analysis['weather'] ?? null));
        $tyre = $this->arrayOf($analysis['tyreSupplier'] ?? null);
        $raceSetup = $this->raceSetupFrom($this->listOf($analysis['setupsUsed'] ?? null));
        $pits = $this->listOf($analysis['pits'] ?? null);
        $parts = $this->partsFrom($analysis);
        $energy = $this->arrayOf($analysis['raceEnergy'] ?? null);

        return [
            'season'      => $season,
            'race'        => $race,
            'level'       => $level,
            'group_label' => $group,
            'track_id'    => $this->int($analysis['trackId'] ?? null),
            'track_name'  => $this->str($analysis['trackName'] ?? null),

            'final_pos'        => $result['final_pos'],
            'start_pos'        => $result['start_pos'],
            'points'           => $result['final_pos'] === null
                ? null
                : (self::POINTS_TABLE[$result['final_pos']] ?? 0),
            'q1_pos'           => $this->int($analysis['q1Pos'] ?? null),
            'q2_pos'           => $this->int($analysis['q2Pos'] ?? null),
            'q1_time_ms'       => $this->lapTimeMs($this->str($analysis['q1Time'] ?? null)),
            'q2_time_ms'       => $this->lapTimeMs($this->str($analysis['q2Time'] ?? null)),
            'positions_gained' => ($result['start_pos'] === null || $result['final_pos'] === null)
                ? null
                : $result['start_pos'] - $result['final_pos'],
            'dnf'              => $result['dnf'] ? 1 : 0,
            'laps_completed'   => $result['laps_completed'],

            'driver_id'  => $this->int($driver['id'] ?? null),
            'driver_oa'  => $this->int($driver['OA'] ?? null),
            'driver_con' => $this->int($driver['con'] ?? null),
            'driver_tal' => $this->int($driver['tal'] ?? null),
            'driver_agg' => $this->int($driver['agr'] ?? null),
            'driver_exp' => $this->int($driver['exp'] ?? null),
            'driver_tei' => $this->int($driver['tei'] ?? null),
            'driver_sta' => $this->int($driver['sta'] ?? null),
            'driver_cha' => $this->int($driver['cha'] ?? null),
            'driver_mot' => $this->int($driver['mot'] ?? null),
            'driver_rep' => $this->int($driver['rep'] ?? null),
            'driver_wei' => $this->int($driver['wei'] ?? null),

            'q1_risk'        => $this->str($analysis['q1Risk'] ?? null),
            'q2_risk'        => $this->str($analysis['q2Risk'] ?? null),
            'start_risk'     => $this->str($analysis['startRisk'] ?? null),
            'overtake_risk'  => $this->int($analysis['overtakeRisk'] ?? null),
            'defend_risk'    => $this->int($analysis['defendRisk'] ?? null),
            'clear_dry_risk' => $this->int($analysis['clearDryRisk'] ?? null),
            'clear_wet_risk' => $this->int($analysis['clearWetRisk'] ?? null),
            'problem_risk'   => $this->int($analysis['problemRisk'] ?? null),

            'mistake_seconds'    => $this->mistakeSecondsFrom($this->listOf($analysis['practiceLaps'] ?? null)),
            'ot_attempts'        => $this->int($analysis['otAttempts'] ?? null),
            'overtakes'          => $this->int($analysis['overtakes'] ?? null),
            'ot_attempts_on_you' => $this->int($analysis['otAttemptsOnYou'] ?? null),
            'overtakes_on_you'   => $this->int($analysis['overtakesOnYou'] ?? null),

            'has_td'           => $td === [] ? 0 : 1,
            'td_overall'       => $this->int($td['overall'] ?? null),
            'td_leadership'    => $this->int($td['leadership'] ?? null),
            'td_mechanics'     => $this->int($td['mechanics'] ?? null),
            'td_electronics'   => $this->int($td['electronics'] ?? null),
            'td_aerodynamics'  => $this->int($td['aerodynamics'] ?? null),
            'td_pit_coord'     => $this->int($td['pitCoord'] ?? null),
            'td_experience'    => $this->int($td['experience'] ?? null),
            'td_motivation'    => $this->int($td['motivation'] ?? null),

            'race_tyre'       => $raceSetup['tyres'] ?? $this->dominantTyre($laps),
            'tyre_supplier'   => $this->str($tyre['name'] ?? null),
            'tyre_peak_temp'  => $this->int($tyre['peakTemp'] ?? null),
            'tyre_dry_perf'   => $this->int($tyre['dryPerf'] ?? null),
            'tyre_wet_perf'   => $this->int($tyre['wetPerf'] ?? null),
            'tyre_durability' => $this->int($tyre['durability'] ?? null),
            'tyre_warmup'     => $this->int($tyre['warmup'] ?? null),

            'was_wet'       => $weather['was_wet'],
            'wet_lap_share' => $weather['wet_lap_share'],
            'avg_temp'      => $weather['avg_temp'],
            'avg_humidity'  => $weather['avg_humidity'],
            'q1_weather'    => $weather['q1_weather'],
            'q2_weather'    => $weather['q2_weather'],

            'pit_stops'    => count($pits),
            'start_fuel'   => $this->int($analysis['startFuel'] ?? null),
            'finish_fuel'  => $this->int($analysis['finishFuel'] ?? null),
            'finish_tyres' => $this->int($analysis['finishTyres'] ?? null),
            'avg_pit_time' => $this->avgPitTime($pits),
            'boost_laps'   => $this->boostLaps($laps),

            'car_power'       => $this->int($analysis['carPower'] ?? null),
            'car_handling'    => $this->int($analysis['carHandl'] ?? null),
            'car_accel'       => $this->int($analysis['carAccel'] ?? null),
            'avg_part_level'  => $parts['avg_level'],
            'total_wear_gain' => $parts['wear_gain'],
            'problems_count'  => count($this->listOf($analysis['problems'] ?? null)),

            'setup_fwing'  => $raceSetup['fwing'],
            'setup_rwing'  => $raceSetup['rwing'],
            'setup_engine' => $raceSetup['engine'],
            'setup_brakes' => $raceSetup['brakes'],
            'setup_gear'   => $raceSetup['gear'],
            'setup_susp'   => $raceSetup['susp'],

            'race_energy_from' => $this->int($energy['from'] ?? null),
            'race_energy_to'   => $this->int($energy['to'] ?? null),
        ];
    }

    /**
     * GPRO reports the group as "Rookie - 31", "Elite", "Pro - 4"… The level
     * is the part before the dash; the number is the specific group, which we
     * keep separately in group_label for finer segmentation.
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
     * Final position is the last lap's pos — RaceAnalysis has no dedicated
     * result field. Lap idx 0 is the grid slot, so it doubles as start pos.
     *
     * A DNF shows up as a race that ended before the field did; GPRO marks the
     * retirement in the lap events, so treat a non-empty final-lap event list
     * mentioning retirement as a DNF signal alongside a missing position.
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
     * Weather is taken from the laps actually run, not the forecast: the
     * forecast is a range, the laps are what happened. wet_lap_share lets a
     * later query separate "one wet lap" from "monsoon".
     *
     * @param list<array<string, mixed>> $laps
     * @param array<string, mixed> $forecast
     * @return array{
     *     was_wet: int,
     *     wet_lap_share: float|null,
     *     avg_temp: float|null,
     *     avg_humidity: float|null,
     *     q1_weather: string|null,
     *     q2_weather: string|null
     * }
     */
    private function weatherFrom(array $laps, array $forecast): array
    {
        $wet = 0;
        $counted = 0;
        $tempSum = 0;
        $humSum = 0;

        foreach ($laps as $lap) {
            if (($this->int($lap['idx'] ?? null) ?? 0) === 0) {
                continue;
            }

            $counted++;
            if (in_array($this->str($lap['weather'] ?? null), self::WET_WEATHER, true)) {
                $wet++;
            }
            $tempSum += $this->int($lap['temp'] ?? null) ?? 0;
            $humSum += $this->int($lap['hum'] ?? null) ?? 0;
        }

        return [
            'was_wet'       => $wet > 0 ? 1 : 0,
            'wet_lap_share' => $counted > 0 ? round($wet / $counted, 4) : null,
            'avg_temp'      => $counted > 0 ? round($tempSum / $counted, 2) : null,
            'avg_humidity'  => $counted > 0 ? round($humSum / $counted, 2) : null,
            'q1_weather'    => $this->str($forecast['q1Weather'] ?? null),
            'q2_weather'    => $this->str($forecast['q2Weather'] ?? null),
        ];
    }

    /**
     * The setup actually raced (the "Race" row of setupsUsed), which is the
     * one that correlates with the result — Q1/Q2 rows are qualifying trims.
     *
     * @param list<array<string, mixed>> $setups
     * @return array{
     *     fwing: int|null,
     *     rwing: int|null,
     *     engine: int|null,
     *     brakes: int|null,
     *     gear: int|null,
     *     susp: int|null,
     *     tyres: string|null
     * }
     */
    private function raceSetupFrom(array $setups): array
    {
        $empty = [
            'fwing' => null, 'rwing' => null, 'engine' => null,
            'brakes' => null, 'gear' => null, 'susp' => null, 'tyres' => null,
        ];

        foreach ($setups as $setup) {
            if (strcasecmp($this->str($setup['session'] ?? null) ?? '', 'Race') !== 0) {
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

        return $empty;
    }

    /**
     * Driver mistake time, summed across practice laps (misTime is per lap,
     * in seconds). This is the app's only quantified read on driver error.
     *
     * @param list<array<string, mixed>> $practiceLaps
     */
    private function mistakeSecondsFrom(array $practiceLaps): ?float
    {
        $total = 0.0;
        $seen = false;

        foreach ($practiceLaps as $lap) {
            $raw = $lap['misTime'] ?? null;
            if (!is_string($raw) && !is_float($raw) && !is_int($raw)) {
                continue;
            }
            $value = (float) $raw;
            $total += $value;
            $seen = true;
        }

        return $seen ? round($total, 3) : null;
    }

    /**
     * Tyre compound most laps were run on — the fallback when setupsUsed has
     * no Race row (e.g. an older payload shape).
     *
     * @param list<array<string, mixed>> $laps
     */
    private function dominantTyre(array $laps): ?string
    {
        /** @var array<string, int> $tally */
        $tally = [];
        foreach ($laps as $lap) {
            $tyre = $this->str($lap['tyres'] ?? null);
            if ($tyre === null || $tyre === '') {
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

    /** @param list<array<string, mixed>> $pits */
    private function avgPitTime(array $pits): ?float
    {
        $total = 0.0;
        $count = 0;
        foreach ($pits as $pit) {
            $raw = $pit['pitTime'] ?? null;
            if (!is_string($raw) && !is_float($raw) && !is_int($raw)) {
                continue;
            }
            $total += (float) $raw;
            $count++;
        }

        return $count > 0 ? round($total / $count, 3) : null;
    }

    /** @param list<array<string, mixed>> $laps */
    private function boostLaps(array $laps): int
    {
        $count = 0;
        foreach ($laps as $lap) {
            if (($this->int($lap['boostLap'] ?? null) ?? 0) > 0) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Car parts as an aggregate: mean level (a proxy for car quality at this
     * level) and total wear accumulated across the race.
     *
     * @param array<string, mixed> $analysis
     * @return array{avg_level: float|null, wear_gain: int|null}
     */
    private function partsFrom(array $analysis): array
    {
        $names = [
            'chassis', 'engine', 'FWing', 'RWing', 'underbody',
            'sidepods', 'cooling', 'gear', 'brakes', 'susp', 'electronics',
        ];

        $levels = [];
        $wear = 0;
        $sawWear = false;

        foreach ($names as $name) {
            $part = $this->arrayOf($analysis[$name] ?? null);
            if ($part === []) {
                continue;
            }

            $lvl = $this->int($part['lvl'] ?? null);
            if ($lvl !== null) {
                $levels[] = $lvl;
            }

            $start = $this->int($part['startWear'] ?? null);
            $finish = $this->int($part['finishWear'] ?? null);
            if ($start !== null && $finish !== null) {
                $wear += $finish - $start;
                $sawWear = true;
            }
        }

        return [
            'avg_level' => $levels === [] ? null : round(array_sum($levels) / count($levels), 2),
            'wear_gain' => $sawWear ? $wear : null,
        ];
    }

    /**
     * "1:59.502s" / "1:59.502" -> milliseconds. Used as part of the natural
     * de-duplication key, so a stable parse matters more than precision.
     */
    private function lapTimeMs(?string $time): ?int
    {
        if ($time === null) {
            return null;
        }

        $clean = trim(rtrim(trim($time), 's'));
        if ($clean === '' || $clean === '-') {
            return null;
        }

        if (preg_match('/^(\d+):(\d+)\.(\d+)$/', $clean, $m) === 1) {
            return ((int) $m[1]) * 60000
                + ((int) $m[2]) * 1000
                + (int) str_pad(substr($m[3], 0, 3), 3, '0');
        }

        if (preg_match('/^(\d+)\.(\d+)$/', $clean, $m) === 1) {
            return ((int) $m[1]) * 1000 + (int) str_pad(substr($m[2], 0, 3), 3, '0');
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function arrayOf(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @return list<array<string, mixed>> */
    private function listOf(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $out[] = $item;
            }
        }

        return $out;
    }

    private function int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (int) trim($value);
        }
        if (is_float($value)) {
            return (int) $value;
        }

        return null;
    }

    private function str(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed === '' ? null : $trimmed;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }
}
