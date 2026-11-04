<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Database\DatabaseSeeder;
use App\Security\ApiTokenCrypto;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseSeeder::class)]
final class SeasonObjectiveStayMigrationTest extends TestCase
{
    private PDO $db;
    private DatabaseSeeder $seeder;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->seeder = new DatabaseSeeder(
            $this->db,
            ['Concentration' => 'concentration', 'Talent' => 'talent'],
            ['Rookie', 'Amateur'],
            [],
            new ApiTokenCrypto('seeder-test-secret'),
        );
        $this->seeder->migrate();
    }

    private function seedTheOldTable(): void
    {
        $this->db->exec('DROP TABLE user_season_objective');
        $this->db->exec(
            "CREATE TABLE user_season_objective (
                user_id INTEGER NOT NULL,
                season INTEGER NOT NULL,
                objective TEXT NOT NULL CHECK (objective IN ('promote', 'hold', 'survive')),
                set_at TEXT NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )"
        );
        $this->db->exec('CREATE UNIQUE INDEX idx_user_season_objective ON user_season_objective (user_id, season)');
        $this->db->exec("INSERT INTO users (username, email_hash, email_encrypted) VALUES ('u', 'h', 'e')");
        $userId = (int) $this->db->lastInsertId();
        $this->db->exec(
            "INSERT INTO user_season_objective (user_id, season, objective, set_at) VALUES
                ($userId, 110, 'promote', '2026-01-01 10:00:00'),
                ($userId, 111, 'hold', '2026-02-01 10:00:00'),
                ($userId, 112, 'survive', '2026-03-01 10:00:00')"
        );
        $this->db->exec('PRAGMA user_version = 19');
    }

    private function tableSql(): string
    {
        return (string) $this->db->query(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'user_season_objective'"
        )->fetchColumn();
    }

    public function testHoldAndSurvivePinsBecomeStay(): void
    {
        $this->seedTheOldTable();

        $this->seeder->migrate();

        $rows = $this->db->query('SELECT season, objective, set_at FROM user_season_objective ORDER BY season')
            ->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame([
            ['season' => 110, 'objective' => 'promote', 'set_at' => '2026-01-01 10:00:00'],
            ['season' => 111, 'objective' => 'stay', 'set_at' => '2026-02-01 10:00:00'],
            ['season' => 112, 'objective' => 'stay', 'set_at' => '2026-03-01 10:00:00'],
        ], $rows);
        $this->assertSame(20, (int) $this->db->query('PRAGMA user_version')->fetchColumn());
    }

    public function testTheNewCheckRejectsHoldAndTheIndexSurvives(): void
    {
        $this->seedTheOldTable();
        $this->seeder->migrate();

        $this->assertStringNotContainsString("'hold'", $this->tableSql());
        $this->assertSame(1, (int) $this->db->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'idx_user_season_objective'"
        )->fetchColumn());

        $this->expectException(\PDOException::class);
        $this->db->exec("INSERT INTO user_season_objective (user_id, season, objective) VALUES (1, 200, 'hold')");
    }

    public function testASecondRunChangesNothing(): void
    {
        $this->seedTheOldTable();
        $this->seeder->migrate();
        $sql = $this->tableSql();

        $this->db->exec('PRAGMA user_version = 19');
        $this->seeder->migrate();

        $this->assertSame($sql, $this->tableSql());
        $this->assertSame(3, (int) $this->db->query('SELECT COUNT(*) FROM user_season_objective')->fetchColumn());
        $this->assertSame(
            ['promote', 'stay', 'stay'],
            $this->db->query('SELECT objective FROM user_season_objective ORDER BY season')->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    public function testAFreshDatabaseNeedsNoMigration(): void
    {
        $this->assertStringContainsString("'promote', 'stay'", $this->tableSql());
    }
}
