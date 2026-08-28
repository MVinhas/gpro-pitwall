<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Repository\PendingRegistrationRepository;
use App\Security\EmailCrypto;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PendingRegistrationRepository::class)]
final class PendingRegistrationRepositoryTest extends TestCase
{
    private PDO $db;
    private EmailCrypto $crypto;
    private PendingRegistrationRepository $repo;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("
            CREATE TABLE pending_registrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL,
                email_encrypted TEXT NOT NULL,
                email_hash TEXT NOT NULL,
                code_hmac TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $this->crypto = new EmailCrypto('test-secret-for-pending-registrations');
        $this->repo = new PendingRegistrationRepository($this->db, $this->crypto);
    }

    private function future(): string
    {
        return (new DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s');
    }

    public function testCreateStoresEncryptedEmailAndRoundTripsIt(): void
    {
        $id = $this->repo->create('racer', 'racer@example.test', 'hmac', $this->future());

        $row = $this->repo->find($id);
        $this->assertIsArray($row);
        $this->assertSame('racer', $row['username']);
        $this->assertIsString($row['email_encrypted']);
        $this->assertSame('racer@example.test', $this->crypto->decrypt($row['email_encrypted']));
    }

    public function testCreateNeverStoresThePlaintextEmail(): void
    {
        $id = $this->repo->create('racer', 'racer@example.test', 'hmac', $this->future());

        $row = $this->repo->find($id);
        $this->assertIsArray($row);
        $this->assertStringNotContainsString('racer@example.test', (string) $row['email_encrypted']);
        $this->assertStringNotContainsString('racer@example.test', (string) $row['email_hash']);
    }

    public function testEmailHashIsDeterministicSoLookupsMatch(): void
    {
        $this->repo->create('one', 'shared@example.test', 'h1', $this->future());
        $id = $this->repo->create('two', 'shared@example.test', 'h2', $this->future());

        $row = $this->repo->find($id);
        $this->assertIsArray($row);
        $this->assertSame($this->crypto->hash('shared@example.test'), $row['email_hash']);
    }

    public function testFindReturnsNullForAnAbsentRow(): void
    {
        $this->assertNull($this->repo->find(999));
    }

    public function testUsernameIsNotUniqueSoAnAttemptCannotSquatTheNamespace(): void
    {
        $first = $this->repo->create('taken', 'a@example.test', 'h', $this->future());
        $second = $this->repo->create('taken', 'b@example.test', 'h', $this->future());

        $this->assertNotSame($first, $second);
        $this->assertIsArray($this->repo->find($first));
        $this->assertIsArray($this->repo->find($second));
    }

    public function testIncrementAttemptsRaisesTheCounter(): void
    {
        $id = $this->repo->create('racer', 'a@example.test', 'h', $this->future());

        $this->repo->incrementAttempts($id);
        $this->repo->incrementAttempts($id);
        $this->repo->incrementAttempts($id);

        $row = $this->repo->find($id);
        $this->assertIsArray($row);
        $this->assertSame(3, (int) $row['attempts']);
    }

    public function testUpdateCodeReplacesTheCodeAndResetsTheAttemptBudget(): void
    {
        $id = $this->repo->create('racer', 'a@example.test', 'old-hmac', $this->future());
        $this->repo->incrementAttempts($id);
        $this->repo->incrementAttempts($id);

        $newExpiry = (new DateTimeImmutable('+30 minutes'))->format('Y-m-d H:i:s');
        $this->repo->updateCode($id, 'new-hmac', $newExpiry);

        $row = $this->repo->find($id);
        $this->assertIsArray($row);
        $this->assertSame('new-hmac', $row['code_hmac']);
        $this->assertSame($newExpiry, $row['expires_at']);
        $this->assertSame(0, (int) $row['attempts']);
    }

    public function testDeleteRemovesTheRow(): void
    {
        $id = $this->repo->create('racer', 'a@example.test', 'h', $this->future());

        $this->repo->delete($id);

        $this->assertNull($this->repo->find($id));
    }

    public function testDeleteByEmailHashRemovesEverySiblingForThatEmail(): void
    {
        $a = $this->repo->create('one', 'same@example.test', 'h', $this->future());
        $b = $this->repo->create('two', 'same@example.test', 'h', $this->future());
        $other = $this->repo->create('three', 'other@example.test', 'h', $this->future());

        $this->repo->deleteByEmailHash($this->crypto->hash('same@example.test'));

        $this->assertNull($this->repo->find($a));
        $this->assertNull($this->repo->find($b));
        $this->assertIsArray($this->repo->find($other));
    }

    public function testCountActiveExcludesExpiredRows(): void
    {
        $past = (new DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $this->repo->create('stale', 'a@example.test', 'h', $past);
        $this->repo->create('live-a', 'b@example.test', 'h', $this->future());
        $this->repo->create('live-b', 'c@example.test', 'h', $this->future());

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->assertSame(2, $this->repo->countActive($now));
    }

    public function testCountActiveIsZeroOnAnEmptyTable(): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->assertSame(0, $this->repo->countActive($now));
    }

    public function testDeleteExpiredPurgesOnlyPastRows(): void
    {
        $past = (new DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $stale = $this->repo->create('stale', 'a@example.test', 'h', $past);
        $fresh = $this->repo->create('fresh', 'b@example.test', 'h', $this->future());

        $this->repo->deleteExpired((new DateTimeImmutable())->format('Y-m-d H:i:s'));

        $this->assertNull($this->repo->find($stale));
        $this->assertIsArray($this->repo->find($fresh));
    }
}
