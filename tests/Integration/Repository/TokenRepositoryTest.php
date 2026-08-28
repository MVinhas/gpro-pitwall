<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Repository\TokenRepository;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TokenRepository::class)]
final class TokenRepositoryTest extends TestCase
{
    private PDO $db;
    private TokenRepository $repo;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("
            CREATE TABLE verification_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                code_hmac TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $this->repo = new TokenRepository($this->db);
    }

    private function future(): string
    {
        return (new DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s');
    }

    public function testStoreThenFindLatestByUserId(): void
    {
        $this->repo->store(7, 'hmac-a', $this->future());

        $row = $this->repo->findLatestByUserId(7);
        $this->assertIsArray($row);
        $this->assertSame('hmac-a', $row['code_hmac']);
        $this->assertSame(0, (int) $row['attempts']);
    }

    public function testFindLatestReturnsNullWhenUserHasNoToken(): void
    {
        $this->assertNull($this->repo->findLatestByUserId(404));
    }

    public function testFindLatestReturnsTheNewestTokenNotTheFirst(): void
    {
        $this->repo->store(7, 'older', $this->future());
        $this->repo->store(7, 'newer', $this->future());

        $row = $this->repo->findLatestByUserId(7);
        $this->assertIsArray($row);
        $this->assertSame('newer', $row['code_hmac']);
    }

    public function testFindLatestIsScopedToTheRequestedUser(): void
    {
        $this->repo->store(1, 'user-one', $this->future());
        $this->repo->store(2, 'user-two', $this->future());

        $row = $this->repo->findLatestByUserId(1);
        $this->assertIsArray($row);
        $this->assertSame('user-one', $row['code_hmac']);
    }

    public function testIncrementAttemptsRaisesTheCounterByOneEachCall(): void
    {
        $id = $this->repo->store(7, 'hmac', $this->future());

        $this->repo->incrementAttempts($id);
        $this->repo->incrementAttempts($id);

        $row = $this->repo->findLatestByUserId(7);
        $this->assertIsArray($row);
        $this->assertSame(2, (int) $row['attempts']);
    }

    public function testDeleteRemovesOnlyTheNamedToken(): void
    {
        $keep = $this->repo->store(7, 'keep', $this->future());
        $drop = $this->repo->store(8, 'drop', $this->future());

        $this->repo->delete($drop);

        $this->assertNull($this->repo->findLatestByUserId(8));
        $this->assertIsArray($this->repo->findLatestByUserId(7));
        $this->assertGreaterThan(0, $keep);
    }

    public function testDeleteExpiredOnlyRemovesPastTokens(): void
    {
        $past = (new DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $this->repo->store(1, 'stale', $past);
        $this->repo->store(2, 'fresh', $this->future());

        $this->repo->deleteExpired((new DateTimeImmutable())->format('Y-m-d H:i:s'));

        $this->assertNull($this->repo->findLatestByUserId(1));
        $this->assertIsArray($this->repo->findLatestByUserId(2));
    }
}
