<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\CarWearService;
use App\Service\TrainingWearProjectionService;
use App\Service\WearAdvisorService;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TrainingWearProjectionService::class)]
final class TrainingWearProjectionServiceTest extends TestCase
{
    /** @var array<string, mixed> */
    private const array SECRETS = [
        'driver_wear_factors' => [
            'concentration' => 1.0,
            'talent'        => 1.0,
            'experience'    => 1.0,
        ],
        'part_level_factors' => [1 => 1.0],
    ];

    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            "CREATE TABLE tracks (id INTEGER PRIMARY KEY, name TEXT,
             laps INTEGER, wear_chassis REAL, wear_engine REAL, wear_fwing REAL,
             wear_rwing REAL, wear_underbody REAL, wear_sidepod REAL,
             wear_cooling REAL, wear_gearbox REAL, wear_brakes REAL,
             wear_suspension REAL, wear_electronics REAL)"
        );
        // A 100-lap track where every part's full-race wear base is 10%.
        $this->db->exec(
            "INSERT INTO tracks (id, name, laps, wear_chassis, wear_engine, wear_fwing,
             wear_rwing, wear_underbody, wear_sidepod, wear_cooling, wear_gearbox,
             wear_brakes, wear_suspension, wear_electronics)
             VALUES (1, 'Testville', 100, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10)"
        );
    }

    private function service(): TrainingWearProjectionService
    {
        return new TrainingWearProjectionService(
            new CarWearService($this->db, self::SECRETS),
            new WearAdvisorService(),
        );
    }

    /** @return array<string, mixed> */
    private function car(int $startWear = 0): array
    {
        $car = [];
        foreach (CarWearService::PARTS_MAP as $map) {
            $car[$map['wear']] = $startWear;
            $car[$map['lvl']] = 1;
        }

        return $car;
    }

    /** @return array<string, mixed> */
    private function track(): array
    {
        return ['id' => 1, 'name' => 'Testville', 'laps' => 100];
    }

    public function testZeroTrainingLapsMatchesAPlainRaceProjection(): void
    {
        $plain = (new CarWearService($this->db, self::SECRETS))
            ->calculateWear($this->track(), $this->car(20), [], 0);

        $projected = $this->service()->project($this->track(), $this->car(20), [], 0, 0);

        $this->assertSame(
            $plain['parts']['Engine']['end'],
            $projected['parts']['Engine']['end']
        );
    }

    public function testTrainingLapsRaiseTheProjectedEndWear(): void
    {
        $without = $this->service()->project($this->track(), $this->car(20), [], 0, 0);
        $with = $this->service()->project($this->track(), $this->car(20), [], 0, 100);

        $this->assertGreaterThan(
            $without['parts']['Engine']['end'],
            $with['parts']['Engine']['end']
        );
    }

    /**
     * Training wear is charged before the race, so it must land in its own
     * column — a manager needs to see how much of the budget training ate.
     */
    public function testTrainingWearIsReportedSeparatelyFromRaceWear(): void
    {
        $result = $this->service()->project($this->track(), $this->car(20), [], 0, 100);
        $engine = $result['parts']['Engine'];

        $this->assertSame(20, $engine['start']);
        $this->assertGreaterThan(0.0, $engine['training']);
        $this->assertGreaterThan(0.0, $engine['race']);
        $this->assertEqualsWithDelta(
            $engine['start'] + $engine['training'] + $engine['race'],
            $engine['end'],
            0.11
        );
    }

    /** 100 laps on a 100-lap track ≈ half a race distance's wear (factor 0.53). */
    public function testTrainingWearTracksTheTestingWearFactor(): void
    {
        $result = $this->service()->project($this->track(), $this->car(), [], 0, 100);

        $this->assertEqualsWithDelta(5.3, $result['parts']['Engine']['training'], 0.1);
    }

    public function testTrainingWearScalesLinearlyWithLapCount(): void
    {
        $fifty = $this->service()->project($this->track(), $this->car(), [], 0, 50);
        $hundred = $this->service()->project($this->track(), $this->car(), [], 0, 100);

        $this->assertEqualsWithDelta(
            $fifty['parts']['Engine']['training'] * 2,
            $hundred['parts']['Engine']['training'],
            0.2
        );
    }

    public function testTheLapCountIsCarriedBackForDisplay(): void
    {
        $result = $this->service()->project($this->track(), $this->car(), [], 0, 75);

        $this->assertSame(75, $result['training_laps']);
        $this->assertSame('Testville', $result['track_name']);
    }

    public function testANegativeLapCountIsFlooredAtZero(): void
    {
        $result = $this->service()->project($this->track(), $this->car(), [], 0, -20);

        $this->assertSame(0, $result['training_laps']);
        $this->assertSame(0.0, $result['parts']['Engine']['training']);
    }

    public function testTheLapCountIsCappedAtTheSessionMaximum(): void
    {
        $atCap = $this->service()->project($this->track(), $this->car(), [], 0, 100);
        $overCap = $this->service()->project($this->track(), $this->car(), [], 0, 500);

        $this->assertSame(100, $overCap['training_laps']);
        $this->assertSame($atCap['parts']['Engine']['end'], $overCap['parts']['Engine']['end']);
    }

    /** The headline is the answer: which parts end up over the limit. */
    public function testAComfortableCarIsReportedAsWithinLimits(): void
    {
        $result = $this->service()->project($this->track(), $this->car(0), [], 0, 100);

        $this->assertTrue($result['within_limits']);
        $this->assertSame([], $result['advisor']['swap']);
    }

    public function testACarPushedOverTheLimitByTrainingIsFlagged(): void
    {
        $result = $this->service()->project($this->track(), $this->car(95), [], 0, 100);

        $this->assertFalse($result['within_limits']);
        $this->assertNotSame([], $result['advisor']['swap']);
    }

    /**
     * The whole point of the feature: training can be what tips a part over.
     * Racing alone must stay inside limits while training-then-racing does not.
     */
    public function testTrainingAloneCanBeWhatBreachesTheLimit(): void
    {
        // 75 + 10 race = 85, under the 90 "risky" line. Add 100 training
        // laps (+5.3) and the same race finishes at 90.3 — over it.
        $withoutTraining = $this->service()->project($this->track(), $this->car(75), [], 0, 0);
        $withTraining = $this->service()->project($this->track(), $this->car(75), [], 0, 100);

        $this->assertTrue($withoutTraining['within_limits']);
        $this->assertFalse($withTraining['within_limits']);
    }

    public function testEveryPartIsProjected(): void
    {
        $result = $this->service()->project($this->track(), $this->car(), [], 0, 100);

        $this->assertCount(count(CarWearService::PARTS_MAP), $result['parts']);
        foreach (array_keys(CarWearService::PARTS_MAP) as $label) {
            $this->assertArrayHasKey($label, $result['parts']);
        }
    }

    public function testAnUnknownTrackIsReportedAsAnErrorRatherThanGuessed(): void
    {
        $result = $this->service()->project(
            ['id' => 999, 'name' => 'Nowhere'],
            $this->car(),
            [],
            0,
            100
        );

        $this->assertArrayHasKey('error', $result);
    }

    public function testRiskStillRaisesRaceWearOnTopOfTraining(): void
    {
        $secrets = self::SECRETS;
        $secrets['part_level_factors'] = [1 => 1.02];
        $service = new TrainingWearProjectionService(
            new CarWearService($this->db, $secrets),
            new WearAdvisorService(),
        );

        $noRisk = $service->project($this->track(), $this->car(), [], 0, 100);
        $highRisk = $service->project($this->track(), $this->car(), [], 50, 100);

        $this->assertGreaterThan(
            $noRisk['parts']['Engine']['race'],
            $highRisk['parts']['Engine']['race']
        );
        $this->assertEqualsWithDelta(
            $noRisk['parts']['Engine']['training'],
            $highRisk['parts']['Engine']['training'],
            0.001
        );
    }
}
