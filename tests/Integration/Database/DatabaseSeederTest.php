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
    /** @param array<string, mixed> $secrets */
    private function makeSeeder(PDO $db, array $secrets = []): DatabaseSeeder
    {
        return new DatabaseSeeder(
            $db,
            ['Concentration' => 'concentration', 'Talent' => 'talent'],
            ['Rookie', 'Amateur'],
            $secrets,
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

    public function testSeedTracksFromCsvPopulatesOvertakingAndGrip(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $seeder = $this->makeSeeder($db);
        $seeder->migrate();

        // Minimal sheet: title row, header row, one track row with the
        // columns the importer reads (semicolon-separated, index 0..44).
        $row = array_fill(0, 45, '0');
        $row[0] = 'Testring';
        $row[2] = 'Hard';
        $row[4] = 'High';
        $row[5] = 'Medium';
        $row[16] = 'Very Low';

        $csv = tempnam(sys_get_temp_dir(), 'tracks');
        file_put_contents($csv, "Track List\nName;Downforce;Overtaking\n" . implode(';', $row) . "\n");

        $count = $seeder->seedTracksFromCsv($csv);
        unlink($csv);

        $this->assertSame(1, $count);
        $track = $db->query("SELECT overtaking, grip FROM tracks WHERE name = 'Testring'")
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('Hard', $track['overtaking']);
        $this->assertSame('Very Low', $track['grip']);
    }

    public function testSeedTracksFromMissingCsvIsANoOp(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $seeder = $this->makeSeeder($db);
        $seeder->migrate();

        $this->assertSame(0, $seeder->seedTracksFromCsv('/nonexistent/tracks.csv'));
    }

    /**
     * GPRO repriced Fitness (700k → 750k) and added a seventh session. An
     * existing prod DB already holds the old rows, so INSERT OR IGNORE alone
     * leaves them stale forever — the reprice has to be an explicit update.
     */
    public function testSeedTrainingsRepricesExistingRows(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $old = ['trainings_seed' => [['Fitness', 700000, 0, 0, 0, 0, 0, 2, 0, -7.4, -1]]];
        $this->makeSeeder($db, $old)->migrate();

        $this->assertSame(700000, (int) $db->query("SELECT cost FROM trainings WHERE name = 'Fitness'")
            ->fetchColumn());

        // New release ships the corrected price; a bumped schema version reruns
        // the seed against the existing row.
        $new = ['trainings_seed' => [['Fitness', 750000, 0, 0, 0, 0, 0, 2, 0, -7.4, -1]]];
        $db->exec('PRAGMA user_version = 0');
        $this->makeSeeder($db, $new)->migrate();

        $this->assertSame(750000, (int) $db->query("SELECT cost FROM trainings WHERE name = 'Fitness'")
            ->fetchColumn(), 'a repriced session must overwrite the stale cost on an existing DB');
    }

    /**
     * The reprice must not clobber the attribute gains, which are the
     * spreadsheet-verified half of the row.
     */
    public function testSeedTrainingsPreservesGainsWhenRepricing(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $seed = ['trainings_seed' => [['Yoga', 700000, 5, 0, -2, 0, 0, -2, 0, 7.2, 0]]];
        $this->makeSeeder($db, $seed)->migrate();
        $db->exec('PRAGMA user_version = 0');
        $this->makeSeeder($db, $seed)->migrate();

        $row = $db->query("SELECT * FROM trainings WHERE name = 'Yoga'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(5.0, (float) $row['gain_concentration']);
        $this->assertSame(7.2, (float) $row['gain_motivation']);
        $this->assertSame(-2.0, (float) $row['gain_stamina']);
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
