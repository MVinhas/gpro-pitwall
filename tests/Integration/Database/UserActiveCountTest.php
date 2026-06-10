<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Repository\UserRepository;
use App\Security\ApiTokenCrypto;
use App\Security\EmailCrypto;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the "active user" telemetry definition against a real SQLite
 * schema: counted iff last_synced_at falls within the window, never deleted,
 * never null.
 */
#[CoversClass(UserRepository::class)]
final class UserActiveCountTest extends TestCase
{
    private PDO $db;
    private UserRepository $repo;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            "CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT,
                email_encrypted TEXT,
                email_hash TEXT,
                api_token TEXT DEFAULT NULL,
                is_admin INTEGER NOT NULL DEFAULT 0,
                verified_at TEXT DEFAULT NULL,
                last_synced_at TEXT DEFAULT NULL,
                sync_status TEXT NOT NULL DEFAULT 'idle',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                deleted_at TEXT DEFAULT NULL
            )"
        );
        $secret = 'active-count-test-secret-not-prod';
        $this->repo = new UserRepository($this->db, new EmailCrypto($secret), new ApiTokenCrypto($secret));
    }

    private function makeUser(string $username, ?string $syncedAgo, bool $deleted = false): void
    {
        $user = $this->repo->create($username, $username . '@example.com');
        self::assertNotNull($user);
        $id = (int) $user['id'];

        if ($syncedAgo !== null) {
            $stmt = $this->db->prepare(
                "UPDATE users SET last_synced_at = datetime('now', :ago) WHERE id = :id"
            );
            $stmt->execute(['ago' => $syncedAgo, 'id' => $id]);
        }
        if ($deleted) {
            $this->repo->softDelete($id);
        }
    }

    public function testCountsOnlyUsersSyncedWithinTheWindow(): void
    {
        $this->makeUser('fresh', '-1 day');
        $this->makeUser('edge', '-29 days');
        $this->makeUser('stale', '-45 days');
        $this->makeUser('never', null);

        $this->assertSame(2, $this->repo->countActiveSince(30));
    }

    public function testDeletedUsersAreNeverActive(): void
    {
        $this->makeUser('fresh', '-1 day');
        $this->makeUser('deleted-but-fresh', '-1 day', deleted: true);

        $this->assertSame(1, $this->repo->countActiveSince(30));
    }

    public function testMarkSyncedMakesAUserActive(): void
    {
        $this->makeUser('never', null);
        $this->assertSame(0, $this->repo->countActiveSince(30));

        $user = $this->repo->findByUsername('never');
        self::assertNotNull($user);
        $this->repo->markSynced((int) $user['id']);

        $this->assertSame(1, $this->repo->countActiveSince(30));
    }
}
