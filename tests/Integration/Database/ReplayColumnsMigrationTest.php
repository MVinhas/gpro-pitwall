<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Database\DatabaseSeeder;
use App\Security\ApiTokenCrypto;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseSeeder::class)]
final class ReplayColumnsMigrationTest extends TestCase
{
    private const array COLUMNS = [
        'replay_laps', 'race_mistakes', 'race_mistake_ms',
        'traffic_ahead_laps', 'traffic_behind_laps', 'avg_race_pos',
    ];

    private function seeder(PDO $db): DatabaseSeeder
    {
        return new DatabaseSeeder(
            $db,
            ['Concentration' => 'concentration'],
            ['Rookie', 'Amateur'],
            [],
            new ApiTokenCrypto('seeder-test-secret'),
        );
    }

    private function memoryDb(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $db;
    }

    /** @return list<string> */
    private function columnsOf(PDO $db, string $table): array
    {
        return array_column($db->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    }

    public function testFreshDatabaseHasTheReplayColumnsOnBothRaceTables(): void
    {
        $db = $this->memoryDb();
        $this->seeder($db)->migrate();

        foreach (['race_telemetry', 'user_race_history'] as $table) {
            foreach (self::COLUMNS as $column) {
                $this->assertContains($column, $this->columnsOf($db, $table), "$table.$column");
            }
        }
    }

    public function testAnExistingDatabaseWithRowsGainsTheColumnsAndKeepsItsData(): void
    {
        $db = $this->memoryDb();
        $this->seeder($db)->migrate();
        foreach (['race_telemetry', 'user_race_history'] as $table) {
            foreach (self::COLUMNS as $column) {
                $db->exec("ALTER TABLE $table DROP COLUMN $column");
            }
        }
        $db->exec(
            "INSERT INTO race_telemetry (season, race, level, group_label, final_pos, laps_completed)
             VALUES (99, 8, 'Rookie', 'Rookie - 31', 5, 51)"
        );

        $db->exec('PRAGMA user_version = 21');
        $this->seeder($db)->migrate();
        $db->exec('PRAGMA user_version = 21');
        $this->seeder($db)->migrate();

        foreach (['race_telemetry', 'user_race_history'] as $table) {
            foreach (self::COLUMNS as $column) {
                $this->assertContains($column, $this->columnsOf($db, $table), "$table.$column");
            }
        }
        $row = $db->query('SELECT final_pos, replay_laps FROM race_telemetry')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(5, (int) $row['final_pos']);
        $this->assertNull($row['replay_laps']);
    }
}
