<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * De-duplicated money movements per user. A fingerprint identifies one
 * movement, so projecting the same archive twice never doubles a row.
 */
class EconomyLedgerRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Insert the movement, or — for a race-sourced one, which only ever comes
     * from its own race's analysis — refresh it. An economy-sourced movement
     * can reappear in a later window's page, so the first sighting is kept.
     *
     * @param array{
     *     user_id: int, season: int, race: int, source: string, occurred_at: ?string,
     *     description: string, amount: int, balance_after: ?int, fingerprint: string
     * } $row
     */
    public function save(array $row): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_economy_ledger
                (user_id, season, race, source, occurred_at, description, amount, balance_after, fingerprint)
             VALUES
                (:user_id, :season, :race, :source, :occurred_at, :description, :amount, :balance_after, :fingerprint)
             ON CONFLICT(user_id, fingerprint) DO UPDATE SET
                season = excluded.season,
                race = excluded.race,
                occurred_at = excluded.occurred_at,
                description = excluded.description,
                amount = excluded.amount,
                balance_after = excluded.balance_after
             WHERE excluded.source = \'race\''
        );
        $stmt->execute([
            ':user_id' => $row['user_id'],
            ':season' => $row['season'],
            ':race' => $row['race'],
            ':source' => $row['source'],
            ':occurred_at' => $row['occurred_at'],
            ':description' => $row['description'],
            ':amount' => $row['amount'],
            ':balance_after' => $row['balance_after'],
            ':fingerprint' => $row['fingerprint'],
        ]);
    }
}
