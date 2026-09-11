<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Storage + retrieval for one manager's own detailed race archive.
 *
 * Every read here is scoped to a user_id the caller supplies, and there is no
 * method that returns another manager's rows. Cross-manager questions belong to
 * RaceTelemetryRepository, which answers them from the anonymous corpus.
 */
class RaceHistoryRepository
{
    /** Columns written by insertIfNew(), in a fixed order. */
    private const array COLUMNS = [
        'user_id', 'season', 'race', 'track_id', 'track_name', 'group_label', 'level',
        'laps_total', 'laps_completed', 'grid_pos', 'final_pos', 'points',
        'positions_gained', 'dnf', 'best_lap_ms', 'best_pit_ms',
        'q1_pos', 'q2_pos', 'q1_time_ms', 'q2_time_ms',
        'pit_stops', 'start_fuel', 'finish_fuel', 'finish_tyres', 'problems_count',
        'avg_temp', 'avg_humidity', 'dry_laps', 'rain_laps', 'mist_laps',
        'problem_laps', 'was_wet', 'fuel_per_km', 'tyre_per_km',
        'race_tyre', 'tyre_supplier',
        'setup_fwing', 'setup_rwing', 'setup_engine', 'setup_brakes',
        'setup_gear', 'setup_susp',
        'q1_risk', 'q2_risk', 'start_risk', 'overtake_risk', 'defend_risk',
        'clear_dry_risk', 'clear_wet_risk', 'problem_risk',
        'boost_lap_1', 'boost_lap_2', 'boost_lap_3',
        'ot_attempts', 'overtakes', 'ot_attempts_on_you', 'overtakes_on_you',
        'car_power', 'car_handling', 'car_accel', 'energy_from', 'energy_to',
        'qualifying_json', 'stints_json', 'pits_json', 'parts_json',
        'driver_json', 'td_json', 'staff_json', 'problems_json',
    ];

    /**
     * "How impressive was this race", as SQL.
     *
     * Finishing position alone rewards whoever had the fastest car; positions
     * gained alone rewards whoever qualified badly. The blend is deliberate:
     * points for the result, a heavier weight on grid-to-flag places gained
     * because that is the part the manager actually drove, a bonus for the
     * podium steps, and a flat penalty for a DNF so a retirement can never
     * outrank a finish.
     *
     * Exposed as a constant so the UI can explain the same formula it sorts by.
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

    /** Sort keys the Bird's Eye View accepts, mapped to ORDER BY clauses. */
    private const array SORTS = [
        'impressive' => 'score DESC, season DESC, race DESC',
        'recent'     => 'season DESC, race DESC',
        'position'   => 'final_pos IS NULL, final_pos ASC, season DESC',
        'gained'     => 'positions_gained IS NULL, positions_gained DESC, season DESC',
        'quali'      => 'q2_pos IS NULL, q2_pos ASC, season DESC',
    ];

