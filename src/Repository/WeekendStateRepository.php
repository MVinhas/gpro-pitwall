<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * One row per user and race: the car, staff, driver and TD as they stood when
 * the manager entered that race weekend. Derived from the API archive.
 */
class WeekendStateRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{
     *     user_id: int, season: int, race: int, source: string,
     *     driver_json: ?string, td_json: ?string, staff_json: ?string,
     *     facilities_json: ?string, car_json: ?string,
     *     cash: ?int, pts: ?int, pos: ?int
     * } $row
     */
    public function save(array $row): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_weekend_state
                (user_id, season, race, driver_json, td_json, staff_json, facilities_json, car_json,
                 cash, pts, pos, source, projected_at)
             VALUES
                (:user_id, :season, :race, :driver_json, :td_json, :staff_json, :facilities_json, :car_json,
                 :cash, :pts, :pos, :source, :projected_at)
             ON CONFLICT(user_id, season, race) DO UPDATE SET
                driver_json = excluded.driver_json,
                td_json = excluded.td_json,
                staff_json = excluded.staff_json,
                facilities_json = excluded.facilities_json,
                car_json = excluded.car_json,
                cash = excluded.cash,
                pts = excluded.pts,
                pos = excluded.pos,
                source = excluded.source,
                projected_at = excluded.projected_at'
        );
        $stmt->execute([
            ':user_id' => $row['user_id'],
            ':season' => $row['season'],
            ':race' => $row['race'],
            ':driver_json' => $row['driver_json'],
            ':td_json' => $row['td_json'],
            ':staff_json' => $row['staff_json'],
            ':facilities_json' => $row['facilities_json'],
            ':car_json' => $row['car_json'],
            ':cash' => $row['cash'],
            ':pts' => $row['pts'],
            ':pos' => $row['pos'],
            ':source' => $row['source'],
            ':projected_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function delete(int $userId, int $season, int $race): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM user_weekend_state WHERE user_id = :u AND season = :s AND race = :r'
        );
        $stmt->execute([':u' => $userId, ':s' => $season, ':r' => $race]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $userId, int $season, int $race): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM user_weekend_state WHERE user_id = :u AND season = :s AND race = :r'
        );
        $stmt->execute([':u' => $userId, ':s' => $season, ':r' => $race]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        /** @var array<string, mixed>|false $row */
        return $row === false ? null : $row;
    }

    /**
     * The latest stored weekend strictly before (season, race), across seasons.
     *
     * @return array<string, mixed>|null
     */
    public function previous(int $userId, int $season, int $race): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM user_weekend_state
             WHERE user_id = :u AND (season < :s OR (season = :s AND race < :r))
             ORDER BY season DESC, race DESC
             LIMIT 1'
        );
        $stmt->execute([':u' => $userId, ':s' => $season, ':r' => $race]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        /** @var array<string, mixed>|false $row */
        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forSeason(int $userId, int $season): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM user_weekend_state WHERE user_id = :u AND season = :s ORDER BY race'
        );
        $stmt->execute([':u' => $userId, ':s' => $season]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }
}
