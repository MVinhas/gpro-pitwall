<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Database\DatabaseSeeder;
use App\Repository\EconomyLedgerRepository;
use App\Security\ApiTokenCrypto;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EconomyLedgerRepository::class)]
final class EconomyLedgerRepositoryTest extends TestCase
{
    private EconomyLedgerRepository $repo;

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

        $this->repo = new EconomyLedgerRepository($db);
    }

    private function save(int $user, int $season, int $race, string $at, string $fp, int $amount = 10): void
    {
        $this->repo->save([
            'user_id' => $user, 'season' => $season, 'race' => $race, 'source' => 'economy',
            'occurred_at' => $at, 'description' => $fp, 'amount' => $amount,
            'balance_after' => null, 'fingerprint' => $fp,
        ]);
    }

    public function testForRaceIsOrderedByOccurredAtThenIdAndScoped(): void
    {
        $this->save(1, 107, 5, '2026-01-02 10:00:00', 'late');
        $this->save(1, 107, 5, '2026-01-01 10:00:00', 'tie-a');
        $this->save(1, 107, 5, '2026-01-01 10:00:00', 'tie-b');
        $this->save(1, 107, 6, '2026-01-01 09:00:00', 'other-race');
        $this->save(1, 108, 5, '2026-01-01 09:00:00', 'other-season');
        $this->save(2, 107, 5, '2026-01-01 09:00:00', 'other-user');

        $rows = $this->repo->forRace(1, 107, 5);

        $this->assertSame(
            ['tie-a', 'tie-b', 'late'],
            array_map(static fn (array $r): string => (string) $r['fingerprint'], $rows)
        );
        $this->assertSame([], $this->repo->forRace(1, 99, 1));
    }

    public function testForSeasonIsOrderedByRaceThenOccurredAtThenId(): void
    {
        $this->save(1, 107, 6, '2026-01-01 09:00:00', 'r6');
        $this->save(1, 107, 5, '2026-01-03 10:00:00', 'r5-late');
        $this->save(1, 107, 5, '2026-01-02 10:00:00', 'r5-a');
        $this->save(1, 107, 5, '2026-01-02 10:00:00', 'r5-b');
        $this->save(1, 108, 1, '2026-01-01 09:00:00', 'other-season');
        $this->save(2, 107, 5, '2026-01-01 09:00:00', 'other-user');

        $rows = $this->repo->forSeason(1, 107);

        $this->assertSame(
            ['r5-a', 'r5-b', 'r5-late', 'r6'],
            array_map(static fn (array $r): string => (string) $r['fingerprint'], $rows)
        );
    }
}
