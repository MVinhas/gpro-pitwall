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

    /**
     * Same class of bug as the training reprice: GPRO re-tunes tyre supplier
     * durability between seasons, and INSERT OR IGNORE would pin an existing
     * database to whatever value it first saw. These drive the wear exponent,
     * so a stale value silently skews every strategy the fallback touches.
     */
    public function testSeedGameConstantsRefreshesChangedValues(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $old = ['tyre_suppliers_durabilities' => ['Yokomama' => 2]];
        $this->makeSeeder($db, $old)->migrate();

        $read = static fn(PDO $d): int => (int) $d->query(
            "SELECT value FROM game_constants WHERE category = 'tyre_brand' AND name = 'Yokomama'"
        )->fetchColumn();

        $this->assertSame(2, $read($db));

        $new = ['tyre_suppliers_durabilities' => ['Yokomama' => 7]];
        $db->exec('PRAGMA user_version = 0');
        $this->makeSeeder($db, $new)->migrate();

        $this->assertSame(7, $read($db), 're-tuned durability must overwrite the stale constant');
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

    /**
     * A pre-1.x users table: no username, no api_token, no soft-delete column,
     * and carrying the dropped is_premium flag plus the old boolean
     * is_verified. Prod migrates a table exactly like this on first request,
     * so every ALTER below runs against live rows, not a clean seed.
     */
    private function legacyDb(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec(
            "CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT,
                is_premium INTEGER NOT NULL DEFAULT 0,
                is_verified INTEGER NOT NULL DEFAULT 0
            )"
        );

        return $db;
    }

    /** @return list<string> */
    private function columnsOf(PDO $db, string $table): array
    {
        $stmt = $db->query("PRAGMA table_info({$table})");
        $this->assertNotFalse($stmt);

        /** @var list<array<string, mixed>> $cols */
        $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(strval(...), array_column($cols, 'name'));
    }

    public function testMigratingALegacyUsersTableAddsEveryMissingColumn(): void
    {
        $db = $this->legacyDb();

        $this->makeSeeder($db)->migrate();

        $columns = $this->columnsOf($db, 'users');
        foreach (
            ['username', 'api_token', 'verified_at', 'is_admin', 'email_encrypted',
             'email_hash', 'last_synced_at', 'sync_status', 'deleted_at'] as $expected
        ) {
            $this->assertContains($expected, $columns, "missing migrated column {$expected}");
        }
    }

    /** Premium was removed 2026-06-01; the column must not survive a migrate. */
    public function testMigratingDropsTheRemovedPremiumColumn(): void
    {
        $db = $this->legacyDb();

        $this->makeSeeder($db)->migrate();

        $this->assertNotContains('is_premium', $this->columnsOf($db, 'users'));
    }

    public function testTheOldBooleanVerifiedFlagIsCarriedIntoTheTimestamp(): void
    {
        $db = $this->legacyDb();
        $db->exec("INSERT INTO users (email, is_verified) VALUES ('kept@example.test', 1)");

        $this->makeSeeder($db)->migrate();

        $verifiedAt = $db->query("SELECT verified_at FROM users WHERE email = 'kept@example.test'")
            ->fetchColumn();
        $this->assertIsString($verifiedAt);
        $this->assertNotSame('', $verifiedAt);
    }

    /**
     * Unverified rows are ghosts of abandoned signups; leaving them would let
     * one squat a username the UNIQUE index is about to start enforcing.
     */
    public function testUnverifiedLegacyUsersArePurgedWhileVerifiedOnesSurvive(): void
    {
        $db = $this->legacyDb();
        $db->exec("INSERT INTO users (email, is_verified) VALUES ('kept@example.test', 1)");
        $db->exec("INSERT INTO users (email, is_verified) VALUES ('ghost@example.test', 0)");

        $this->makeSeeder($db)->migrate();

        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $this->assertSame(
            'kept@example.test',
            $db->query('SELECT email FROM users')->fetchColumn()
        );
    }

    public function testLegacyUsersWithoutAUsernameGetADerivedOne(): void
    {
        $db = $this->legacyDb();
        $db->exec("INSERT INTO users (email, is_verified) VALUES ('kept@example.test', 1)");

        $this->makeSeeder($db)->migrate();

        $username = $db->query('SELECT username FROM users')->fetchColumn();
        $this->assertIsString($username);
        $this->assertStringStartsWith('User_', $username);
    }

    /**
     * The unique indexes are what make the registration race safe. A migrated
     * table gets its columns by ALTER, which carries no constraint — so the
     * seeder has to create them separately.
     */
    public function testMigrationHardensTheUniqueIndexesOnAMigratedTable(): void
    {
        $db = $this->legacyDb();

        $this->makeSeeder($db)->migrate();

        $db->exec("INSERT INTO users (username, email_hash) VALUES ('dup', 'hash-a')");

        $this->expectException(\PDOException::class);
        $db->exec("INSERT INTO users (username, email_hash) VALUES ('dup', 'hash-b')");
    }

    public function testMigratingIsIdempotentAcrossAFreshSeederInstance(): void
    {
        $db = $this->legacyDb();
        $db->exec("INSERT INTO users (email, is_verified) VALUES ('kept@example.test', 1)");

        $this->makeSeeder($db)->migrate();
        $columnsAfterFirst = $this->columnsOf($db, 'users');

        $db->exec('PRAGMA user_version = 0');
        $this->makeSeeder($db)->migrate();

        $this->assertSame($columnsAfterFirst, $this->columnsOf($db, 'users'));
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    /**
     * A plaintext api_token left by a pre-encryption release must be encrypted
     * in place on migrate, and must still decrypt to the original value.
     */
    public function testLegacyPlaintextApiTokensAreEncryptedInPlace(): void
    {
        $db = $this->legacyDb();
        $db->exec("INSERT INTO users (email, is_verified) VALUES ('kept@example.test', 1)");
        $this->makeSeeder($db)->migrate();

        $db->exec("UPDATE users SET api_token = 'plaintext-token'");
        $db->exec('PRAGMA user_version = 0');

        $this->makeSeeder($db)->migrate();

        $stored = $db->query('SELECT api_token FROM users')->fetchColumn();
        $this->assertIsString($stored);
        $this->assertNotSame('plaintext-token', $stored);
        $this->assertSame(
            'plaintext-token',
            (new ApiTokenCrypto('seeder-test-secret'))->decrypt($stored)
        );
    }

    public function testAlreadyEncryptedApiTokensAreNotDoubleEncrypted(): void
    {
        $db = $this->legacyDb();
        $db->exec("INSERT INTO users (email, is_verified) VALUES ('kept@example.test', 1)");
        $this->makeSeeder($db)->migrate();

        $crypto = new ApiTokenCrypto('seeder-test-secret');
        $stmt = $db->prepare('UPDATE users SET api_token = :t');
        $stmt->execute(['t' => $crypto->encrypt('plaintext-token')]);

        $db->exec('PRAGMA user_version = 0');
        $this->makeSeeder($db)->migrate();

        $stored = $db->query('SELECT api_token FROM users')->fetchColumn();
        $this->assertIsString($stored);
        $this->assertSame('plaintext-token', $crypto->decrypt($stored));
    }

    public function testAnEmptyApiTokenIsLeftAloneRatherThanEncrypted(): void
    {
        $db = $this->legacyDb();
        $db->exec("INSERT INTO users (email, is_verified) VALUES ('kept@example.test', 1)");
        $this->makeSeeder($db)->migrate();

        $db->exec("UPDATE users SET api_token = ''");
        $db->exec('PRAGMA user_version = 0');
        $this->makeSeeder($db)->migrate();

        $this->assertSame('', $db->query('SELECT api_token FROM users')->fetchColumn());
    }

    /**
     * A tracks table from before the boost/overtaking columns existed. Built by
     * migrating a full modern table and dropping the four late additions, so the
     * fixture stays valid for the CSV reseed that runs in the same migrate().
     */
    public function testTracksGainTheirMigratedColumnsOnAnOlderSchema(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->makeSeeder($db)->migrate();

        $late = ['boost_dry', 'boost_wet', 'overtaking', 'grip'];
        foreach ($late as $column) {
            $db->exec("ALTER TABLE tracks DROP COLUMN {$column}");
        }
        $this->assertSame([], array_intersect($late, $this->columnsOf($db, 'tracks')));

        $db->exec('PRAGMA user_version = 0');
        $this->makeSeeder($db)->migrate();

        $columns = $this->columnsOf($db, 'tracks');
        foreach ($late as $expected) {
            $this->assertContains($expected, $columns, "missing migrated track column {$expected}");
        }
    }

    public function testDeprecatedTablesAreDroppedOnMigrate(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE track_risks (id INTEGER PRIMARY KEY AUTOINCREMENT)');

        $this->makeSeeder($db)->migrate();

        $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='track_risks'");
        $this->assertNotFalse($stmt);
        $this->assertFalse($stmt->fetchColumn());
    }
}
