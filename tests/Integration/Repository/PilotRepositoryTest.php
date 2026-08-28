<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Repository\PilotRepository;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PilotRepository::class)]
final class PilotRepositoryTest extends TestCase
{
    private PDO $db;
    private PilotRepository $repo;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("
            CREATE TABLE pilots (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                division TEXT NOT NULL,
                concentration INTEGER NOT NULL,
                talent INTEGER NOT NULL,
                experience INTEGER NOT NULL
            )
        ");

        $this->repo = new PilotRepository($this->db);
    }

    /** @return array<string, mixed> */
    private function pilot(string $division, int $talent = 50): array
    {
        return [
            'division' => $division,
            'concentration' => 60,
            'talent' => $talent,
            'experience' => 40,
        ];
    }

    public function testAddPilotThenReadItBack(): void
    {
        $this->assertTrue($this->repo->addPilot($this->pilot('Elite', 77)));

        $rows = $this->repo->getPilotsByDivision('Elite');
        $this->assertCount(1, $rows);
        $this->assertSame(77, (int) $rows[0]['talent']);
        $this->assertSame('Elite', $rows[0]['division']);
    }

    public function testGetPilotsByDivisionIsEmptyForAnUnknownDivision(): void
    {
        $this->assertSame([], $this->repo->getPilotsByDivision('Nowhere'));
    }

    public function testGetPilotsByDivisionDoesNotLeakOtherDivisions(): void
    {
        $this->repo->addPilot($this->pilot('Elite'));
        $this->repo->addPilot($this->pilot('Elite'));
        $this->repo->addPilot($this->pilot('Rookie'));

        $this->assertCount(2, $this->repo->getPilotsByDivision('Elite'));
        $this->assertCount(1, $this->repo->getPilotsByDivision('Rookie'));
    }

    public function testDeleteLastPilotRemovesTheHighestIdInThatDivision(): void
    {
        $this->repo->addPilot($this->pilot('Elite', 10));
        $this->repo->addPilot($this->pilot('Elite', 20));
        $this->repo->addPilot($this->pilot('Elite', 30));

        $this->assertTrue($this->repo->deleteLastPilot('Elite'));

        $remaining = array_map('intval', array_column($this->repo->getPilotsByDivision('Elite'), 'talent'));
        $this->assertSame([10, 20], $remaining);
    }

    public function testDeleteLastPilotIsScopedToItsOwnDivision(): void
    {
        $this->repo->addPilot($this->pilot('Elite', 10));
        $this->repo->addPilot($this->pilot('Rookie', 99));

        $this->repo->deleteLastPilot('Elite');

        $this->assertCount(0, $this->repo->getPilotsByDivision('Elite'));
        $this->assertCount(1, $this->repo->getPilotsByDivision('Rookie'));
    }

    public function testDeleteLastPilotReportsFailureWhenTheDivisionIsEmpty(): void
    {
        $this->assertFalse($this->repo->deleteLastPilot('Elite'));
    }

    public function testClearDivisionRemovesEveryPilotOfThatDivisionOnly(): void
    {
        $this->repo->addPilot($this->pilot('Elite'));
        $this->repo->addPilot($this->pilot('Elite'));
        $this->repo->addPilot($this->pilot('Rookie'));

        $this->assertTrue($this->repo->clearDivision('Elite'));

        $this->assertSame([], $this->repo->getPilotsByDivision('Elite'));
        $this->assertCount(1, $this->repo->getPilotsByDivision('Rookie'));
    }

    public function testClearDivisionSucceedsWhenThereIsNothingToClear(): void
    {
        $this->assertTrue($this->repo->clearDivision('Elite'));
    }
}
