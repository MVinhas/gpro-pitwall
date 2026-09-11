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
        'driver_rep', 'driver_wei', 'driver_age',
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

    /** Filters the Bird's Eye View may apply to the anonymous corpus. */
    private const array EQUALITY_FILTERS = [
        'track'    => 'track_id',
        'season'   => 'season',
        'race'     => 'race',
        'supplier' => 'tyre_supplier',
        'compound' => 'race_tyre',
        'level'    => 'level',
    ];

    /**
     * Same "how impressive was this race" blend the personal archive sorts by,
     * so a manager comparing their own row against the field is reading one
     * scale, not two.
     */
    public const string IMPRESSIVENESS_SQL = "
        CASE WHEN dnf = 1 THEN -20 ELSE
            (COALESCE(points, 0) * 2)
            + (COALESCE(positions_gained, 0) * 4)
            + (CASE
                 WHEN final_pos = 1 THEN 20
                 WHEN final_pos <= 3 THEN 10
                 WHEN final_pos <= 6 THEN 5
                 ELSE 0
               END)
        END
    ";

    /** Sort keys accepted by browse(), mapped to ORDER BY clauses. */
    private const array SORTS = [
        'impressive' => 'score DESC, season DESC, race DESC',
        'recent'     => 'season DESC, race DESC',
        'position'   => 'final_pos IS NULL, final_pos ASC, season DESC',
        'gained'     => 'positions_gained IS NULL, positions_gained DESC, season DESC',
        'quali'      => 'q2_pos IS NULL, q2_pos ASC, season DESC',
    ];

    /**
     * Anonymous rows for the Bird's Eye View's "everyone" mode.
     *
     * These are individual races, but they carry no manager identity — the
     * corpus has none to carry. A reader sees "a Rookie who started 14th and
     * finished 3rd", never who that was.
     *
     * @param array<string, int|string|null> $filters
     * @return list<array<string, mixed>>
     */
    public function browse(array $filters = [], string $sort = 'impressive', int $limit = 100): array
    {
        [$where, $params] = $this->whereFor($filters);
        $order = self::SORTS[$sort] ?? self::SORTS['impressive'];
        $score = self::IMPRESSIVENESS_SQL;

        $sql = "
            SELECT season, race, track_id, track_name, level, group_label,
                   start_pos, final_pos, points, positions_gained, dnf,
                   q1_pos, q2_pos, q1_time_ms, q2_time_ms,
                   pit_stops, laps_completed, race_tyre, tyre_supplier,
                   was_wet, avg_temp, avg_humidity, avg_pit_time,
                   overtake_risk, defend_risk, clear_dry_risk, clear_wet_risk,
                   overtakes, ot_attempts, driver_oa, avg_part_level,
                   ({$score}) AS score
            FROM race_telemetry
            {$where}
            ORDER BY {$order}
            LIMIT :limit
        ";

        $params['limit'] = $limit;

        return $this->rows($sql, $params);
    }

    /**
     * Headline aggregate over the same filtered slice browse() lists.
     *
     * @param array<string, int|string|null> $filters
     * @return array<string, mixed>
     */
    public function browseSummary(array $filters = []): array
    {
        [$where, $params] = $this->whereFor($filters);

        $sql = "
            SELECT COUNT(*)                 AS races,
                   COUNT(DISTINCT driver_id) AS drivers,
                   COUNT(DISTINCT track_id)  AS tracks,
                   COUNT(DISTINCT season)    AS seasons,
                   ROUND(AVG(final_pos), 2)  AS avg_pos,
                   ROUND(AVG(start_pos), 2)  AS avg_grid,
                   ROUND(AVG(points), 2)     AS avg_points,
                   ROUND(AVG(pit_stops), 2)  AS avg_stops,
                   SUM(dnf)                  AS dnfs,
                   SUM(was_wet)              AS wet_races
            FROM race_telemetry
            {$where}
        ";

        $rows = $this->rows($sql, $params);

        return $rows[0] ?? [];
    }

    /**
     * Distinct values actually present in the corpus, for the filter controls.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function filterOptions(): array
    {
        return [
            'tracks' => $this->rows(
                'SELECT DISTINCT track_id AS value, track_name AS label FROM race_telemetry
                  WHERE track_id IS NOT NULL ORDER BY track_name'
            ),
            'seasons' => $this->rows(
                'SELECT DISTINCT season AS value, season AS label FROM race_telemetry ORDER BY season DESC'
            ),
            'races' => $this->rows(
                'SELECT DISTINCT race AS value, race AS label FROM race_telemetry ORDER BY race'
            ),
            'suppliers' => $this->rows(
                'SELECT DISTINCT tyre_supplier AS value, tyre_supplier AS label FROM race_telemetry
                  WHERE tyre_supplier IS NOT NULL ORDER BY tyre_supplier'
            ),
            'compounds' => $this->rows(
                'SELECT DISTINCT race_tyre AS value, race_tyre AS label FROM race_telemetry
                  WHERE race_tyre IS NOT NULL ORDER BY race_tyre'
            ),
            'levels' => $this->rows(
                'SELECT DISTINCT level AS value, level AS label FROM race_telemetry ORDER BY level'
            ),
        ];
    }

    /**
     * Every track the corpus knows, with how much it knows about each — the
     * Track History picker, which must never offer a track with no data.
     *
     * @return list<array<string, mixed>>
     */
    public function trackCoverage(): array
    {
        return $this->rows("
            SELECT track_id, track_name,
                   COUNT(*)                AS races,
                   COUNT(DISTINCT season)  AS seasons,
                   COUNT(DISTINCT level)   AS levels,
                   SUM(was_wet)            AS wet_races
            FROM race_telemetry
            WHERE track_id IS NOT NULL
            GROUP BY track_id, track_name
            ORDER BY races DESC
        ");
    }

    /**
     * Race count per level at one track — the "how much do we know" header of
     * the Track History screen, and the denominator of every percentage on it.
     *
     * @return list<array<string, mixed>>
     */
    public function trackLevelTotals(int $trackId): array
    {
        return $this->rows("
            SELECT level,
                   COUNT(*)                       AS races,
                   COUNT(DISTINCT season)         AS seasons,
                   COUNT(DISTINCT driver_id)      AS drivers,
                   ROUND(AVG(final_pos), 2)       AS avg_pos,
                   ROUND(AVG(mistake_seconds), 3) AS avg_mistake,
                   SUM(dnf)                       AS dnfs
            FROM race_telemetry
            WHERE track_id = :track
            GROUP BY level
        ", ['track' => $trackId]);
    }

    /**
     * Distribution of a categorical risk choice per level at one track, as
     * counts — the caller turns them into the percentages GPRO's own track
     * analysis prints. `$column` is whitelisted, never interpolated raw.
     *
     * @return list<array<string, mixed>>
     */
    public function trackRiskDistribution(int $trackId, string $column): array
    {
        $allowed = ['q1_risk', 'q2_risk', 'start_risk'];
        if (!in_array($column, $allowed, true)) {
            return [];
        }

        return $this->rows("
            SELECT level, {$column} AS choice, COUNT(*) AS n
            FROM race_telemetry
            WHERE track_id = :track AND {$column} IS NOT NULL
            GROUP BY level, {$column}
        ", ['track' => $trackId]);
    }

    /**
     * Mean numeric race risks per level at one track, plus the share of races
     * that ran a clear-track-dry setting above 50 — the aggressive-setup
     * tell GPRO's own page highlights.
     *
     * @return list<array<string, mixed>>
     */
    public function trackRiskAverages(int $trackId): array
    {
        return $this->rows("
            SELECT level,
                   COUNT(*)                        AS n,
                   ROUND(AVG(overtake_risk), 2)    AS overtaking,
                   ROUND(AVG(defend_risk), 2)      AS defensive,
                   ROUND(AVG(clear_dry_risk), 2)   AS clear_dry,
                   ROUND(AVG(clear_wet_risk), 2)   AS clear_wet,
                   ROUND(AVG(problem_risk), 2)     AS malfunctioning,
                   ROUND(
                     100.0 * SUM(CASE WHEN clear_dry_risk > 50 THEN 1 ELSE 0 END) / COUNT(*),
                     2
                   ) AS clear_dry_over_50
            FROM race_telemetry
            WHERE track_id = :track
            GROUP BY level
        ", ['track' => $trackId]);
    }

    /**
     * Pit-stop count distribution per level at one track — the strategy half of
     * the Track History screen.
     *
     * @return list<array<string, mixed>>
     */
    public function trackStopDistribution(int $trackId): array
    {
        return $this->rows("
            SELECT level, pit_stops, COUNT(*) AS n,
                   ROUND(AVG(final_pos), 2) AS avg_pos
            FROM race_telemetry
            WHERE track_id = :track AND pit_stops IS NOT NULL
            GROUP BY level, pit_stops
            ORDER BY level, pit_stops
        ", ['track' => $trackId]);
    }

    /**
     * Weather actually observed at one track, per season — the bottom table of
     * GPRO's track page. Built from laps run, not from a forecast.
     *
     * @return list<array<string, mixed>>
     */
    public function trackWeatherBySeason(int $trackId): array
    {
        return $this->rows("
            SELECT season,
                   COUNT(*)                      AS races,
                   ROUND(AVG(avg_temp), 2)       AS temperature,
                   ROUND(AVG(avg_humidity), 2)   AS humidity,
                   SUM(was_wet)                  AS wet_races,
                   ROUND(AVG(wet_lap_share), 3)  AS wet_lap_share
            FROM race_telemetry
            WHERE track_id = :track
            GROUP BY season
            ORDER BY season DESC
        ", ['track' => $trackId]);
    }

    /**
     * Tyre compound usage and result per level at one track.
     *
     * @return list<array<string, mixed>>
     */
    public function trackTyreUsage(int $trackId, int $minSample = 3): array
    {
        return $this->rows("
            SELECT level, race_tyre, was_wet,
                   COUNT(*)                 AS n,
                   ROUND(AVG(final_pos), 2) AS avg_pos,
                   ROUND(AVG(points), 2)    AS avg_points
            FROM race_telemetry
            WHERE track_id = :track AND race_tyre IS NOT NULL
            GROUP BY level, race_tyre, was_wet
            HAVING COUNT(*) >= :min
            ORDER BY level, avg_pos
        ", ['track' => $trackId, 'min' => $minSample]);
    }

    /**
     * What a winning race looked like at one track, per level: the mean choices
     * of races that finished in the top three, beside the mean of the rest.
     *
     * This is the Insights answer to "what does the next track reward" — every
     * column is a decision a manager makes before the lights go out.
     *
     * @return list<array<string, mixed>>
     */
    public function trackWinnerProfile(int $trackId, int $minSample = 3): array
    {
        return $this->rows("
            SELECT level,
                   CASE WHEN final_pos <= 3 THEN 'podium' ELSE 'rest' END AS band,
                   COUNT(*)                        AS n,
                   ROUND(AVG(start_pos), 2)        AS avg_grid,
                   ROUND(AVG(pit_stops), 2)        AS avg_stops,
                   ROUND(AVG(start_fuel), 1)       AS avg_start_fuel,
                   ROUND(AVG(overtake_risk), 2)    AS overtaking,
                   ROUND(AVG(defend_risk), 2)      AS defensive,
                   ROUND(AVG(clear_dry_risk), 2)   AS clear_dry,
                   ROUND(AVG(problem_risk), 2)     AS malfunctioning,
                   ROUND(AVG(driver_oa), 1)        AS driver_oa,
                   ROUND(AVG(avg_part_level), 2)   AS part_level,
                   ROUND(AVG(boost_laps), 2)       AS boost_laps,
                   ROUND(AVG(setup_fwing), 0)      AS fwing,
                   ROUND(AVG(setup_rwing), 0)      AS rwing,
                   ROUND(AVG(setup_engine), 0)     AS engine,
                   ROUND(AVG(setup_brakes), 0)     AS brakes,
                   ROUND(AVG(setup_gear), 0)       AS gear,
                   ROUND(AVG(setup_susp), 0)       AS susp
            FROM race_telemetry
            WHERE track_id = :track AND final_pos IS NOT NULL AND dnf = 0
            GROUP BY level, band
            HAVING COUNT(*) >= :min
            ORDER BY level, band
        ", ['track' => $trackId, 'min' => $minSample]);
    }

    /**
     * The most-used tyre compound among podium finishers at one track, per
     * level — a mode, which an average over compound names cannot express.
     *
     * @return list<array<string, mixed>>
     */
    public function trackPodiumTyres(int $trackId): array
    {
        return $this->rows("
            SELECT level, race_tyre, was_wet, COUNT(*) AS n
            FROM race_telemetry
            WHERE track_id = :track AND final_pos <= 3 AND race_tyre IS NOT NULL
            GROUP BY level, race_tyre, was_wet
            ORDER BY level, n DESC
        ", ['track' => $trackId]);
    }

    /**
     * Races that took a top-three grid slot AND a top-three finish, and have
     * not yet been promoted into the Division Baseline.
     *
     * Doing both is the filter that makes a driver representative of its
     * division: a good qualifier with a bad race had the car, a good race from
     * the back had the luck, but doing both is pace.
     *
     * This is the one query here that reads outside race_telemetry. The
     * anti-join against pilots is what makes the auto-fill idempotent — it runs
     * on every sync and must never promote the same race twice. It joins on the
     * provenance column only, never on anything user-identifying.
     *
     * Rows collected before driver_age existed are skipped: pilots.age is NOT
     * NULL, and inventing an age would corrupt every recruitment calculation
     * that reads the baseline.
     *
     * @return list<array<string, mixed>>
     */
    public function podiumDoublesAwaitingBaseline(int $limit = 200): array
    {
        return $this->rows("
            SELECT t.id, t.level,
                   t.driver_con, t.driver_tal, t.driver_agg, t.driver_exp,
                   t.driver_tei, t.driver_sta, t.driver_cha, t.driver_mot,
                   t.driver_wei, t.driver_age
            FROM race_telemetry t
            LEFT JOIN pilots p ON p.source_telemetry_id = t.id
            WHERE p.id IS NULL
              AND t.dnf = 0
              AND t.q2_pos IS NOT NULL AND t.q2_pos <= 3
              AND t.final_pos IS NOT NULL AND t.final_pos <= 3
              AND t.driver_age IS NOT NULL
              AND t.driver_con IS NOT NULL
              AND t.driver_tal IS NOT NULL
            ORDER BY t.id
            LIMIT :limit
        ", ['limit' => $limit]);
    }

    /**
     * Shared WHERE builder for browse()/browseSummary(). Filter keys are
     * whitelisted against EQUALITY_FILTERS, so no caller input reaches the SQL.
     *
     * @param array<string, int|string|null> $filters
     * @return array{0: string, 1: array<string, int|string>}
     */
    private function whereFor(array $filters): array
    {
        $clauses = [];
        $params = [];

        foreach (self::EQUALITY_FILTERS as $key => $column) {
            $value = $filters[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $clauses[] = "{$column} = :{$key}";
            $params[$key] = $value;
        }

        $quali = $filters['quali_max'] ?? null;
        if ($quali !== null && $quali !== '') {
            $clauses[] = 'q2_pos IS NOT NULL AND q2_pos <= :quali_max';
            $params['quali_max'] = (int) $quali;
        }

        $finish = $filters['finish_max'] ?? null;
        if ($finish !== null && $finish !== '') {
            $clauses[] = 'final_pos IS NOT NULL AND final_pos <= :finish_max';
            $params['finish_max'] = (int) $finish;
        }

        $wet = $filters['wet'] ?? null;
        if ($wet === '1' || $wet === 1) {
            $clauses[] = 'was_wet = 1';
        } elseif ($wet === '0' || $wet === 0) {
            $clauses[] = 'was_wet = 0';
        }

        return [
            $clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses),
            $params,
        ];
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
