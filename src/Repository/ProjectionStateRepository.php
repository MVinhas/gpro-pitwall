<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Which races have been projected out of the archive, by which projector
 * version and when, and which races still need it.
 */
class ProjectionStateRepository
{
    /**
     * Candidate races are every race some archive row is stamped with, plus the
     * ones a backfilled analysis names in its `SR=S,R` params. A race is pending
     * when it was never projected, was projected by an older version, or any row
     * that feeds it (its own stamps, its analysis, the season's SF/CCP history)
     * was fetched after the projection. Only indexed columns are compared: no
     * payload is decoded.
     */
    private const string PENDING_SQL = "
        WITH candidates(season, race) AS (
            SELECT DISTINCT season, race FROM user_api_archive
             WHERE user_id = :user_id AND season IS NOT NULL AND race IS NOT NULL
            UNION
            SELECT CAST(substr(params, 4, instr(params, ',') - 4) AS INTEGER),
                   CAST(substr(params, instr(params, ',') + 1) AS INTEGER)
              FROM user_api_archive
             WHERE user_id = :user_id AND endpoint = 'RaceAnalysis'
               AND params >= 'SR=' AND params < 'SR>' AND instr(params, ',') > 4
        )
        SELECT c.season AS season, c.race AS race
          FROM candidates c
          LEFT JOIN user_projection_state ps
            ON ps.user_id = :user_id AND ps.season = c.season AND ps.race = c.race
         WHERE ps.user_id IS NULL
            OR ps.version < :version
            OR EXISTS (
                SELECT 1 FROM user_api_archive a
                 WHERE a.user_id = :user_id AND a.season = c.season AND a.race = c.race
                   AND a.fetched_at > ps.projected_at)
            OR EXISTS (
                SELECT 1 FROM user_api_archive a
                 WHERE a.user_id = :user_id AND a.endpoint = 'RaceAnalysis'
                   AND a.params = 'SR=' || c.season || ',' || c.race
                   AND a.fetched_at > ps.projected_at)
            OR EXISTS (
                SELECT 1 FROM user_api_archive a
                 WHERE a.user_id = :user_id AND a.endpoint IN ('SFHistory', 'CCPHistory')
                   AND a.params = 'season=' || c.season
                   AND a.fetched_at > ps.projected_at)
         ORDER BY c.season ASC, c.race ASC
         LIMIT :limit";

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function mark(int $userId, int $season, int $race, int $version): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_projection_state (user_id, season, race, version, projected_at)
             VALUES (:u, :s, :r, :v, :at)
             ON CONFLICT(user_id, season, race) DO UPDATE SET
                version = excluded.version, projected_at = excluded.projected_at'
        );
        $stmt->execute([
            ':u' => $userId, ':s' => $season, ':r' => $race, ':v' => $version,
            ':at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return list<array{season: int, race: int}> oldest first
     */
    public function pendingRaces(int $userId, int $version, int $limit): array
    {
        $stmt = $this->pdo->prepare(self::PENDING_SQL);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':version', $version, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(0, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = ['season' => (int) $row['season'], 'race' => (int) $row['race']];
        }

        return $out;
    }

    /**
     * SQLite's plan for the pending query, one line per step.
     *
     * @return list<string>
     */
    public function explainPending(int $userId, int $version, int $limit): array
    {
        $stmt = $this->pdo->prepare('EXPLAIN QUERY PLAN ' . self::PENDING_SQL);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':version', $version, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_values(array_map(
            static fn (array $row): string => (string) $row['detail'],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        ));
    }
}
