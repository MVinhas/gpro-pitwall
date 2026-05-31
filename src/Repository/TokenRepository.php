<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

class TokenRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function store(int $userId, string $codeHmac, string $expiresAt): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO verification_tokens (user_id, code_hmac, expires_at) VALUES (:uid, :hmac, :exp)'
        );
        $stmt->execute([
            'uid' => $userId,
            'hmac' => $codeHmac,
            'exp' => $expiresAt
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findLatestByUserId(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM verification_tokens WHERE user_id = :uid ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function incrementAttempts(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE verification_tokens SET attempts = attempts + 1 WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM verification_tokens WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function deleteExpired(string $now): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM verification_tokens WHERE expires_at < :now');
        $stmt->execute(['now' => $now]);
    }
}
