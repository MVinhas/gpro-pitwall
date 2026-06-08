<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Repository\PersistentTokenRepository;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PersistentTokenRepository::class)]
final class PersistentTokenRepositoryTest extends TestCase
{
    private PDO $db;
    private PersistentTokenRepository $repo;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("
            CREATE TABLE persistent_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                selector TEXT NOT NULL UNIQUE,
                validator_hash TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $this->repo = new PersistentTokenRepository($this->db);
    }

    private function future(): string
    {
        return (new DateTimeImmutable('+30 days'))->format('Y-m-d H:i:s');
    }

    public function testCreateThenFindBySelector(): void
    {
        $this->repo->create(42, 'sel-abc', 'hash-xyz', $this->future());

        $row = $this->repo->findBySelector('sel-abc');
        $this->assertIsArray($row);
        $this->assertSame(42, (int) $row['user_id']);
        $this->assertSame('hash-xyz', $row['validator_hash']);
    }

    public function testFindBySelectorReturnsNullWhenUnknown(): void
    {
        $this->assertNull($this->repo->findBySelector('does-not-exist'));
    }

    public function testSelectorIsUnique(): void
    {
        $this->repo->create(1, 'dup', 'h1', $this->future());

        $this->expectException(\PDOException::class);
        $this->repo->create(2, 'dup', 'h2', $this->future());
    }

    public function testRotateUpdatesHashAndExpiry(): void
    {
        $id = $this->repo->create(1, 'sel', 'old-hash', $this->future());

        $newExpiry = (new DateTimeImmutable('+60 days'))->format('Y-m-d H:i:s');
        $this->repo->rotate($id, 'new-hash', $newExpiry);

        $row = $this->repo->findBySelector('sel');
        $this->assertIsArray($row);
        $this->assertSame('new-hash', $row['validator_hash']);
        $this->assertSame($newExpiry, $row['expires_at']);
    }

    public function testDeleteRemovesRow(): void
    {
        $id = $this->repo->create(1, 'sel', 'h', $this->future());
        $this->repo->delete($id);
        $this->assertNull($this->repo->findBySelector('sel'));
    }

    public function testDeleteAllForUserRemovesEveryTokenOfThatUser(): void
    {
        $this->repo->create(7, 'a', 'h', $this->future());
        $this->repo->create(7, 'b', 'h', $this->future());
        $this->repo->create(8, 'c', 'h', $this->future());

        $this->repo->deleteAllForUser(7);

        $this->assertNull($this->repo->findBySelector('a'));
        $this->assertNull($this->repo->findBySelector('b'));
        $this->assertIsArray($this->repo->findBySelector('c'));
    }

    public function testDeleteExpiredOnlyRemovesPastTokens(): void
    {
        $past = (new DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s');
        $this->repo->create(1, 'old', 'h', $past);
        $this->repo->create(1, 'fresh', 'h', $this->future());

        $this->repo->deleteExpired((new DateTimeImmutable())->format('Y-m-d H:i:s'));

        $this->assertNull($this->repo->findBySelector('old'));
        $this->assertIsArray($this->repo->findBySelector('fresh'));
    }
}
