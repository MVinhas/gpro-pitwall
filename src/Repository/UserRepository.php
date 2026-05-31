<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;
use App\Security\EmailCrypto;
use App\Security\ApiTokenCrypto;

class UserRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly EmailCrypto $crypto,
        private readonly ApiTokenCrypto $apiTokenCrypto,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM users WHERE email_hash = :hash LIMIT 1
        ");

        $stmt->execute([
            'hash' => $this->crypto->hash($email)
        ]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return null;
        }

        return $this->hydrate($user);
    }


    /** @return array<string, mixed>|null */
    public function findByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
        $stmt->execute(['username' => $username]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $this->hydrate($result) : null;
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $this->hydrate($result) : null;
    }

    public function findEncryptedEmailById(int $id): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT email_encrypted FROM users WHERE id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $id]);

        $email = $stmt->fetchColumn();
        return is_string($email) ? $email : null;
    }

    /** @return array<string, mixed>|null */
    public function create(string $username, string $email): ?array
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO users (username, email_encrypted, email_hash, created_at)
            VALUES (:u, :e, :h, :now)
        ");

        $stmt->execute([
            'u' => $username,
            'e' => $this->crypto->encrypt($email),
            'h' => $this->crypto->hash($email),
            'now' => date('Y-m-d H:i:s')
        ]);

        return $this->findByEmail($email);
    }

    public function markVerified(int $id): void
    {
        $stmt = $this->pdo->prepare("UPDATE users SET verified_at = :now WHERE id = :id");
        $stmt->execute([
            'now' => date('Y-m-d H:i:s'),
            'id' => $id
        ]);
    }

    public function updateApiToken(int $userId, string $token): void
    {
        $stored = $token === '' ? '' : $this->apiTokenCrypto->encrypt($token);
        $stmt = $this->pdo->prepare("UPDATE users SET api_token = :token WHERE id = :id");
        $stmt->execute(['token' => $stored, 'id' => $userId]);
    }

    /**
     * Decrypts api_token in-place on a freshly-fetched user row so callers
     * keep seeing plaintext. Leaves the row untouched if the column is empty
     * or already plaintext (pre-migration data, removed by applyUserMigrations).
     */
    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function hydrate(array $user): array
    {
        if (!array_key_exists('api_token', $user) || $user['api_token'] === null || $user['api_token'] === '') {
            return $user;
        }

        try {
            $user['api_token'] = $this->apiTokenCrypto->decrypt($user['api_token']);
        } catch (\RuntimeException) {
            // Not ciphertext — treat as legacy plaintext and leave as-is.
            // DatabaseSeeder migrates these to ciphertext on next boot.
        }

        return $user;
    }

    public function setPremium(int $userId, bool $status): void
    {
        $stmt = $this->pdo->prepare("UPDATE users SET is_premium = :status WHERE id = :id");
        $stmt->execute(['status' => $status ? 1 : 0, 'id' => $userId]);
    }

    public function countAll(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    }

    public function countPremium(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM users WHERE is_premium = 1")->fetchColumn();
    }

    public function countWithApiToken(): int
    {
        return (int)$this->pdo->query(
            "SELECT COUNT(*) FROM users WHERE api_token IS NOT NULL AND api_token != ''"
        )->fetchColumn();
    }

    public function updateSyncStatus(int $userId, string $status): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE users SET sync_status = :status WHERE id = :id"
        );
        $stmt->execute([
            'status' => $status,
            'id' => $userId,
        ]);
    }

    public function markSynced(int $userId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE users
            SET last_synced_at = datetime('now'),
                sync_status = 'idle'
            WHERE id = :id"
        );
        $stmt->execute(['id' => $userId]);
    }
}
