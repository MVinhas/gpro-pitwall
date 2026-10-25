<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Database\DatabaseSeeder;
use App\Repository\StandingsRepository;
use App\Security\ApiTokenCrypto;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StandingsRepository::class)]
final class StandingsRepositoryTest extends TestCase
{
    private StandingsRepository $repo;

    protected function setUp(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        (new DatabaseSeeder(
            $db,
            ['Concentration' => 'concentration'],
            ['Rookie', 'Amateur', 'Pro', 'Master', 'Elite'],
            [],
            new ApiTokenCrypto('read-test-secret'),
        ))->migrate();

        $this->repo = new StandingsRepository($db);
    }

    private function save(int $user, int $season, int $race, ?int $pos = null): void
    {
        $this->repo->save([
            'user_id' => $user, 'season' => $season, 'race' => $race,
            'own_pos' => $pos, 'own_pts' => null, 'group_label' => null, 'managers_json' => null,
        ]);
    }

    public function testFindReturnsTheExactRowOrNull(): void
    {
        $this->save(1, 107, 5, 3);

        $row = $this->repo->find(1, 107, 5);

        $this->assertNotNull($row);
        $this->assertSame(3, (int) $row['own_pos']);
        $this->assertNull($this->repo->find(1, 107, 6));
        $this->assertNull($this->repo->find(1, 108, 5));
    }

    public function testFindNeverReturnsAnotherUsersRow(): void
    {
        $this->save(2, 107, 5, 1);

        $this->assertNull($this->repo->find(1, 107, 5));
    }

    public function testForSeasonIsOrderedByRaceAndScopedToUserAndSeason(): void
    {
        $this->save(1, 107, 9);
        $this->save(1, 107, 2);
        $this->save(1, 107, 5);
        $this->save(1, 108, 1);
        $this->save(2, 107, 3);

        $rows = $this->repo->forSeason(1, 107);

        $this->assertSame([2, 5, 9], array_map(static fn (array $r): int => (int) $r['race'], $rows));
        $this->assertSame([], $this->repo->forSeason(1, 99));
    }
}
