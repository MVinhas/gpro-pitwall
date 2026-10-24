<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Database\DatabaseSeeder;
use App\Security\ApiTokenCrypto;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseSeeder::class)]
final class UserApiArchiveMigrationTest extends TestCase
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

    public function testMigratesExistingV14DatabaseIdempotently(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $seeder = $this->makeSeeder($db);
        $seeder->migrate();

        $db->exec("INSERT INTO users (username, email_hash, email_encrypted) VALUES ('u', 'h', 'e')");
        $userId = (int) $db->lastInsertId();
        $db->exec("INSERT INTO user_race_history (user_id, season, race) VALUES ($userId, 100, 1)");

        $db->exec('DROP TABLE IF EXISTS user_api_archive');
        $db->exec('PRAGMA user_version = 14');
        $seeder->migrate();
        $seeder->migrate();

        $this->assertSame(
            1,
            (int) $db->query('SELECT COUNT(*) FROM user_race_history')->fetchColumn(),
        );
        $this->assertSame(15, (int) $db->query('PRAGMA user_version')->fetchColumn());

        $insert = $db->prepare(
            'INSERT INTO user_api_archive (user_id, endpoint, params, race_window, fetched_at, payload_gz)
             VALUES (:u, :e, :p, :w, :f, :b)'
        );
        $row = ['u' => $userId, 'e' => 'office', 'p' => '', 'w' => 'w1', 'f' => '2026-10-23 10:00:00', 'b' => 'x'];
        $insert->execute($row);
        $this->expectException(\PDOException::class);
        $insert->execute($row);
    }

    public function testArchiveRowsCascadeWithUser(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA foreign_keys = ON');
        $this->makeSeeder($db)->migrate();

        $db->exec("INSERT INTO users (username, email_hash, email_encrypted) VALUES ('u', 'h', 'e')");
        $userId = (int) $db->lastInsertId();
        $db->exec(
            "INSERT INTO user_api_archive (user_id, endpoint, fetched_at, payload_gz)
             VALUES ($userId, 'office', '2026-10-23 10:00:00', x'00')"
        );
        $db->exec("DELETE FROM users WHERE id = $userId");

        $this->assertSame(
            0,
            (int) $db->query('SELECT COUNT(*) FROM user_api_archive')->fetchColumn(),
        );
    }

    public function testNaturalKeyIncludesTokenScope(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->makeSeeder($db)->migrate();
        $db->exec("INSERT INTO users (username, email_hash, email_encrypted) VALUES ('u', 'h', 'e')");
        $userId = (int) $db->lastInsertId();

        $insert = $db->prepare(
            "INSERT INTO user_api_archive (user_id, token_scope, endpoint, params, race_window, fetched_at, payload_gz)
             VALUES (:u, :s, 'office', '', 'w1', '2026-10-23 10:00:00', x'00')"
        );
        $insert->execute(['u' => $userId, 's' => 'scope-a']);
        $insert->execute(['u' => $userId, 's' => 'scope-b']);

        $this->assertSame(2, (int) $db->query('SELECT COUNT(*) FROM user_api_archive')->fetchColumn());
        $this->expectException(\PDOException::class);
        $insert->execute(['u' => $userId, 's' => 'scope-a']);
    }
}
