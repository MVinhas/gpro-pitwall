<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * One row per user and race: the manager's group standings as they stood on
 * entering that race. Derived from the API archive.
 */
class StandingsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{
     *     user_id: int, season: int, race: int,
     *     own_pos: ?int, own_pts: ?int, group_label: ?string, managers_json: ?string
     * } $row
     */
    public function save(array $row): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_standings
                (user_id, season, race, own_pos, own_pts, group_label, managers_json, projected_at)
             VALUES
                (:user_id, :season, :race, :own_pos, :own_pts, :group_label, :managers_json, :projected_at)
             ON CONFLICT(user_id, season, race) DO UPDATE SET
                own_pos = excluded.own_pos,
                own_pts = excluded.own_pts,
                group_label = excluded.group_label,
                managers_json = excluded.managers_json,
                projected_at = excluded.projected_at'
        );
        $stmt->execute([
            ':user_id' => $row['user_id'],
            ':season' => $row['season'],
            ':race' => $row['race'],
            ':own_pos' => $row['own_pos'],
            ':own_pts' => $row['own_pts'],
            ':group_label' => $row['group_label'],
            ':managers_json' => $row['managers_json'],
            ':projected_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function delete(int $userId, int $season, int $race): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM user_standings WHERE user_id = :u AND season = :s AND race = :r'
        );
        $stmt->execute([':u' => $userId, ':s' => $season, ':r' => $race]);
    }
}
