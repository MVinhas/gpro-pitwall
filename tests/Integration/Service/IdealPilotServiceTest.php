<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Repository\PilotRepository;
use App\Service\IdealPilotService;
use App\Service\PilotCalculatorService;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdealPilotService::class)]
final class IdealPilotServiceTest extends TestCase
{
    /** Display label => column name, mirroring the injected stats schema. */
    private const array SCHEMA = [
        'Concentration' => 'concentration',
        'Talent' => 'talent',
        'Age' => 'age',
        'Weight' => 'weight',
    ];

    /** @var array<string, float> */
    private const array FACTORS = ['concentration' => 0.5, 'talent' => 0.5];

    private PDO $db;
    private PilotRepository $repo;
    private IdealPilotService $service;

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
                age INTEGER NOT NULL,
                weight INTEGER NOT NULL
            )
        ");

        $this->repo = new PilotRepository($this->db);
        $this->service = new IdealPilotService(
            $this->repo,
            new PilotCalculatorService(self::FACTORS, []),
            self::SCHEMA
        );
    }

    private function addPilot(string $division, int $con, int $tal, int $age = 25, int $weight = 70): void
    {
        $this->repo->addPilot([
            'division' => $division,
            'concentration' => $con,
            'talent' => $tal,
            'age' => $age,
            'weight' => $weight,
        ]);
    }

    public function testAnEmptyDivisionYieldsNoStatsAndAZeroCount(): void
    {
        $this->assertSame(
            ['stats' => [], 'count' => 0],
            $this->service->getIdealPilot('Elite')
        );
    }

    public function testCountReflectsThePilotsInThatDivisionOnly(): void
    {
        $this->addPilot('Elite', 50, 50);
        $this->addPilot('Elite', 60, 60);
        $this->addPilot('Rookie', 10, 10);

        $this->assertSame(2, $this->service->getIdealPilot('Elite')['count']);
    }

    public function testStatsAreTheMeanAcrossTheDivision(): void
    {
        $this->addPilot('Elite', 40, 60);
        $this->addPilot('Elite', 60, 80);

        $stats = $this->service->getIdealPilot('Elite')['stats'];

        $this->assertSame(50.0, $stats['Concentration']);
        $this->assertSame(70.0, $stats['Talent']);
    }

    public function testStatsAreKeyedByDisplayLabelNotColumnName(): void
    {
        $this->addPilot('Elite', 50, 50);

        $stats = $this->service->getIdealPilot('Elite')['stats'];

        $this->assertArrayHasKey('Concentration', $stats);
        $this->assertArrayNotHasKey('concentration', $stats);
    }

    public function testOverallAbilityLeadsTheStatsBlock(): void
    {
        $this->addPilot('Elite', 50, 50);

        $stats = $this->service->getIdealPilot('Elite')['stats'];

        $this->assertSame('Overall Ability', array_key_first($stats));
    }

    public function testOverallAbilityIsTheMeanOfThePerPilotOverallsToOneDecimal(): void
    {
        $this->addPilot('Elite', 40, 60);
        $this->addPilot('Elite', 61, 80);

        // Overalls: (40+60)/2 = 50.0 and (61+80)/2 = 70.5 → mean 60.25 → 60.3.
        $this->assertSame(60.3, $this->service->getIdealPilot('Elite')['stats']['Overall Ability']);
    }

    public function testAveragedStatsAreRoundedToWholeNumbers(): void
    {
        $this->addPilot('Elite', 50, 50);
        $this->addPilot('Elite', 51, 51);
        $this->addPilot('Elite', 51, 51);

        $stats = $this->service->getIdealPilot('Elite')['stats'];

        $this->assertSame(51.0, $stats['Concentration']);
    }

    public function testAgeAndWeightAreRoundedLikeEveryOtherStat(): void
    {
        $this->addPilot('Elite', 50, 50, 24, 70);
        $this->addPilot('Elite', 50, 50, 27, 71);

        $stats = $this->service->getIdealPilot('Elite')['stats'];

        $this->assertSame(26.0, $stats['Age']);
        $this->assertSame(71.0, $stats['Weight']);
    }

    public function testASinglePilotIsItsOwnIdeal(): void
    {
        $this->addPilot('Elite', 77, 88, 30, 68);

        $result = $this->service->getIdealPilot('Elite');

        $this->assertSame(1, $result['count']);
        $this->assertSame(77.0, $result['stats']['Concentration']);
        $this->assertSame(88.0, $result['stats']['Talent']);
    }

    public function testOtherDivisionsDoNotSkewTheAverage(): void
    {
        $this->addPilot('Elite', 50, 50);
        $this->addPilot('Rookie', 1, 1);
        $this->addPilot('Rookie', 1, 1);

        $this->assertSame(50.0, $this->service->getIdealPilot('Elite')['stats']['Concentration']);
    }
}
