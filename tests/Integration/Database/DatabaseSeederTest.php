<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Database\DatabaseSeeder;
use App\Security\ApiTokenCrypto;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseSeeder::class)]
final class DatabaseSeederTest extends TestCase
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

    public function testFirstMigrateBuildsSchemaAndStampsVersion(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->assertSame(0, (int) $db->query('PRAGMA user_version')->fetchColumn());

        $this->makeSeeder($db)->migrate();

        $this->assertGreaterThan(0, (int) $db->query('PRAGMA user_version')->fetchColumn());

        $users = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")
            ->fetchColumn();
        $this->assertSame('users', $users);
    }

    public function testSecondMigrateIsGatedAndSkipsWork(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->makeSeeder($db)->migrate();

        // Drop a table the seeder creates. Because user_version is already at
        // the current schema version, a second migrate() must be a no-op and
        // must NOT recreate it — proving the gate short-circuits the work.
        $db->exec('DROP TABLE pilots');
        $this->makeSeeder($db)->migrate();

        $exists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='pilots'")
            ->fetchColumn();
        $this->assertFalse($exists, 'gated migrate must not recreate the dropped table');
    }

    public function testMigrateRerunsWhenVersionIsBehind(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->makeSeeder($db)->migrate();

        // Simulate a fresh deploy that introduced a new migration: reset the
        // version and drop a table. migrate() now reruns the full sequence.
        $db->exec('DROP TABLE pilots');
        $db->exec('PRAGMA user_version = 0');
        $this->makeSeeder($db)->migrate();

        $exists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='pilots'")
            ->fetchColumn();
        $this->assertSame('pilots', $exists, 'a behind version must rerun migrate and rebuild the table');
    }
}
