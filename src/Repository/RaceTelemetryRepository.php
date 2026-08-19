<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Storage + aggregation for the anonymous race-telemetry corpus.
 *
 * There is no findByUser(), and there cannot be one: race_telemetry holds no
 * user column. Every read here is an aggregate over the whole dataset, sliced
 * by race characteristics (level, weather, tyre, risk…) only.
 */
class RaceTelemetryRepository
{
    /** Columns written by insertIfNew(), in a fixed order. */
    private const array COLUMNS = [
        'season', 'race', 'level', 'group_label', 'track_id', 'track_name',
        'final_pos', 'start_pos', 'points', 'q1_pos', 'q2_pos',
        'q1_time_ms', 'q2_time_ms', 'positions_gained', 'dnf', 'laps_completed',
        'driver_id', 'driver_oa', 'driver_con', 'driver_tal', 'driver_agg',
        'driver_exp', 'driver_tei', 'driver_sta', 'driver_cha', 'driver_mot',
        'driver_rep', 'driver_wei',
        'q1_risk', 'q2_risk', 'start_risk', 'overtake_risk', 'defend_risk',
        'clear_dry_risk', 'clear_wet_risk', 'problem_risk',
        'mistake_seconds', 'ot_attempts', 'overtakes', 'ot_attempts_on_you',
        'overtakes_on_you',
        'has_td', 'td_overall', 'td_leadership', 'td_mechanics', 'td_electronics',
        'td_aerodynamics', 'td_pit_coord', 'td_experience', 'td_motivation',
        'race_tyre', 'tyre_supplier', 'tyre_peak_temp', 'tyre_dry_perf',
        'tyre_wet_perf', 'tyre_durability', 'tyre_warmup',
        'was_wet', 'wet_lap_share', 'avg_temp', 'avg_humidity',
        'q1_weather', 'q2_weather',
        'pit_stops', 'start_fuel', 'finish_fuel', 'finish_tyres',
        'avg_pit_time', 'boost_laps',
        'car_power', 'car_handling', 'car_accel', 'avg_part_level',
        'total_wear_gain', 'problems_count',
        'setup_fwing', 'setup_rwing', 'setup_engine', 'setup_brakes',
        'setup_gear', 'setup_susp',
        'race_energy_from', 'race_energy_to',
    ];

