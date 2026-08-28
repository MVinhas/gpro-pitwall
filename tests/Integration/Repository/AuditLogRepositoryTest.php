<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Repository\AuditLogRepository;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditLogRepository::class)]
final class AuditLogRepositoryTest extends TestCase
{
    private PDO $db;
    private AuditLogRepository $repo;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("
            CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                actor_id INTEGER NOT NULL,
                action TEXT NOT NULL,
                target_user_id INTEGER,
                metadata_json TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $this->repo = new AuditLogRepository($this->db);
    }

    public function testRecordPersistsActorActionAndTarget(): void
    {
        $this->repo->record(1, 'user.soft_delete', 42);

        $rows = $this->repo->recent();
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]['actor_id']);
        $this->assertSame('user.soft_delete', $rows[0]['action']);
        $this->assertSame(42, (int) $rows[0]['target_user_id']);
    }

    public function testRecordEncodesMetadataAsJson(): void
    {
        $this->repo->record(1, 'user.toggle_admin', 42, ['from' => false, 'to' => true]);

        $rows = $this->repo->recent();
        $this->assertIsString($rows[0]['metadata_json']);
        $this->assertSame(
            ['from' => false, 'to' => true],
            json_decode($rows[0]['metadata_json'], true)
        );
    }

    public function testEmptyMetadataIsStoredAsNullNotAnEmptyJsonObject(): void
    {
        $this->repo->record(1, 'user.restore', 42);

        $rows = $this->repo->recent();
        $this->assertNull($rows[0]['metadata_json']);
    }

    public function testTargetUserIdMayBeNullForNonUserActions(): void
    {
        $this->repo->record(1, 'admin.login', null);

        $rows = $this->repo->recent();
        $this->assertNull($rows[0]['target_user_id']);
    }

    public function testRecentIsEmptyOnAFreshLedger(): void
    {
        $this->assertSame([], $this->repo->recent());
    }

    public function testRecentReturnsMostRecentEntriesFirst(): void
    {
        $this->repo->record(1, 'first', null);
        $this->repo->record(1, 'second', null);
        $this->repo->record(1, 'third', null);

        $rows = $this->repo->recent();
        $this->assertSame(['third', 'second', 'first'], array_column($rows, 'action'));
    }

    public function testRecentHonoursTheLimit(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->repo->record(1, "action-{$i}", null);
        }

        $this->assertCount(3, $this->repo->recent(3));
    }

    public function testLimitIsClampedToAtLeastOne(): void
    {
        $this->repo->record(1, 'only', null);

        $this->assertCount(1, $this->repo->recent(0));
        $this->assertCount(1, $this->repo->recent(-5));
    }

    public function testLimitIsClampedToFiveHundred(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->repo->record(1, "action-{$i}", null);
        }

        $this->assertCount(12, $this->repo->recent(100000));
    }
}
