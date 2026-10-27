<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * The race engineer's latest season plan, one per user and season, so the
 * Season screen can show it without recomputing it. The plan carries a version
 * in `v`; a reader ignores a plan saved under any other shape.
 */
class SeasonBudgetRepository
{
    private const int PLAN_VERSION = 1;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{race: int, computed_at: string, plan: array<string, mixed>}|null
     */
    public function find(int $userId, int $season): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT race, plan_json, computed_at FROM user_season_budget WHERE user_id = :u AND season = :s'
        );
        $stmt->execute([':u' => $userId, ':s' => $season]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !is_string($row['plan_json'])) {
            return null;
        }

        $plan = json_decode($row['plan_json'], true);
        if (!is_array($plan) || ($plan['v'] ?? null) !== self::PLAN_VERSION) {
            return null;
        }

        return [
            'race' => (int) $row['race'],
            'computed_at' => (string) $row['computed_at'],
            'plan' => $plan,
        ];
    }

    /**
     * @param array<string, mixed> $plan
     */
    public function save(int $userId, int $season, int $race, array $plan): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO user_season_budget (user_id, season, race, plan_json)
             VALUES (:u, :s, :race, :plan)
             ON CONFLICT(user_id, season) DO UPDATE SET
                race = excluded.race,
                plan_json = excluded.plan_json,
                computed_at = datetime('now')"
        );
        $stmt->execute([
            ':u' => $userId,
            ':s' => $season,
            ':race' => $race,
            ':plan' => json_encode($plan, JSON_THROW_ON_ERROR),
        ]);
    }
}
