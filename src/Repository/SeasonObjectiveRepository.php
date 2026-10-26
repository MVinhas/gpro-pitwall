<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * The season objective a manager pinned (promote, hold or survive), one per
 * user and season. No row means the engineer proposes one on its own.
 */
class SeasonObjectiveRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function find(int $userId, int $season): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT objective FROM user_season_objective WHERE user_id = :u AND season = :s'
        );
        $stmt->execute([':u' => $userId, ':s' => $season]);
        $value = $stmt->fetchColumn();

        return is_string($value) ? $value : null;
    }

    public function save(int $userId, int $season, string $objective): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO user_season_objective (user_id, season, objective)
             VALUES (:u, :s, :objective)
             ON CONFLICT(user_id, season) DO UPDATE SET
                objective = excluded.objective,
                set_at = datetime('now')"
        );
        $stmt->execute([':u' => $userId, ':s' => $season, ':objective' => $objective]);
    }

    public function clear(int $userId, int $season): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM user_season_objective WHERE user_id = :u AND season = :s'
        );
        $stmt->execute([':u' => $userId, ':s' => $season]);
    }
}
