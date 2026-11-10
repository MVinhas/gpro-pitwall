<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Archive\ArchiveSanitizer;
use App\Database\DatabaseSeeder;
use App\Repository\ApiArchiveRepository;
use App\Repository\AuditLogRepository;
use App\Repository\UserRepository;
use App\Security\ApiTokenCrypto;
use App\Security\EmailCrypto;
use App\Service\AccountErasureService;
use App\Service\GproApiClient;
use App\Tests\Support\ArrayCache;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A manager who deletes their own account leaves behind only what feeds the
 * app's models (race history, weekend snapshots, the money ledger), attached
 * to a row that no longer says who they were.
 */
#[CoversClass(AccountErasureService::class)]
#[CoversClass(UserRepository::class)]
final class AccountErasureServiceTest extends TestCase
{
    private const array KEPT = ['user_race_history', 'user_weekend_state', 'user_economy_ledger'];

    private const array ERASED = [
        'user_api_archive', 'user_standings', 'user_projection_state', 'user_season_objective',
        'user_season_budget', 'user_weekend_choice', 'user_sync_events', 'persistent_tokens',
        'verification_tokens',
    ];

    private PDO $db;
    private UserRepository $users;
    private AuditLogRepository $audit;
    private ArrayCache $cache;
    private AccountErasureService $service;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $secret = 'erasure-test-secret-not-prod';
        (new DatabaseSeeder(
            $this->db,
            ['Concentration' => 'concentration'],
            ['Rookie', 'Amateur', 'Pro', 'Master', 'Elite'],
            [],
            new ApiTokenCrypto($secret),
        ))->migrate();