    /** Driver attributes the correlation report walks. */
    public const array DRIVER_ATTRIBUTES = [
        'driver_oa'  => 'Overall',
        'driver_con' => 'Concentration',
        'driver_tal' => 'Talent',
        'driver_agg' => 'Aggressiveness',
        'driver_exp' => 'Experience',
        'driver_tei' => 'Technical insight',
        'driver_sta' => 'Stamina',
        'driver_cha' => 'Charisma',
        'driver_mot' => 'Motivation',
        'driver_rep' => 'Reputation',
        'driver_wei' => 'Weight',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Insert one anonymous race row, ignoring a repeat of a race already in
     * the corpus. De-duplication is enforced by the natural-key unique index,
     * so concurrent syncs of the same race collapse safely.
     *
     * @param array<string, mixed> $row
     * @return bool true when a new row landed
     */
    public function insertIfNew(array $row): bool
    {
        $columns = implode(', ', self::COLUMNS);
        $placeholders = implode(', ', array_map(
            static fn (string $c): string => ':' . $c,
            self::COLUMNS
        ));

        $stmt = $this->pdo->prepare(
            "INSERT OR IGNORE INTO race_telemetry ({$columns}) VALUES ({$placeholders})"
        );

        $params = [];
        foreach (self::COLUMNS as $column) {
            $params[$column] = $row[$column] ?? null;
        }

        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /** Total races in the corpus. */
    public function total(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM race_telemetry');
        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }

    /**
     * Corpus size and coverage per level — the headline "how much do we know"
     * figure, and the guard against reading noise as signal.
     *
     * @return list<array<string, mixed>>
     */
    public function levelSummary(): array
    {
        $sql = "
            SELECT level,
                   COUNT(*)                AS races,
                   COUNT(DISTINCT driver_id) AS drivers,
                   COUNT(DISTINCT track_id)  AS tracks,
                   ROUND(AVG(final_pos), 2)  AS avg_pos,
                   ROUND(AVG(points), 2)     AS avg_points,
                   SUM(was_wet)              AS wet_races,
                   SUM(dnf)                  AS dnfs,
                   MIN(season)               AS first_season,
                   MAX(season)               AS last_season
            FROM race_telemetry
            GROUP BY level
            ORDER BY races DESC
        ";

        return $this->rows($sql);
    }

    /**
     * Pearson correlation between each driver attribute and an outcome,
     * computed per level in SQL.
     *
     * Position is "lower is better", so a NEGATIVE r means a higher attribute
     * goes with a better finish. The caller flips the sign for presentation.
     *
     * @return list<array<string, mixed>>
     */
    public function driverAttributeCorrelations(string $outcome = 'final_pos', int $minSample = 5): array
    {
        $outcome = $this->safeOutcome($outcome);
        $out = [];

        foreach (array_keys(self::DRIVER_ATTRIBUTES) as $attr) {
            $sql = "
                SELECT level,
                       COUNT(*) AS n,
                       ROUND(AVG({$attr}), 2) AS avg_attr,
                       (
                         (COUNT(*) * SUM({$attr} * {$outcome}) - SUM({$attr}) * SUM({$outcome}))
                         /
                         NULLIF(
                           (
                             SQRT(NULLIF(COUNT(*) * SUM({$attr} * {$attr}) - SUM({$attr}) * SUM({$attr}), 0))
                             *
                             SQRT(NULLIF(
                               COUNT(*) * SUM({$outcome} * {$outcome})
                               - SUM({$outcome}) * SUM({$outcome}), 0
                             ))
                           ), 0
                         )
                       ) AS r
                FROM race_telemetry
                WHERE {$attr} IS NOT NULL AND {$outcome} IS NOT NULL AND dnf = 0
                GROUP BY level
                HAVING COUNT(*) >= :min
                ORDER BY level
            ";

            foreach ($this->rows($sql, ['min' => $minSample]) as $row) {
                $row['attribute'] = $attr;
                $row['label'] = self::DRIVER_ATTRIBUTES[$attr];
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Tyre compound performance, segmented by level and wet/dry — the core
     * "which tyre when" question.
     *
     * @return list<array<string, mixed>>
     */
    public function tyrePerformance(int $minSample = 3): array
    {
        $sql = "
            SELECT level,
                   race_tyre,
                   was_wet,
                   COUNT(*)                 AS n,
                   ROUND(AVG(final_pos), 2) AS avg_pos,
                   ROUND(AVG(points), 2)    AS avg_points,
                   ROUND(AVG(positions_gained), 2) AS avg_gained,
                   ROUND(AVG(finish_tyres), 1)     AS avg_finish_tyres
            FROM race_telemetry
            WHERE race_tyre IS NOT NULL AND final_pos IS NOT NULL
            GROUP BY level, race_tyre, was_wet
            HAVING COUNT(*) >= :min
            ORDER BY level, was_wet, avg_pos
        ";

        return $this->rows($sql, ['min' => $minSample]);
    }

    /**
     * With-TD vs without-TD, per level. The comparison only means anything
     * when both arms exist at that level, which the caller checks.
     *
     * @return list<array<string, mixed>>
     */
    public function technicalDirectorEffect(int $minSample = 3): array
    {
        $sql = "
            SELECT level,
                   has_td,
                   COUNT(*)                 AS n,
                   ROUND(AVG(final_pos), 2) AS avg_pos,
                   ROUND(AVG(points), 2)    AS avg_points,
                   ROUND(AVG(positions_gained), 2) AS avg_gained,
                   ROUND(AVG(problems_count), 2)   AS avg_problems,
                   ROUND(AVG(avg_pit_time), 2)     AS avg_pit_time
            FROM race_telemetry
            WHERE final_pos IS NOT NULL
            GROUP BY level, has_td
            HAVING COUNT(*) >= :min
            ORDER BY level, has_td DESC
        ";

        return $this->rows($sql, ['min' => $minSample]);
    }

    /**
     * Average outcome by the value of one risk dimension, per level.
     * `$column` is whitelisted — never interpolate caller input here.
     *
     * @return list<array<string, mixed>>
     */
    public function riskPerformance(string $column, int $minSample = 3): array
    {
        $allowed = [
            'q1_risk', 'q2_risk', 'start_risk',
            'overtake_risk', 'defend_risk', 'clear_dry_risk', 'clear_wet_risk',
        ];

        if (!in_array($column, $allowed, true)) {
            return [];
        }

        $sql = "
            SELECT level,
                   {$column} AS risk_value,
                   COUNT(*)                 AS n,
                   ROUND(AVG(final_pos), 2) AS avg_pos,
                   ROUND(AVG(q2_pos), 2)    AS avg_q2_pos,
                   ROUND(AVG(points), 2)    AS avg_points,
                   ROUND(AVG(positions_gained), 2) AS avg_gained,
                   ROUND(AVG(overtakes), 2)        AS avg_overtakes,
                   SUM(dnf)                        AS dnfs
            FROM race_telemetry
            WHERE {$column} IS NOT NULL
            GROUP BY level, {$column}
            HAVING COUNT(*) >= :min
            ORDER BY level, avg_pos
        ";

        return $this->rows($sql, ['min' => $minSample]);
    }

    /**
     * Pit-stop count vs result, per level and wetness — the strategy question.
     *
     * @return list<array<string, mixed>>
     */
    public function strategyPerformance(int $minSample = 3): array
    {
        $sql = "
            SELECT level,
                   pit_stops,
                   was_wet,
                   COUNT(*)                 AS n,
                   ROUND(AVG(final_pos), 2) AS avg_pos,
                   ROUND(AVG(points), 2)    AS avg_points,
                   ROUND(AVG(positions_gained), 2) AS avg_gained,
                   ROUND(AVG(start_fuel), 1)       AS avg_start_fuel
            FROM race_telemetry
            WHERE pit_stops IS NOT NULL AND final_pos IS NOT NULL
            GROUP BY level, pit_stops, was_wet
            HAVING COUNT(*) >= :min
            ORDER BY level, was_wet, avg_pos
        ";

        return $this->rows($sql, ['min' => $minSample]);
    }

    /**
     * Driver mistake time bucketed against results — "do mistakes cost
     * positions, and how much".
     *
     * @return list<array<string, mixed>>
     */
    public function mistakeImpact(int $minSample = 3): array
    {
        $sql = "
            SELECT level,
                   CASE
                     WHEN mistake_seconds < 1 THEN '0-1s'
                     WHEN mistake_seconds < 2 THEN '1-2s'
                     WHEN mistake_seconds < 4 THEN '2-4s'
                     ELSE '4s+'
                   END AS bucket,
                   COUNT(*)                 AS n,
                   ROUND(AVG(mistake_seconds), 2) AS avg_mistake,
                   ROUND(AVG(final_pos), 2) AS avg_pos,
                   ROUND(AVG(q2_pos), 2)    AS avg_q2_pos,
                   ROUND(AVG(points), 2)    AS avg_points
            FROM race_telemetry
            WHERE mistake_seconds IS NOT NULL AND final_pos IS NOT NULL
            GROUP BY level, bucket
            HAVING COUNT(*) >= :min
            ORDER BY level, avg_mistake
        ";

        return $this->rows($sql, ['min' => $minSample]);
    }

    /**
     * The "driver prototype" per level: mean attributes of races that finished
     * in the top three, next to the mean of everything else. The gap between
     * the two columns is the shape of a winning driver at that level.
     *
     * @return list<array<string, mixed>>
     */
    public function winningDriverPrototype(int $minSample = 3): array
    {
        $attrs = array_keys(self::DRIVER_ATTRIBUTES);
        $select = [];
        foreach ($attrs as $attr) {
            $select[] = "ROUND(AVG({$attr}), 1) AS {$attr}";
        }
        $selectSql = implode(",\n                   ", $select);

        $sql = "
            SELECT level,
                   CASE WHEN final_pos <= 3 THEN 'podium' ELSE 'rest' END AS band,
                   COUNT(*) AS n,
                   {$selectSql}
            FROM race_telemetry
            WHERE final_pos IS NOT NULL AND dnf = 0
            GROUP BY level, band
            HAVING COUNT(*) >= :min
            ORDER BY level, band
        ";

        return $this->rows($sql, ['min' => $minSample]);
    }

    /**
     * Only outcome columns may be interpolated into the correlation SQL.
     * Anything else falls back to final_pos rather than reaching the query.
     */
    private function safeOutcome(string $outcome): string
    {
        $allowed = ['final_pos', 'points', 'q1_pos', 'q2_pos', 'positions_gained'];
        return in_array($outcome, $allowed, true) ? $outcome : 'final_pos';
    }

    /**
     * Every threshold here lands in a HAVING COUNT(*) >= :min comparison, and
     * SQLite will not compare an integer against a *string* bound parameter —
     * PDO's default binding would make each such query silently return zero
     * rows. Bind integers explicitly as PARAM_INT.
     *
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $name => $value) {
            $stmt->bindValue(
                ':' . $name,
                $value,
                is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }

        $stmt->execute();
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }
}
