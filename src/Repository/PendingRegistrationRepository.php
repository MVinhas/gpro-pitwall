<?php

declare(strict_types=1);

namespace App\Repository;

use App\Security\EmailCrypto;
use PDO;

/**
 * Holds in-flight registrations that have not yet proven email control.
 *
 * Deliberately separate from `users`: a row here carries no UNIQUE constraint
 * on username/email, so an unverified attempt can never squat the live account
 * namespace or block a real person. Identity is awarded only when a pending row
 * is promoted into `users` at verification time, where the UNIQUE constraints
 * are the authority. Rows are short-lived and disposable — purged on TTL.
 */
class PendingRegistrationRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly EmailCrypto $crypto,
    ) {
    }

    public function create(
        string $username,
        string $email,
        string $codeHmac,
        string $expiresAt
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO pending_registrations
                (username, email_encrypted, email_hash, code_hmac, expires_at)
             VALUES (:u, :e, :h, :hmac, :exp)'
        );
        $stmt->execute([
            'u' => $username,
            'e' => $this->crypto->encrypt($email),
            'h' => $this->crypto->hash($email),
            'hmac' => $codeHmac,
            'exp' => $expiresAt,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pending_registrations WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function incrementAttempts(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE pending_registrations SET attempts = attempts + 1 WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * Replace the code on an existing pending row (resend). Resets the attempt
     * counter so a fresh code gets its own full attempt budget.
     */
    public function updateCode(int $id, string $codeHmac, string $expiresAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE pending_registrations
             SET code_hmac = :hmac, expires_at = :exp, attempts = 0
             WHERE id = :id'
        );
        $stmt->execute(['hmac' => $codeHmac, 'exp' => $expiresAt, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM pending_registrations WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Remove every pending row for an email once it has a real account, so a
     * promoted registration can't leave stale siblings behind that would only
     * fail at their own promotion.
     */
    public function deleteByEmailHash(string $emailHash): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM pending_registrations WHERE email_hash = :h'
        );
        $stmt->execute(['h' => $emailHash]);
    }

    /**
     * Count registrations still in flight (code not yet expired) — a leading
     * indicator of incoming signups for the admin dashboard.
     */
    public function countActive(string $now): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM pending_registrations WHERE expires_at >= :now'
        );
        $stmt->execute(['now' => $now]);
        return (int) $stmt->fetchColumn();
    }

    public function deleteExpired(string $now): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM pending_registrations WHERE expires_at < :now'
        );
        $stmt->execute(['now' => $now]);
    }
}
