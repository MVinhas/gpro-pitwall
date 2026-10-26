<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Database\DatabaseSeeder;
use App\Repository\WeekendStateRepository;
use App\Security\ApiTokenCrypto;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WeekendStateRepository::class)]
final class WeekendStateRepositoryTest extends TestCase
{
    private WeekendStateRepository $repo;

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

        $this->repo = new WeekendStateRepository($db);
    }

    private function save(int $user, int $season, int $race, ?int $cash = null): void
    {
        $this->repo->save([
            'user_id' => $user, 'season' => $season, 'race' => $race, 'source' => 'history',
            'driver_json' => null, 'td_json' => null, 'staff_json' => null,
            'facilities_json' => null, 'car_json' => null,
            'cash' => $cash, 'pts' => null, 'pos' => null,
        ]);
    }

    public function testFindReturnsTheExactRowOrNull(): void
    {
        $this->save(1, 107, 5, 1000);

        $row = $this->repo->find(1, 107, 5);

        $this->assertNotNull($row);
        $this->assertSame(1000, (int) $row['cash']);
        $this->assertNull($this->repo->find(1, 107, 6));
    }

    public function testFindNeverReturnsAnotherUsersRow(): void
    {
        $this->save(2, 107, 5);

        $this->assertNull($this->repo->find(1, 107, 5));
    }

    public function testPreviousIsTheLatestRowStrictlyBefore(): void
    {
        $this->save(1, 107, 3);
        $this->save(1, 107, 4);
        $this->save(1, 107, 5);
        $this->save(1, 107, 6);

        $row = $this->repo->previous(1, 107, 5);

        $this->assertNotNull($row);
        $this->assertSame(4, (int) $row['race']);
    }

    public function testPreviousCrossesTheSeasonBoundary(): void
    {
        $this->save(1, 107, 16);
        $this->save(1, 107, 17);
        $this->save(1, 108, 1);

        $row = $this->repo->previous(1, 108, 1);

        $this->assertNotNull($row);
        $this->assertSame(107, (int) $row['season']);
        $this->assertSame(17, (int) $row['race']);
    }

    public function testPreviousIsNullForTheFirstStoredRace(): void
    {
        $this->save(1, 107, 1);
        $this->save(1, 107, 2);

        $this->assertNull($this->repo->previous(1, 107, 1));
    }

    public function testPreviousIgnoresAnotherUser(): void
    {
        $this->save(2, 107, 4);
        $this->save(1, 107, 5);

        $this->assertNull($this->repo->previous(1, 107, 5));
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