    /** Filters that map straight onto an equality check. */
    private const array EQUALITY_FILTERS = [
        'track'    => 'track_id',
        'season'   => 'season',
        'race'     => 'race',
        'supplier' => 'tyre_supplier',
        'compound' => 'race_tyre',
        'level'    => 'level',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Insert one race, ignoring a re-sync of a race already archived.
     *
     * @param array<string, mixed> $row
     * @return bool true when a previously unseen race landed
     */
    public function insertIfNew(array $row): bool
    {
        $columns = implode(', ', self::COLUMNS);
        $placeholders = implode(', ', array_map(
            static fn (string $c): string => ':' . $c,
            self::COLUMNS
        ));

        $stmt = $this->pdo->prepare(
            "INSERT OR IGNORE INTO user_race_history ({$columns}) VALUES ({$placeholders})"
        );

        $params = [];
        foreach (self::COLUMNS as $column) {
            $params[$column] = $row[$column] ?? null;
        }

        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /** How many races this manager has archived. */
    public function countFor(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM user_race_history WHERE user_id = :u');
        $stmt->execute(['u' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Summary rows for the Bird's Eye View, filtered and sorted.
     *
     * @param array<string, int|string|null> $filters
     * @return list<array<string, mixed>>
     */
    public function search(int $userId, array $filters = [], string $sort = 'impressive', int $limit = 100): array
    {
        [$where, $params] = $this->whereFor($userId, $filters);
        $order = self::SORTS[$sort] ?? self::SORTS['impressive'];
        $score = self::IMPRESSIVENESS_SQL;

        $sql = "
            SELECT id, season, race, track_id, track_name, level, group_label,
                   grid_pos, final_pos, points, positions_gained, dnf,
                   q1_pos, q2_pos, q1_time_ms, q2_time_ms,
                   best_lap_ms, best_pit_ms, pit_stops, laps_total, laps_completed,
                   race_tyre, tyre_supplier, was_wet, avg_temp, avg_humidity,
                   overtake_risk, defend_risk, clear_dry_risk, clear_wet_risk,
                   overtakes, ot_attempts,
                   ({$score}) AS score
            FROM user_race_history
            {$where}
            ORDER BY {$order}
            LIMIT :limit
        ";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * Aggregate headline over the same filtered slice the list shows, so the
     * numbers above the table always describe the rows under it.
     *
     * @param array<string, int|string|null> $filters
     * @return array<string, mixed>
     */
    public function summary(int $userId, array $filters = []): array
    {
        [$where, $params] = $this->whereFor($userId, $filters);

        $sql = "
            SELECT COUNT(*)                       AS races,
                   ROUND(AVG(final_pos), 2)       AS avg_pos,
                   ROUND(AVG(grid_pos), 2)        AS avg_grid,
                   SUM(points)                    AS points,
                   SUM(CASE WHEN final_pos = 1 THEN 1 ELSE 0 END)  AS wins,
                   SUM(CASE WHEN final_pos <= 3 THEN 1 ELSE 0 END) AS podiums,
                   SUM(CASE WHEN q2_pos = 1 THEN 1 ELSE 0 END)     AS poles,
                   SUM(dnf)                       AS dnfs,
                   SUM(positions_gained)          AS net_gained,
                   ROUND(AVG(pit_stops), 2)       AS avg_stops,
                   MIN(best_lap_ms)               AS best_lap_ms,
                   MIN(best_pit_ms)               AS best_pit_ms,
                   COUNT(DISTINCT track_id)       AS tracks,
                   COUNT(DISTINCT season)         AS seasons
            FROM user_race_history
            {$where}
        ";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [];
        }

        /** @var array<string, mixed> $row */
        return $row;
    }

    /**
     * One archived race in full, with the JSON blocks decoded.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $userId, int $season, int $race): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM user_race_history
            WHERE user_id = :u AND season = :s AND race = :r
        ');
        $stmt->execute(['u' => $userId, 's' => $season, 'r' => $race]);

        $fetched = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($fetched)) {
            return null;
        }

        /** @var array<string, mixed> $row */
        $row = $fetched;

        foreach (['qualifying', 'stints', 'pits', 'parts', 'driver', 'td', 'staff', 'problems'] as $block) {
            $raw = $row[$block . '_json'] ?? null;
            $row[$block] = is_string($raw) && $raw !== ''
                ? json_decode($raw, true, 512, JSON_INVALID_UTF8_SUBSTITUTE)
                : null;
            unset($row[$block . '_json']);
        }

        return $row;
    }

    /**
     * The most recently archived race, for the default selection.
     *
     * @return array{season: int, race: int}|null
     */
    public function latest(int $userId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT season, race FROM user_race_history
            WHERE user_id = :u
            ORDER BY season DESC, race DESC
            LIMIT 1
        ');
        $stmt->execute(['u' => $userId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return ['season' => (int) $row['season'], 'race' => (int) $row['race']];
    }

    /**
     * Every archived race as a selector option, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function raceOptions(int $userId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT season, race, track_name, final_pos, dnf
            FROM user_race_history
            WHERE user_id = :u
            ORDER BY season DESC, race DESC
        ');
        $stmt->execute(['u' => $userId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * Distinct values present in this manager's archive, for the filter
     * dropdowns — built from real rows so no filter can return nothing.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function filterOptions(int $userId): array
    {
        $queries = [
            'tracks' => 'SELECT DISTINCT track_id AS value, track_name AS label
                         FROM user_race_history WHERE user_id = :u AND track_id IS NOT NULL
                         ORDER BY track_name',
            'seasons' => 'SELECT DISTINCT season AS value, season AS label
                          FROM user_race_history WHERE user_id = :u
                          ORDER BY season DESC',
            'races' => 'SELECT DISTINCT race AS value, race AS label
                        FROM user_race_history WHERE user_id = :u
                        ORDER BY race',
            'suppliers' => 'SELECT DISTINCT tyre_supplier AS value, tyre_supplier AS label
                            FROM user_race_history WHERE user_id = :u AND tyre_supplier IS NOT NULL
                            ORDER BY tyre_supplier',
            'compounds' => 'SELECT DISTINCT race_tyre AS value, race_tyre AS label
                            FROM user_race_history WHERE user_id = :u AND race_tyre IS NOT NULL
                            ORDER BY race_tyre',
        ];

        $out = [];
        foreach ($queries as $key => $sql) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['u' => $userId]);
            /** @var list<array<string, mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $out[$key] = $rows;
        }

        return $out;
    }

    /**
     * This manager's own races at one track, oldest first — the personal half
     * of the Track History screen.
     *
     * @return list<array<string, mixed>>
     */
    public function atTrack(int $userId, int $trackId): array
    {
        $score = self::IMPRESSIVENESS_SQL;

        $stmt = $this->pdo->prepare("
            SELECT season, race, grid_pos, final_pos, points, positions_gained, dnf,
                   q1_pos, q2_pos, q1_time_ms, q2_time_ms, best_lap_ms,
                   pit_stops, race_tyre, tyre_supplier, was_wet, avg_temp,
                   overtake_risk, defend_risk, clear_dry_risk, problem_risk,
                   setup_fwing, setup_rwing, setup_engine, setup_brakes,
                   setup_gear, setup_susp, fuel_per_km, tyre_per_km,
                   ({$score}) AS score
            FROM user_race_history
            WHERE user_id = :u AND track_id = :t
            ORDER BY season DESC, race DESC
        ");
        $stmt->execute(['u' => $userId, 't' => $trackId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * Builds the shared WHERE clause. Filter keys are whitelisted against
     * EQUALITY_FILTERS, so no caller input ever reaches the SQL text.
     *
     * @param array<string, int|string|null> $filters
     * @return array{0: string, 1: array<string, int|string>}
     */
    private function whereFor(int $userId, array $filters): array
    {
        $clauses = ['user_id = :user_id'];
        $params = ['user_id' => $userId];

        foreach (self::EQUALITY_FILTERS as $key => $column) {
            $value = $filters[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $clauses[] = "{$column} = :{$key}";
            $params[$key] = $value;
        }

        // Position filters are ranges ("top 3", "points"), not equality.
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

        return ['WHERE ' . implode(' AND ', $clauses), $params];
    }
}
