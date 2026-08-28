<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Repository\UserRepository;
use App\Security\ApiTokenCrypto;
use App\Security\EmailCrypto;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserRepository::class)]
final class UserRepositoryTest extends TestCase
{
    private const string SECRET = 'user-repo-test-secret-not-prod';

    private PDO $db;
    private UserRepository $repo;
    private ApiTokenCrypto $apiTokenCrypto;

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
        $this->db->exec("CREATE UNIQUE INDEX idx_users_username ON users(username)");

        $this->apiTokenCrypto = new ApiTokenCrypto(self::SECRET);
        $this->repo = new UserRepository(
            $this->db,
            new EmailCrypto(self::SECRET),
            $this->apiTokenCrypto
        );
    }

    private function makeUser(string $username = 'racer', string $email = 'racer@example.test'): int
    {
        $user = $this->repo->create($username, $email);
        self::assertIsArray($user);
        return (int) $user['id'];
    }

    public function testCreateReturnsTheStoredUser(): void
    {
        $user = $this->repo->create('racer', 'racer@example.test');

        $this->assertIsArray($user);
        $this->assertSame('racer', $user['username']);
        $this->assertNull($user['verified_at']);
        $this->assertSame(0, (int) $user['is_admin']);
    }

    public function testCreateNeverStoresThePlaintextEmail(): void
    {
        $this->makeUser();

        $stored = $this->db->query("SELECT email_encrypted, email_hash FROM users")->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($stored);
        $this->assertStringNotContainsString('racer@example.test', (string) $stored['email_encrypted']);
        $this->assertStringNotContainsString('racer@example.test', (string) $stored['email_hash']);
    }

    public function testFindByEmailIsCaseInsensitive(): void
    {
        $this->makeUser();

        $this->assertIsArray($this->repo->findByEmail('RACER@EXAMPLE.TEST'));
    }

    public function testFindByEmailReturnsNullForAnUnknownAddress(): void
    {
        $this->makeUser();

        $this->assertNull($this->repo->findByEmail('nobody@example.test'));
    }

    public function testFindByUsernameReturnsTheUser(): void
    {
        $this->makeUser('racer');

        $user = $this->repo->findByUsername('racer');
        $this->assertIsArray($user);
        $this->assertSame('racer', $user['username']);
    }

    public function testFindByUsernameReturnsNullWhenAbsent(): void
    {
        $this->assertNull($this->repo->findByUsername('ghost'));
    }

    public function testFindByIdReturnsNullForAnAbsentUser(): void
    {
        $this->assertNull($this->repo->findById(999));
    }

    public function testUsernamesAreUniqueSoARaceLosesAtTheIndex(): void
    {
        $this->makeUser('racer', 'a@example.test');

        $this->expectException(PDOException::class);
        $this->repo->create('racer', 'b@example.test');
    }

    public function testMarkVerifiedStampsTheUser(): void
    {
        $id = $this->makeUser();

        $this->repo->markVerified($id);

        $user = $this->repo->findById($id);
        $this->assertIsArray($user);
        $this->assertNotNull($user['verified_at']);
    }

    public function testTheApiTokenIsStoredEncryptedAndReadBackAsPlaintext(): void
    {
        $id = $this->makeUser();

        $this->repo->updateApiToken($id, 'secret-api-token');

        $raw = $this->db->query("SELECT api_token FROM users WHERE id = {$id}")->fetchColumn();
        $this->assertIsString($raw);
        $this->assertNotSame('secret-api-token', $raw);

        $user = $this->repo->findById($id);
        $this->assertIsArray($user);
        $this->assertSame('secret-api-token', $user['api_token']);
    }

    public function testClearingTheApiTokenStoresAnEmptyStringNotCiphertext(): void
    {
        $id = $this->makeUser();
        $this->repo->updateApiToken($id, 'secret-api-token');

        $this->repo->updateApiToken($id, '');

        $this->assertSame('', $this->db->query("SELECT api_token FROM users WHERE id = {$id}")->fetchColumn());
    }

    /**
     * Pre-migration rows hold plaintext tokens. Hydration must hand those back
     * untouched rather than throwing — the seeder re-encrypts them on next boot.
     */
    public function testALegacyPlaintextTokenSurvivesHydration(): void
    {
        $id = $this->makeUser();
        $this->db->exec("UPDATE users SET api_token = 'legacy-plaintext' WHERE id = {$id}");

        $user = $this->repo->findById($id);
        $this->assertIsArray($user);
        $this->assertSame('legacy-plaintext', $user['api_token']);
    }

    public function testUpdateAdminTogglesBothWays(): void
    {
        $id = $this->makeUser();

        $this->repo->updateAdmin($id, true);
        $user = $this->repo->findById($id);
        $this->assertIsArray($user);
        $this->assertSame(1, (int) $user['is_admin']);

        $this->repo->updateAdmin($id, false);
        $user = $this->repo->findById($id);
        $this->assertIsArray($user);
        $this->assertSame(0, (int) $user['is_admin']);
    }

    public function testRenameChangesTheUsername(): void
    {
        $id = $this->makeUser('before');

        $this->repo->rename($id, 'after');

        $this->assertNull($this->repo->findByUsername('before'));
        $this->assertIsArray($this->repo->findByUsername('after'));
    }

    public function testRenameToATakenUsernameIsRefusedByTheIndex(): void
    {
        $this->makeUser('taken', 'a@example.test');
        $id = $this->makeUser('mine', 'b@example.test');

        $this->expectException(PDOException::class);
        $this->repo->rename($id, 'taken');
    }

    public function testSoftDeleteHidesTheUserFromEveryNormalLookup(): void
    {
        $id = $this->makeUser();

        $this->repo->softDelete($id);

        $this->assertNull($this->repo->findById($id));
        $this->assertNull($this->repo->findByUsername('racer'));
        $this->assertNull($this->repo->findByEmail('racer@example.test'));
    }

    public function testASoftDeletedUserIsStillReachableForRestore(): void
    {
        $id = $this->makeUser();
        $this->repo->softDelete($id);

        $user = $this->repo->findByIdIncludingDeleted($id);

        $this->assertIsArray($user);
        $this->assertNotNull($user['deleted_at']);
    }

    public function testRestoreBringsTheUserBack(): void
    {
        $id = $this->makeUser();
        $this->repo->softDelete($id);

        $this->repo->restore($id);

        $this->assertIsArray($this->repo->findById($id));
    }

    public function testFindByIdIncludingDeletedReturnsNullWhenTrulyAbsent(): void
    {
        $this->assertNull($this->repo->findByIdIncludingDeleted(999));
    }

    public function testFindEncryptedEmailByIdReturnsCiphertextNotPlaintext(): void
    {
        $id = $this->makeUser();

        $payload = $this->repo->findEncryptedEmailById($id);

        $this->assertIsString($payload);
        $this->assertStringNotContainsString('racer@example.test', $payload);
        $this->assertSame('racer@example.test', (new EmailCrypto(self::SECRET))->decrypt($payload));
    }

    public function testFindEncryptedEmailByIdReturnsNullForAnAbsentUser(): void
    {
        $this->assertNull($this->repo->findEncryptedEmailById(999));
    }

    public function testPaginateReturnsNewestFirst(): void
    {
        $this->makeUser('first', 'a@example.test');
        $this->makeUser('second', 'b@example.test');
        $this->makeUser('third', 'c@example.test');

        $page = $this->repo->paginate(1, 10);

        $this->assertSame(3, $page['total']);
        $this->assertSame(['third', 'second', 'first'], array_column($page['rows'], 'username'));
    }

    public function testPaginateSplitsAcrossPages(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->makeUser("user{$i}", "u{$i}@example.test");
        }

        $this->assertCount(2, $this->repo->paginate(1, 2)['rows']);
        $this->assertCount(2, $this->repo->paginate(2, 2)['rows']);
        $this->assertCount(1, $this->repo->paginate(3, 2)['rows']);
        $this->assertCount(0, $this->repo->paginate(4, 2)['rows']);
    }

    public function testPaginateClampsOutOfRangeArguments(): void
    {
        $this->makeUser();

        $this->assertCount(1, $this->repo->paginate(0, 10)['rows']);
        $this->assertCount(1, $this->repo->paginate(-3, 10)['rows']);
        $this->assertCount(1, $this->repo->paginate(1, 0)['rows']);
    }

    /** Soft-deleted users stay listed so an admin can find and restore them. */
    public function testPaginateStillListsSoftDeletedUsers(): void
    {
        $id = $this->makeUser();
        $this->repo->softDelete($id);

        $page = $this->repo->paginate(1, 10);

        $this->assertCount(1, $page['rows']);
        $this->assertNotNull($page['rows'][0]['deleted_at']);
    }

    public function testCountAllIncludesDeletedButCountLiveDoesNot(): void
    {
        $keep = $this->makeUser('keep', 'a@example.test');
        $drop = $this->makeUser('drop', 'b@example.test');
        $this->repo->softDelete($drop);

        $this->assertSame(2, $this->repo->countAll());
        $this->assertSame(1, $this->repo->countLive());
        $this->assertGreaterThan(0, $keep);
    }

    public function testCountWithApiTokenOnlyCountsConfiguredUsers(): void
    {
        $withToken = $this->makeUser('a', 'a@example.test');
        $this->makeUser('b', 'b@example.test');
        $this->repo->updateApiToken($withToken, 'token');

        $this->assertSame(1, $this->repo->countWithApiToken());
    }

    public function testClearingATokenRemovesTheUserFromTheConfiguredCount(): void
    {
        $id = $this->makeUser();
        $this->repo->updateApiToken($id, 'token');
        $this->repo->updateApiToken($id, '');

        $this->assertSame(0, $this->repo->countWithApiToken());
    }

    public function testCountCreatedSinceCountsRecentSignupsOnly(): void
    {
        $old = $this->makeUser('old', 'a@example.test');
        $this->makeUser('new', 'b@example.test');
        $this->db->exec("UPDATE users SET created_at = datetime('now', '-40 days') WHERE id = {$old}");

        $this->assertSame(1, $this->repo->countCreatedSince(30));
        $this->assertSame(2, $this->repo->countCreatedSince(365));
    }

    public function testCountCreatedBetweenIsolatesTheOlderWindow(): void
    {
        $recent = $this->makeUser('recent', 'a@example.test');
        $older = $this->makeUser('older', 'b@example.test');
        $this->db->exec("UPDATE users SET created_at = datetime('now', '-40 days') WHERE id = {$older}");

        $this->assertSame(1, $this->repo->countCreatedBetween(60, 30));
        $this->assertGreaterThan(0, $recent);
    }

    public function testCountActiveBetweenIsolatesTheOlderWindow(): void
    {
        $id = $this->makeUser();
        $this->db->exec("UPDATE users SET last_synced_at = datetime('now', '-40 days') WHERE id = {$id}");

        $this->assertSame(1, $this->repo->countActiveBetween(60, 30));
        $this->assertSame(0, $this->repo->countActiveSince(30));
    }

    public function testUpdateSyncStatusIsPersisted(): void
    {
        $id = $this->makeUser();

        $this->repo->updateSyncStatus($id, 'running');

        $user = $this->repo->findById($id);
        $this->assertIsArray($user);
        $this->assertSame('running', $user['sync_status']);
    }

    public function testMarkSyncedStampsTheSyncTimeAndCountsAsActive(): void
    {
        $id = $this->makeUser();

        $this->repo->markSynced($id);

        $user = $this->repo->findById($id);
        $this->assertIsArray($user);
        $this->assertNotNull($user['last_synced_at']);
        $this->assertSame(1, $this->repo->countActiveSince(1));
    }
}
