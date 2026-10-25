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
     * Insert the movement or, when its fingerprint is known, refresh it: a
     * movement attributed by a race header (or by its own race's analysis) is
     * authoritative, so a re-projection moves a row an earlier pass mis-filed.
     *
     * @param array{
     *     user_id: int, season: int, race: int, source: string, occurred_at: ?string,
     *     description: string, amount: int, balance_after: ?int, fingerprint: string
     * } $row
     */
    public function save(array $row): void
    {
        $this->write($row, true);
    }

    /**
     * Insert the movement unless its fingerprint is known. For a movement whose
     * race is only a guess (the archive stamp), so it never overrides a row an
     * authoritative pass already filed.
     *
     * @param array{
     *     user_id: int, season: int, race: int, source: string, occurred_at: ?string,
     *     description: string, amount: int, balance_after: ?int, fingerprint: string
     * } $row
     */
    public function saveIfNew(array $row): void
    {
        $this->write($row, false);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function write(array $row, bool $refresh): void
    {
        $conflict = $refresh
            ? 'DO UPDATE SET
                season = excluded.season,
                race = excluded.race,
                source = excluded.source,
                occurred_at = excluded.occurred_at,
                description = excluded.description,
                amount = excluded.amount,
                balance_after = excluded.balance_after'
            : 'DO NOTHING';
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_economy_ledger
                (user_id, season, race, source, occurred_at, description, amount, balance_after, fingerprint)
             VALUES
                (:user_id, :season, :race, :source, :occurred_at, :description, :amount, :balance_after, :fingerprint)
             ON CONFLICT(user_id, fingerprint) ' . $conflict
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

    /**
     * @return list<array<string, mixed>>
     */
    public function forRace(int $userId, int $season, int $race): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM user_economy_ledger
             WHERE user_id = :u AND season = :s AND race = :r
             ORDER BY race, occurred_at, id'
        );
        $stmt->execute([':u' => $userId, ':s' => $season, ':r' => $race]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forSeason(int $userId, int $season): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM user_economy_ledger
             WHERE user_id = :u AND season = :s
             ORDER BY race, occurred_at, id'
        );
        $stmt->execute([':u' => $userId, ':s' => $season]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }
}
