<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\CarWearService;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CarWearService::class)]
final class CarWearServiceTest extends TestCase
{
    /** @var array<string, mixed> */
    private const array SECRETS = [
        'driver_wear_factors' => [
            'concentration' => 0.99,
            'talent'        => 0.998,
            'experience'    => 0.997,
        ],
        'part_level_factors' => [
            1 => 1.05,
            5 => 1.025,
            7 => 1.0043,
            8 => 1.0021,
            9 => 1.0,
        ],
    ];

    private function service(?PDO $db = null): CarWearService
    {
        return new CarWearService($db ?? new PDO('sqlite::memory:'), self::SECRETS);
    }

    public function testDriverFactorIsExponentialProductOfThreeAttributes(): void
    {
        $factor = $this->service()->driverFactor([
            'concentration' => 100,
            'talent'        => 100,
            'experience'    => 100,
        ]);

        $expected = (0.99 ** 100) * (0.998 ** 100) * (0.997 ** 100);
        $this->assertEqualsWithDelta($expected, $factor, 1e-9);
    }

    public function testMissingDriverAttributesDefaultToZeroExponent(): void
    {
        // 0.99^0 == 1, so an empty driver gives factor 1.0 — no wear modifier.
        $this->assertSame(1.0, $this->service()->driverFactor([]));
    }

    public function testProjectEndWearComposesTrackBaseLevelDriverAndRisk(): void
    {
        // Level 9 has factor 1.0 — risk drops out, so the projection is just
        // trackBase * driverFactor added to start.
        $end = $this->service()->projectEndWear(
            trackBase: 5.0,
            level: 9,
            startWear: 20.0,
            driverFactor: 0.5,
            risk: 25,
        );

        $this->assertSame(22.5, $end);
    }

    public function testRiskAmplifiesWearViaLevelFactorExponent(): void
    {
        // Level 1 factor 1.05; risk 10 → 1.05^10 ≈ 1.6289.
        // est = 4.0 * 1.6289 * 1.0 = 6.5156 → rounded 6.5; end = 50 + 6.5 = 56.5.
        $end = $this->service()->projectEndWear(
            trackBase: 4.0,
            level: 1,
            startWear: 50.0,
            driverFactor: 1.0,
            risk: 10,
        );

        $this->assertSame(56.5, $end);
    }

    public function testUnknownLevelFallsBackToBuiltInDefaultFactor(): void
    {
        // 99 is not in the secrets table; service uses 1.019326794.
        $end = $this->service()->projectEndWear(
            trackBase: 2.0,
            level: 99,
            startWear: 0.0,
            driverFactor: 1.0,
            risk: 0,
        );

        // 1.019326794^0 = 1, so est = 2.0, end = 2.0.
        $this->assertSame(2.0, $end);
    }

    public function testProjectionRoundsEstimateToOneDecimal(): void
    {
        // Force a non-round product to confirm round(est, 1) is applied.
        $end = $this->service()->projectEndWear(
            trackBase: 3.333,
            level: 9,
            startWear: 0.0,
            driverFactor: 1.0,
            risk: 0,
        );

        $this->assertSame(3.3, $end);
    }

    public function testCalculateWearReturnsErrorWhenTrackUnknown(): void
    {
        // Empty in-memory DB → no track row → service returns ['error' => ...].
        $db = new PDO('sqlite::memory:');
        $db->exec(
            "CREATE TABLE tracks (id INTEGER PRIMARY KEY, name TEXT,
             laps INTEGER, distance REAL, fuel_per_lap REAL, fuel_per_lap_wet REAL,
             tyre_wear TEXT, wear_chassis REAL, wear_engine REAL, wear_fwing REAL,
             wear_rwing REAL, wear_underbody REAL, wear_sidepod REAL,
             wear_cooling REAL, wear_gearbox REAL, wear_brakes REAL,
             wear_suspension REAL, wear_electronics REAL)"
        );

        $result = $this->service($db)->calculateWear(
            ['id' => 999, 'name' => 'Unknown', 'laps' => null],
            [],
            [],
            0,
        );

        $this->assertArrayHasKey('error', $result);
    }

    public function testTestingWearRatesScaleFullRaceBaseByLapsDriverAndTestingFactor(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec(
            "CREATE TABLE tracks (id INTEGER PRIMARY KEY, name TEXT,
             laps INTEGER, wear_chassis REAL, wear_engine REAL, wear_fwing REAL,
             wear_rwing REAL, wear_underbody REAL, wear_sidepod REAL,
             wear_cooling REAL, wear_gearbox REAL, wear_brakes REAL,
             wear_suspension REAL, wear_electronics REAL)"
        );
        // 100-lap track, engine full-race base 50. Empty driver → factor 1.
        $db->exec(
            "INSERT INTO tracks (id, name, laps, wear_chassis, wear_engine, wear_fwing,
             wear_rwing, wear_underbody, wear_sidepod, wear_cooling, wear_gearbox,
             wear_brakes, wear_suspension, wear_electronics)
             VALUES (1, 'Testopolis', 100, 30, 50, 20, 20, 20, 20, 20, 30, 40, 20, 10)"
        );

        $result = $this->service($db)->testingWearRates(
            ['id' => 0, 'name' => 'Testopolis'],
            ['usaEngine' => 7],
            [],
        );

        // per-lap engine = base(50) * driverFactor(1) * 0.5 / laps(100) = 0.25.
        $this->assertEqualsWithDelta(0.25, $result['parts']['Engine']['per_lap'], 1e-9);
        // A 30-lap session therefore adds 0.25 * 30 = 7.5%.
        $this->assertEqualsWithDelta(7.5, $result['parts']['Engine']['per_lap'] * 30, 1e-9);
        $this->assertSame(7, $result['parts']['Engine']['start']);
        $this->assertSame(100, $result['race_laps']);
    }

    public function testTestingWearRatesReturnsErrorWhenTrackUnknown(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec("CREATE TABLE tracks (id INTEGER PRIMARY KEY, name TEXT, laps INTEGER)");

        $result = $this->service($db)->testingWearRates(
            ['id' => 0, 'name' => 'Nowhere'],
            [],
            [],
        );

        $this->assertArrayHasKey('error', $result);
    }
}
