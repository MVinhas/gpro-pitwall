<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * What the manager decided about a weekend's optional steps (testing, training): do it or skip it. One row per
 * user, season, race and item; no row means undecided.
 */
class WeekendChoiceRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{testing?: 'do'|'skip', training?: 'do'|'skip'}
     */
    public function choices(int $userId, int $season, int $race): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT item, choice FROM user_weekend_choice WHERE user_id = :u AND season = :s AND race = :r'
        );
        $stmt->execute([':u' => $userId, ':s' => $season, ':r' => $race]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['item'] === 'testing' && ($row['choice'] === 'do' || $row['choice'] === 'skip')) {
                $out['testing'] = $row['choice'];
            } elseif ($row['item'] === 'training' && ($row['choice'] === 'do' || $row['choice'] === 'skip')) {
                $out['training'] = $row['choice'];
            }
        }

        return $out;
    }

    public function save(int $userId, int $season, int $race, string $item, string $choice): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO user_weekend_choice (user_id, season, race, item, choice)
             VALUES (:u, :s, :r, :item, :choice)
             ON CONFLICT(user_id, season, race, item) DO UPDATE SET
                choice = excluded.choice,
                updated_at = datetime('now')"
        );
        $stmt->execute([':u' => $userId, ':s' => $season, ':r' => $race, ':item' => $item, ':choice' => $choice]);
    }

    public function clear(int $userId, int $season, int $race, string $item): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM user_weekend_choice WHERE user_id = :u AND season = :s AND race = :r AND item = :item'
        );
        $stmt->execute([':u' => $userId, ':s' => $season, ':r' => $race, ':item' => $item]);
    }
}
