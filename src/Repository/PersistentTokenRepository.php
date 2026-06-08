<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Persistent "remember me" tokens (selector + validator scheme).
 *
 * The selector is the plaintext lookup key; validator_hash is the SHA-256 of
 * the validator — the raw validator never touches the DB. See
 * PersistentLoginService for the security model.
 */
class PersistentTokenRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(int $userId, string $selector, string $validatorHash, string $expiresAt): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO persistent_tokens (user_id, selector, validator_hash, expires_at)
             VALUES (:uid, :sel, :hash, :exp)'
        );
        $stmt->execute([
            'uid'  => $userId,
            'sel'  => $selector,
            'hash' => $validatorHash,
            'exp'  => $expiresAt,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findBySelector(string $selector): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM persistent_tokens WHERE selector = :sel LIMIT 1'
        );
        $stmt->execute(['sel' => $selector]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function rotate(int $id, string $validatorHash, string $expiresAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE persistent_tokens SET validator_hash = :hash, expires_at = :exp WHERE id = :id'
        );
        $stmt->execute(['hash' => $validatorHash, 'exp' => $expiresAt, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM persistent_tokens WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function deleteAllForUser(int $userId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM persistent_tokens WHERE user_id = :uid');
        $stmt->execute(['uid' => $userId]);
    }

    public function deleteExpired(string $now): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM persistent_tokens WHERE expires_at < :now');
        $stmt->execute(['now' => $now]);
    }
}