        $this->users = new UserRepository($this->db, new EmailCrypto($secret), new ApiTokenCrypto($secret));
        $this->audit = new AuditLogRepository($this->db);
        $this->cache = new ArrayCache();
        $this->service = new AccountErasureService($this->users, $this->cache);
    }

    private function makeUser(string $username, string $token): int
    {
        $user = $this->users->create($username, $username . '@example.invalid');
        self::assertNotNull($user);
        $id = (int) $user['id'];
        $this->users->markVerified($id);
        $this->users->updateApiToken($id, $token);

        foreach (
            [
            "INSERT INTO user_race_history (user_id, season, race) VALUES (:u, 84, 7)",
            "INSERT INTO user_weekend_state (user_id, season, race, source) VALUES (:u, 84, 7, 'snapshot')",
            "INSERT INTO user_economy_ledger (user_id, season, race, source, amount, fingerprint)
                 VALUES (:u, 84, 7, 'race', 100, 'f' || :u)",
            "INSERT INTO user_api_archive (user_id, endpoint, fetched_at, payload_gz)
                 VALUES (:u, 'office', '2026-11-01 10:00:00', 'x')",
            "INSERT INTO user_standings (user_id, season, race, managers_json) VALUES (:u, 84, 7, '[\"A rival\"]')",
            "INSERT INTO user_projection_state (user_id, season, race, version, projected_at)
                 VALUES (:u, 84, 7, 1, '2026-11-01 10:00:00')",
            "INSERT INTO user_season_objective (user_id, season, objective) VALUES (:u, 84, 'stay')",
            "INSERT INTO user_season_budget (user_id, season, race, plan_json) VALUES (:u, 84, 7, '{}')",
            "INSERT INTO user_weekend_choice (user_id, season, race, item, choice) VALUES (:u, 84, 7, 'testing', 'do')",
            "INSERT INTO user_sync_events (user_id) VALUES (:u)",
            "INSERT INTO persistent_tokens (user_id, selector, validator_hash, expires_at)
                 VALUES (:u, 'sel' || :u, 'h', '2027-01-01 00:00:00')",
            "INSERT INTO verification_tokens (user_id, code_hmac, expires_at) VALUES (:u, 'h', '2027-01-01 00:00:00')",
            ] as $sql
        ) {
            $this->db->prepare($sql)->execute(['u' => $id]);
        }

        $namespace = GproApiClient::cacheNamespaceFor($token);
        $this->cache->set($namespace . ':office_data', ['email' => $username . '@gpro.invalid']);
        $this->cache->set($namespace . ':race_analysis_84_7', ['laps' => 60]);

        return $id;
    }

    private function rows(string $table, int $userId): int
    {
        self::assertContains($table, [...self::KEPT, ...self::ERASED]);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id = :u");
        $stmt->execute(['u' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    public function testErasingKeepsWhatFeedsTheModelsAndDeletesTheRest(): void
    {
        $id = $this->makeUser('racer', 'token-of-racer');

        $this->service->eraseOwnAccount($id);

        foreach (self::KEPT as $table) {
            self::assertSame(1, $this->rows($table, $id), "{$table} must be kept");
        }
        foreach (self::ERASED as $table) {
            self::assertSame(0, $this->rows($table, $id), "{$table} must be erased");
        }
    }

    public function testAnotherManagersDataIsUntouched(): void
    {
        $id = $this->makeUser('racer', 'token-of-racer');
        $other = $this->makeUser('rival', 'token-of-rival');

        $this->service->eraseOwnAccount($id);

        foreach ([...self::KEPT, ...self::ERASED] as $table) {
            self::assertSame(1, $this->rows($table, $other), "{$table} of another manager");
        }
        $otherRow = $this->users->findById($other);
        self::assertNotNull($otherRow);
        self::assertSame('rival', $otherRow['username']);
        self::assertSame('token-of-rival', $otherRow['api_token']);
        self::assertTrue($this->cache->has(GproApiClient::cacheNamespaceFor('token-of-rival') . ':office_data'));
    }

    public function testTheRowNoLongerSaysWhoTheManagerWas(): void
    {
        $id = $this->makeUser('racer', 'token-of-racer');
        $this->users->updateAdmin($id, true);

        $this->service->eraseOwnAccount($id);

        self::assertNull($this->users->findById($id), 'an erased account cannot log in');
        self::assertNull($this->users->findByUsername('racer'));
        self::assertNull($this->users->findByEmail('racer@example.invalid'));

        $row = $this->users->findByIdIncludingDeleted($id);
        self::assertNotNull($row);
        self::assertSame('deleted-' . $id, $row['username']);
        self::assertSame('', $row['email_encrypted']);
        self::assertSame('deleted-' . $id, $row['email_hash']);
        self::assertNull($row['api_token']);
        self::assertSame(0, (int) $row['is_admin']);
        self::assertNotEmpty($row['deleted_at']);
        self::assertTrue($this->users->isErased($id));
    }

    public function testTheUsernameAndEmailCanRegisterAgain(): void
    {
        $id = $this->makeUser('racer', 'token-of-racer');

        $this->service->eraseOwnAccount($id);

        $again = $this->users->create('racer', 'racer@example.invalid');
        self::assertNotNull($again);
        self::assertNotSame($id, (int) $again['id']);
    }

    public function testTheCachedGproPayloadsOfThatTokenAreRemoved(): void
    {
        $id = $this->makeUser('racer', 'token-of-racer');
        $namespace = GproApiClient::cacheNamespaceFor('token-of-racer');

        $this->service->eraseOwnAccount($id);

        self::assertFalse($this->cache->has($namespace . ':office_data'));
        self::assertFalse($this->cache->has($namespace . ':race_analysis_84_7'));
    }

    public function testAuditEntriesAboutTheManagerLoseTheirDetails(): void
    {
        $id = $this->makeUser('racer', 'token-of-racer');
        $other = $this->makeUser('rival', 'token-of-rival');
        $this->audit->record(99, 'rename', $id, ['from' => 'racer_old', 'to' => 'racer']);
        $this->audit->record(99, 'username_reminder', $other, ['username' => 'rival']);

        $this->service->eraseOwnAccount($id);

        $byTarget = [];
        foreach ($this->audit->recent() as $entry) {
            $byTarget[(int) $entry['target_user_id']] = $entry;
        }
        self::assertSame('rename', $byTarget[$id]['action'], 'the entry itself stays');
        self::assertNull($byTarget[$id]['metadata_json']);
        self::assertStringContainsString('rival', (string) $byTarget[$other]['metadata_json']);
    }

    public function testAnAdminDeleteStillKeepsEverythingAndCanBeRestored(): void
    {
        $id = $this->makeUser('racer', 'token-of-racer');

        $this->users->softDelete($id);

        foreach ([...self::KEPT, ...self::ERASED] as $table) {
            self::assertSame(1, $this->rows($table, $id), "{$table} after an admin delete");
        }
        self::assertFalse($this->users->isErased($id));

        $this->users->restore($id);
        $row = $this->users->findById($id);
        self::assertNotNull($row);
        self::assertSame('racer', $row['username']);
        self::assertSame('token-of-racer', $row['api_token']);
    }

    public function testTheArchiveRebuildNoLongerVisitsTheAccountSoItsHistoryIsNeverRewritten(): void
    {
        $id = $this->makeUser('racer', 'token-of-racer');
        $other = $this->makeUser('rival', 'token-of-rival');
        $archive = new ApiArchiveRepository($this->db, new ArchiveSanitizer());

        $this->service->eraseOwnAccount($id);

        self::assertSame([$other], $archive->userIds());
    }

    public function testErasingAnAccountThatIsAlreadyGoneDoesNothing(): void
    {
        $id = $this->makeUser('racer', 'token-of-racer');
        $this->service->eraseOwnAccount($id);
        $before = $this->users->findByIdIncludingDeleted($id);

        $this->service->eraseOwnAccount($id);
        $this->service->eraseOwnAccount(9999);

        self::assertSame($before, $this->users->findByIdIncludingDeleted($id));
    }

    public function testAnAccountWithNoTokenIsErasedToo(): void
    {
        $user = $this->users->create('tokenless', 'tokenless@example.invalid');
        self::assertNotNull($user);
        $id = (int) $user['id'];
        $this->users->markVerified($id);

        $this->service->eraseOwnAccount($id);

        self::assertTrue($this->users->isErased($id));
    }
}
