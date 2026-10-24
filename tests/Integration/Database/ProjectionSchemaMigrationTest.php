<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Database\DatabaseSeeder;
use App\Security\ApiTokenCrypto;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseSeeder::class)]
final class ProjectionSchemaMigrationTest extends TestCase
{
    private const array TABLES = [
        'user_weekend_state',
        'user_standings',
        'user_economy_ledger',
        'user_projection_state',
    ];

    private function makeSeeder(PDO $db): DatabaseSeeder
    {
        return new DatabaseSeeder(
            $db,
            ['Concentration' => 'concentration', 'Talent' => 'talent'],
            ['Rookie', 'Amateur'],
            [],
            new ApiTokenCrypto('seeder-test-secret'),
        );
    }

    private function memoryDb(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA foreign_keys = ON');

        return $db;
    }

    private function insertUser(PDO $db, string $name = 'u'): int
    {
        $db->exec("INSERT INTO users (username, email_hash, email_encrypted, verified_at)
             VALUES ('$name', 'h$name', 'e$name', datetime('now'))");

        return (int) $db->lastInsertId();
    }

    /** @return list<string> */
    private function indexColumns(PDO $db, string $index): array
    {
        return array_column($db->query("PRAGMA index_info($index)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    }

    public function testMigratesV16DatabaseAddingProjectionTablesIdempotently(): void
    {
        $db = $this->memoryDb();
        $seeder = $this->makeSeeder($db);
        $seeder->migrate();
        $userId = $this->insertUser($db);
        $db->exec(
            "INSERT INTO user_api_archive (user_id, endpoint, fetched_at, payload_gz)
             VALUES ($userId, 'office', '2026-10-23 10:00:00', x'00')"
        );
        foreach (self::TABLES as $table) {
            $db->exec("DROP TABLE $table");
        }
        $db->exec('PRAGMA user_version = 16');

        $seeder->migrate();
        $seeder->migrate();

        $this->assertSame(17, (int) $db->query('PRAGMA user_version')->fetchColumn());
        foreach (self::TABLES as $table) {
            $this->assertSame(
                1,
                (int) $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = '$table'")
                    ->fetchColumn(),
                $table,
            );
        }
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM user_api_archive')->fetchColumn());
    }

    public function testPerRaceTablesAreIndexedOnUserSeasonRace(): void
    {
        $db = $this->memoryDb();
        $this->makeSeeder($db)->migrate();

        $this->assertSame(['user_id', 'season', 'race'], $this->indexColumns($db, 'idx_user_weekend_state_natural'));
        $this->assertSame(['user_id', 'season', 'race'], $this->indexColumns($db, 'idx_user_standings_natural'));
        $this->assertSame(['user_id', 'season', 'race'], $this->indexColumns($db, 'idx_user_economy_ledger_race'));
    }

    public function testWeekendStateIsUniquePerRace(): void
    {
        $db = $this->memoryDb();
        $this->makeSeeder($db)->migrate();
        $userId = $this->insertUser($db);
        $insert = $db->prepare(
            "INSERT INTO user_weekend_state (user_id, season, race, source) VALUES (:u, 107, 5, 'snapshot')"
        );
        $insert->execute(['u' => $userId]);

        $this->expectException(\PDOException::class);
        $insert->execute(['u' => $userId]);
    }

    public function testStandingsAreUniquePerRace(): void
    {
        $db = $this->memoryDb();
        $this->makeSeeder($db)->migrate();
        $userId = $this->insertUser($db);
        $insert = $db->prepare('INSERT INTO user_standings (user_id, season, race) VALUES (:u, 107, 5)');
        $insert->execute(['u' => $userId]);

        $this->expectException(\PDOException::class);
        $insert->execute(['u' => $userId]);
    }

    public function testWeekendSourceIsRestrictedToSnapshotOrHistory(): void
    {
        $db = $this->memoryDb();
        $this->makeSeeder($db)->migrate();
        $userId = $this->insertUser($db);

        $this->expectException(\PDOException::class);
        $db->exec("INSERT INTO user_weekend_state (user_id, season, race, source) VALUES ($userId, 1, 1, 'guess')");
    }

    public function testLedgerFingerprintIsUniquePerUser(): void
    {
        $db = $this->memoryDb();
        $this->makeSeeder($db)->migrate();
        $userId = $this->insertUser($db, 'a');
        $otherId = $this->insertUser($db, 'b');

        $insert = $db->prepare(
            "INSERT INTO user_economy_ledger (user_id, season, race, source, description, amount, fingerprint)
             VALUES (:u, 107, 5, 'race', 'Sponsor', 1000, 'fp1')"
        );
        $insert->execute(['u' => $userId]);
        $insert->execute(['u' => $otherId]);

        $this->expectException(\PDOException::class);
        $insert->execute(['u' => $userId]);
    }

    public function testProjectionRowsCascadeWithUser(): void
    {
        $db = $this->memoryDb();
        $this->makeSeeder($db)->migrate();
        $userId = $this->insertUser($db);
        $db->exec("INSERT INTO user_weekend_state (user_id, season, race, source) VALUES ($userId, 1, 1, 'history')");
        $db->exec("INSERT INTO user_standings (user_id, season, race) VALUES ($userId, 1, 1)");
        $db->exec(
            "INSERT INTO user_economy_ledger (user_id, season, race, source, description, amount, fingerprint)
             VALUES ($userId, 1, 1, 'economy', 'Fee', -5, 'fp')"
        );
        $db->exec("INSERT INTO user_projection_state (user_id, season, race, version, projected_at) VALUES ($userId, 1, 1, 1, '2026-10-24 10:00:00')");

        $db->exec("DELETE FROM users WHERE id = $userId");

        foreach (self::TABLES as $table) {
            $this->assertSame(0, (int) $db->query("SELECT COUNT(*) FROM $table")->fetchColumn(), $table);
        }
    }
}
