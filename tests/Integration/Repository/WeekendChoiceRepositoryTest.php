<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Database\DatabaseSeeder;
use App\Repository\WeekendChoiceRepository;
use App\Security\ApiTokenCrypto;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WeekendChoiceRepository::class)]
final class WeekendChoiceRepositoryTest extends TestCase
{
    private PDO $db;
    private DatabaseSeeder $seeder;
    private WeekendChoiceRepository $repo;

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

        $this->repo = new WeekendChoiceRepository($this->db);
    }

    public function testSaveThenChoicesReturnsBothItems(): void
    {
        $this->repo->save(7, 108, 9, 'testing', 'skip');
        $this->repo->save(7, 108, 9, 'training', 'do');

        $this->assertEqualsCanonicalizing(
            ['testing' => 'skip', 'training' => 'do'],
            $this->repo->choices(7, 108, 9),
        );
    }

    public function testSaveTwiceKeepsTheLatest(): void
    {
        $this->repo->save(7, 108, 9, 'testing', 'do');
        $this->repo->save(7, 108, 9, 'testing', 'skip');

        $this->assertSame(['testing' => 'skip'], $this->repo->choices(7, 108, 9));
    }

    public function testChoicesAreKeyedByRace(): void
    {
        $this->repo->save(7, 108, 9, 'testing', 'do');

        $this->assertSame([], $this->repo->choices(7, 108, 10));
        $this->assertSame([], $this->repo->choices(7, 107, 9));
        $this->assertSame([], $this->repo->choices(8, 108, 9));
    }

    public function testClearRemovesOnlyThatItem(): void
    {
        $this->repo->save(7, 108, 9, 'testing', 'do');
        $this->repo->save(7, 108, 9, 'training', 'skip');

        $this->repo->clear(7, 108, 9, 'testing');

        $this->assertSame(['training' => 'skip'], $this->repo->choices(7, 108, 9));
    }

    public function testAnUnknownItemOrChoiceIsRejectedByTheTable(): void
    {
        $this->expectException(\PDOException::class);

        $this->repo->save(7, 108, 9, 'sponsor', 'do');
    }

    public function testSeedingTwiceIsIdempotent(): void
    {
        $this->repo->save(7, 108, 9, 'testing', 'do');
        $this->db->exec('PRAGMA user_version = 20');

        $this->seeder->migrate();
        $this->seeder->migrate();

        $this->assertSame(['testing' => 'do'], $this->repo->choices(7, 108, 9));
        $this->assertSame(22, (int) $this->db->query('PRAGMA user_version')->fetchColumn());
        $indexes = $this->db->query("PRAGMA index_list('user_weekend_choice')")->fetchAll(PDO::FETCH_COLUMN, 1);
        $this->assertContains('idx_user_weekend_choice', $indexes);
    }

    public function testMigrationCreatesTheTableOnAVersion20Database(): void
    {
        $this->db->exec('DROP TABLE user_weekend_choice');
        $this->db->exec('PRAGMA user_version = 20');

        $this->seeder->migrate();

        $tables = $this->db->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('user_weekend_choice', $tables);
        $indexes = $this->db->query("PRAGMA index_list('user_weekend_choice')")->fetchAll(PDO::FETCH_COLUMN, 1);
        $this->assertContains('idx_user_weekend_choice', $indexes);
        $this->assertSame(22, (int) $this->db->query('PRAGMA user_version')->fetchColumn());
        $this->repo->save(7, 108, 9, 'testing', 'do');
        $this->assertSame(['testing' => 'do'], $this->repo->choices(7, 108, 9));
    }
}
