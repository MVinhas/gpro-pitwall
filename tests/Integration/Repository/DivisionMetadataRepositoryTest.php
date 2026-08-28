<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Repository\DivisionMetadataRepository;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DivisionMetadataRepository::class)]
final class DivisionMetadataRepositoryTest extends TestCase
{
    private PDO $db;
    private DivisionMetadataRepository $repo;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("
            CREATE TABLE division_metadata (
                division TEXT PRIMARY KEY,
                last_retrieved_season INTEGER NOT NULL DEFAULT 0,
                last_retrieved_race INTEGER NOT NULL DEFAULT 1
            )
        ");

        $this->repo = new DivisionMetadataRepository($this->db);
    }

    public function testUnknownDivisionFallsBackToSeasonZeroRaceOne(): void
    {
        $this->assertSame(
            ['season' => 0, 'race' => 1],
            $this->repo->getMetadata('Elite')
        );
    }

    public function testUpdateSeasonThenReadBack(): void
    {
        $this->repo->updateSeason('Elite', 84);

        $this->assertSame(
            ['season' => 84, 'race' => 1],
            $this->repo->getMetadata('Elite')
        );
    }

    public function testUpdateSeasonIsIdempotentAndOverwritesInPlace(): void
    {
        $this->repo->updateSeason('Rookie', 80);
        $this->repo->updateSeason('Rookie', 81);
        $this->repo->updateSeason('Rookie', 82);

        $this->assertSame(82, $this->repo->getMetadata('Rookie')['season']);
        $this->assertSame(
            1,
            (int) $this->db->query("SELECT COUNT(*) FROM division_metadata WHERE division = 'Rookie'")->fetchColumn()
        );
    }

    public function testUpdateSeasonResetsTheRaceCounterToOne(): void
    {
        $this->db->exec(
            "INSERT INTO division_metadata (division, last_retrieved_season, last_retrieved_race)
             VALUES ('Pro', 80, 12)"
        );

        $this->repo->updateSeason('Pro', 81);

        $this->assertSame(1, $this->repo->getMetadata('Pro')['race']);
    }

    public function testDivisionsAreIsolatedFromEachOther(): void
    {
        $this->repo->updateSeason('Elite', 84);
        $this->repo->updateSeason('Rookie', 12);

        $this->assertSame(84, $this->repo->getMetadata('Elite')['season']);
        $this->assertSame(12, $this->repo->getMetadata('Rookie')['season']);
    }

    public function testUpdateSeasonReportsSuccess(): void
    {
        $this->assertTrue($this->repo->updateSeason('Amateur', 5));
    }
}
