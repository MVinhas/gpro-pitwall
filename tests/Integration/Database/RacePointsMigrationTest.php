<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Database\DatabaseSeeder;
use App\Security\ApiTokenCrypto;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Rows stored before 1.21.49 carry F1's 25-18-15… points; the migration
 * rewrites them on GPRO's 10-8-6-5-4-3-2-1 from the stored finish.
 */
#[CoversClass(DatabaseSeeder::class)]
final class RacePointsMigrationTest extends TestCase
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

    /** Old-scale rows: a win, a 3rd, a 9th, an 8th and a DNF with no finish. */
    private function seedOldPoints(): void
    {
        $this->db->exec(
            'INSERT INTO user_race_history (user_id, season, race, final_pos, points) VALUES
                (1, 110, 1, 1, 25), (1, 110, 2, 3, 15), (1, 110, 3, 9, 2),
                (1, 110, 4, 8, 4), (1, 110, 5, NULL, NULL)'
        );
        $this->db->exec(
            "INSERT INTO race_telemetry (season, race, level, final_pos, points) VALUES
                (110, 1, 'Amateur', 2, 18), (110, 2, 'Amateur', 10, 1), (110, 3, 'Amateur', NULL, NULL)"
        );
        $this->db->exec('PRAGMA user_version = 24');
    }

    /**
     * @return array<int, int|null> points keyed by race
     */
    private function points(string $table): array
    {
        $rows = $this->db->query("SELECT race, points FROM {$table} ORDER BY race")->fetchAll(PDO::FETCH_ASSOC);

        return array_combine(
            array_map(static fn (array $r): int => (int) $r['race'], $rows),
            array_map(static fn (array $r): ?int => $r['points'] === null ? null : (int) $r['points'], $rows),
        );
    }

    public function testRecomputesStoredPointsOnGprosScale(): void
    {
        $this->seedOldPoints();

        $this->seeder->migrate();

        $this->assertSame([1 => 10, 2 => 6, 3 => 0, 4 => 1, 5 => null], $this->points('user_race_history'));
        $this->assertSame([1 => 8, 2 => 0, 3 => null], $this->points('race_telemetry'));
        $this->assertSame(25, (int) $this->db->query('PRAGMA user_version')->fetchColumn());
    }

    public function testRunningItAgainChangesNothing(): void
    {
        $this->seedOldPoints();
        $this->seeder->migrate();
        $this->db->exec('PRAGMA user_version = 24');

        $this->seeder->migrate();

        $this->assertSame([1 => 10, 2 => 6, 3 => 0, 4 => 1, 5 => null], $this->points('user_race_history'));
        $this->assertSame([1 => 8, 2 => 0, 3 => null], $this->points('race_telemetry'));
    }
}
