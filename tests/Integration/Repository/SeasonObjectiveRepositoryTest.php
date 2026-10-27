<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Database\DatabaseSeeder;
use App\Repository\SeasonObjectiveRepository;
use App\Security\ApiTokenCrypto;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SeasonObjectiveRepository::class)]
final class SeasonObjectiveRepositoryTest extends TestCase
{
    private PDO $db;
    private DatabaseSeeder $seeder;
    private SeasonObjectiveRepository $repo;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->seeder = new DatabaseSeeder(
            $this->db,
            ['Concentration' => 'concentration'],
            ['Rookie', 'Amateur', 'Pro', 'Master', 'Elite'],
            [],
            new ApiTokenCrypto('read-test-secret'),
        );
        $this->seeder->migrate();

        $this->repo = new SeasonObjectiveRepository($this->db);
    }

    public function testSaveThenFindReturnsTheObjective(): void
    {
        $this->repo->save(1, 112, 'promote');

        $this->assertSame('promote', $this->repo->find(1, 112));
    }

    public function testSaveTwiceKeepsTheLatest(): void
    {
        $this->repo->save(1, 112, 'promote');
        $this->repo->save(1, 112, 'survive');

        $this->assertSame('survive', $this->repo->find(1, 112));
        $this->assertSame(
            1,
            (int) $this->db->query('SELECT COUNT(*) FROM user_season_objective')->fetchColumn(),
        );
    }

    public function testClearRemovesOnlyThatSeason(): void
    {
        $this->repo->save(1, 112, 'promote');
        $this->repo->save(1, 113, 'hold');

        $this->repo->clear(1, 112);

        $this->assertNull($this->repo->find(1, 112));
        $this->assertSame('hold', $this->repo->find(1, 113));
    }

    public function testFindForAnotherUserIsNull(): void
    {
        $this->repo->save(2, 112, 'promote');

        $this->assertNull($this->repo->find(1, 112));
    }

    public function testAnUnknownObjectiveIsRejectedByTheTable(): void
    {
        $this->expectException(\PDOException::class);

        $this->repo->save(1, 112, 'auto');
    }

    public function testMigrationRunsTwiceOnAnExistingDatabase(): void
    {
        $this->db->exec(
            "INSERT INTO users (username, email_hash, email_encrypted, verified_at)
             VALUES ('u', 'h', 'e', datetime('now'))"
        );
        $this->db->exec('DROP TABLE user_season_objective');
        $this->db->exec('PRAGMA user_version = 17');

        $this->seeder->migrate();
        $this->seeder->migrate();

        $this->assertSame(
            1,
            (int) $this->db->query("SELECT COUNT(*) FROM users WHERE username = 'u'")->fetchColumn(),
        );
        $this->assertSame(
            1,
            (int) $this->db->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'user_season_objective'"
            )->fetchColumn(),
        );
        $this->assertSame(19, (int) $this->db->query('PRAGMA user_version')->fetchColumn());
    }
}
