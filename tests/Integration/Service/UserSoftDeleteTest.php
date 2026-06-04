<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Repository\UserRepository;
use App\Security\ApiTokenCrypto;
use App\Security\EmailCrypto;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Round-trips soft-delete and restore against a real SQLite schema, and
 * asserts the login-blocking guarantee: a soft-deleted user disappears from
 * every normal lookup (so they can't log in) yet stays restorable by admin.
 */
#[CoversClass(UserRepository::class)]
final class UserSoftDeleteTest extends TestCase
{
    private UserRepository $repo;

    protected function setUp(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec(
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
        $secret = 'soft-delete-test-secret-not-prod';
        $this->repo = new UserRepository($db, new EmailCrypto($secret), new ApiTokenCrypto($secret));
    }

    private function makeUser(string $username = 'racer'): int
    {
        $u = $this->repo->create($username, $username . '@example.com');
        self::assertNotNull($u);
        return (int) $u['id'];
    }

    public function testSoftDeletedUserVanishesFromNormalLookups(): void
    {
        $id = $this->makeUser('racer');

        $this->repo->softDelete($id);

        // The login-block guarantee: deleted users are invisible to the
        // lookups the login + session paths use.
        self::assertNull($this->repo->findById($id), 'findById must hide deleted users');
        self::assertNull($this->repo->findByUsername('racer'), 'findByUsername must hide deleted users');
    }

    public function testAdminCanStillSeeAndRestoreADeletedUser(): void
    {
        $id = $this->makeUser('racer');
        $this->repo->softDelete($id);

        $row = $this->repo->findByIdIncludingDeleted($id);
        self::assertNotNull($row, 'admin lookup must still see the deleted user');
        self::assertNotEmpty($row['deleted_at']);

        $this->repo->restore($id);

        // After restore the user is fully back.
        self::assertNotNull($this->repo->findById($id));
        self::assertNotNull($this->repo->findByUsername('racer'));
    }

    public function testRestoreOnANonDeletedUserIsANoop(): void
    {
        $id = $this->makeUser('racer');
        $this->repo->restore($id); // nothing to restore
        self::assertNotNull($this->repo->findById($id));
    }
}
