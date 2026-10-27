<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Database\DatabaseSeeder;
use App\Repository\SeasonBudgetRepository;
use App\Security\ApiTokenCrypto;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SeasonBudgetRepository::class)]
final class SeasonBudgetRepositoryTest extends TestCase
{
    private PDO $db;
    private DatabaseSeeder $seeder;
    private SeasonBudgetRepository $repo;

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

        $this->repo = new SeasonBudgetRepository($this->db);
    }

    public function testSaveThenFindRoundTrips(): void
    {
        $plan = ['v' => 1, 'ctr' => 40, 'rows' => [['race' => 8, 'cash' => 1200000]]];

        $this->repo->save(1, 112, 8, $plan);

        $found = $this->repo->find(1, 112);
        $this->assertNotNull($found);
        $this->assertSame(8, $found['race']);
        $this->assertSame($plan, $found['plan']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $found['computed_at']);
    }

    public function testSaveOverwritesTheSeasonRow(): void
    {
        $this->repo->save(1, 112, 8, ['v' => 1, 'ctr' => 40]);
        $this->repo->save(1, 112, 9, ['v' => 1, 'ctr' => 55]);

        $found = $this->repo->find(1, 112);
        $this->assertNotNull($found);
        $this->assertSame(9, $found['race']);
        $this->assertSame(['v' => 1, 'ctr' => 55], $found['plan']);
        $this->assertSame(
            1,
            (int) $this->db->query('SELECT COUNT(*) FROM user_season_budget')->fetchColumn(),
        );
    }

    public function testFindIgnoresAnotherVersion(): void
    {
        $this->repo->save(1, 112, 8, ['v' => 2, 'ctr' => 40]);

        $this->assertNull($this->repo->find(1, 112));
    }

    public function testFindIgnoresAPlanWithoutAVersion(): void
    {
        $this->repo->save(1, 112, 8, ['ctr' => 40]);

        $this->assertNull($this->repo->find(1, 112));
    }

    public function testFindIgnoresInvalidJson(): void
    {
        $this->db->exec(
            "INSERT INTO user_season_budget (user_id, season, race, plan_json) VALUES (1, 112, 8, '{not json')"
        );

        $this->assertNull($this->repo->find(1, 112));
    }

    public function testFindIgnoresAJsonValueThatIsNotAnArray(): void
    {
        $this->db->exec(
            "INSERT INTO user_season_budget (user_id, season, race, plan_json) VALUES (1, 112, 8, '42')"
        );

        $this->assertNull($this->repo->find(1, 112));
    }

    public function testFindIsPerSeason(): void
    {
        $this->repo->save(1, 112, 8, ['v' => 1, 'ctr' => 40]);

        $this->assertNull($this->repo->find(1, 113));
        $this->assertNotNull($this->repo->find(1, 112));
    }

    public function testFindIsPerUser(): void
    {
        $this->repo->save(2, 112, 8, ['v' => 1, 'ctr' => 40]);

        $this->assertNull($this->repo->find(1, 112));
    }

    public function testMigrationRunsTwiceAndKeepsAnExistingRow(): void
    {
        $this->db->exec(
            "INSERT INTO users (username, email_hash, email_encrypted, verified_at)
             VALUES ('u', 'h', 'e', datetime('now'))"
        );
        $this->repo->save(1, 112, 8, ['v' => 1, 'ctr' => 40]);
        $this->db->exec('PRAGMA user_version = 18');

        $this->seeder->migrate();
        $this->seeder->migrate();

        $found = $this->repo->find(1, 112);
        $this->assertNotNull($found);
        $this->assertSame(8, $found['race']);
        $this->assertSame(19, (int) $this->db->query('PRAGMA user_version')->fetchColumn());
    }

    public function testMigrationCreatesTheTableOnAVersion18Database(): void
    {
        $this->db->exec('DROP TABLE user_season_budget');
        $this->db->exec('PRAGMA user_version = 18');

        $this->seeder->migrate();

        $this->repo->save(1, 112, 8, ['v' => 1]);
        $this->assertNotNull($this->repo->find(1, 112));
    }
}
