<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Database\DatabaseSeeder;
use App\Security\ApiTokenCrypto;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseSeeder::class)]
final class PhantomTdRepairMigrationTest extends TestCase
{
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

    public function testClearsHasTdWhereTdStatsAreEmpty(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $seeder = $this->makeSeeder($db);
        $seeder->migrate();

        $insert = $db->prepare(
            'INSERT INTO race_telemetry (season, race, level, track_id, has_td, td_overall)
             VALUES (:season, :race, :level, :track_id, :has_td, :td_overall)'
        );
        $rows = [
            ['race' => 1, 'level' => 'Rookie', 'td_overall' => null],
            ['race' => 2, 'level' => 'Pro', 'td_overall' => 0],
            ['race' => 3, 'level' => 'Pro', 'td_overall' => 150, 'has_td' => 1],
            ['race' => 4, 'level' => 'Pro', 'td_overall' => 150, 'has_td' => 0],
        ];
        foreach ($rows as $row) {
            $insert->execute([
                'season' => 100,
                'race' => $row['race'],
                'level' => $row['level'],
                'track_id' => 1,
                'has_td' => $row['has_td'] ?? 1,
                'td_overall' => $row['td_overall'],
            ]);
        }

        $db->exec('PRAGMA user_version = 13');
        $seeder->migrate();
        $seeder->migrate();

        $flags = $db->query('SELECT race, has_td FROM race_telemetry ORDER BY race')
            ->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame([1 => 0, 2 => 0, 3 => 1, 4 => 0], $flags);
        $this->assertSame(14, (int) $db->query('PRAGMA user_version')->fetchColumn());
    }
}
