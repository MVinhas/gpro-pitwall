<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\StrategyService;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StrategyService::class)]
final class StrategyServiceTest extends TestCase
{
    /**
     * Minimal fixture — values are deliberately small and round so the test
     * keeps a clear arithmetic relation to the output. Real secrets are
     * obviously different (see config/secrets.php).
     *
     * @var array<string, mixed>
     */
    private const array SECRETS = [
        'fuel_factors' => [
            'conc' => 0.0, 'agg' => 0.0, 'exp' => 0.0, 'te' => 0.0,
            'eng_lvl' => 0.0, 'ele_lvl' => 0.0, 'constant' => 0.0,
        ],
        'tyre_suppliers_durabilities' => [
            'Pipirelli' => 1, 'Yokomama' => 2,
        ],
        'tyre_calc' => [
            'factors' => [
                'track_wear'      => 1.0,
                'avg_temp'        => 1.0,
                'tyre_durability' => 1.0,
                'suspension'      => 1.0,
                'aggressiveness'  => 1.0,
                'experience'      => 1.0,
                'weight'          => 1.0,
                'tyre_type_base'  => 1.0,
            ],
            'track_wear_values' => ['Medium' => 2.0, 'Low' => 1.0, 'High' => 3.0],
            'tyre_type_values'  => [
                'Extra Soft' => 1.0, 'Soft' => 1.0, 'Medium' => 1.0,
                'Hard' => 1.0, 'Rain' => 1.0,
            ],
            'tyre_risk_factors' => [
                'Extra Soft' => 1.0, 'Soft' => 1.0, 'Medium' => 1.0,
                'Hard' => 1.0, 'Rain' => 1.0,
            ],
            'base_wear_constant' => 1.0,
            'tyre_compound_difference' => ['Pipirelli' => 0.0],
        ],
        'pit_stop' => [
            'factor_fuel_td'         => 0.0,
            'factor_fuel_no_td'      => 0.0,
            'factor_staff_conc_td'   => 0.0,
            'factor_staff_conc_no_td' => 0.0,
            'factor_staff_stress_td' => 0.0,
            'factor_staff_stress_no_td' => 0.0,
            'factor_td_exp'          => 0.0,
            'factor_td_pit'          => 0.0,
            'base_time'              => 30.0,
        ],
    ];

    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->exec(
            "CREATE TABLE tracks (
                id INTEGER PRIMARY KEY,
                name TEXT,
                laps INTEGER,
                distance REAL,
                fuel_per_lap REAL,
                fuel_per_lap_wet REAL,
                tyre_wear TEXT,
                tyre_wear_factor REAL,
                pit_time REAL,
                corners INTEGER,
                lap_length REAL
            )"
        );
        $db->exec(
            "INSERT INTO tracks VALUES
             (1, 'Imola', 50, 250.0, 2.0, 2.5, 'Medium', 100.0, 22.0, 12, 5.0)"
        );
        return $db;
    }

    private function service(?PDO $db = null): StrategyService
    {
        return new StrategyService($db ?? $this->db(), self::SECRETS);
    }

    /** @return array<string, mixed> */
    private function inputs(): array
    {
        return ['laps' => 50, 'temp' => 20.0, 'hum' => 50, 'risk' => 0, 'target_wear' => 15];
    }

    public function testMissingTrackReturnsExplicitError(): void
    {
        $emptyDb = new PDO('sqlite::memory:');
        $emptyDb->exec("CREATE TABLE tracks (id INTEGER PRIMARY KEY, name TEXT)");

        $result = $this->service($emptyDb)->calculateStrategy(
            ['id' => 999, 'name' => 'Unknown'],
            [],
            ['concentration' => 50, 'aggressiveness' => 50, 'experience' => 50,
             'technical_insight' => 50, 'weight' => 75],
            ['concentration' => 0, 'stressHandling' => 0],
            ['id' => 0, 'ownTD' => 0, 'experience' => 0, 'pitCoordination' => 0],
            $this->inputs(),
        );

        $this->assertArrayHasKey('error', $result);
    }

    public function testCalculateStrategyReturnsAllFiveCompounds(): void
    {
        $result = $this->service()->calculateStrategy(
            ['id' => 1, 'name' => 'Imola'],
            ['lvlEngine' => 1, 'lvlElectronics' => 1, 'lvlSusp' => 1],
            ['concentration' => 50, 'aggressiveness' => 50, 'experience' => 50,
             'technical_insight' => 50, 'weight' => 75],
            ['concentration' => 0, 'stressHandling' => 0],
            ['id' => 0, 'ownTD' => 0, 'experience' => 0, 'pitCoordination' => 0],
            $this->inputs(),
        );

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(
            ['Extra Soft', 'Soft', 'Medium', 'Hard', 'Rain'],
            array_keys($result['tyres']),
        );
    }

    public function testEveryCompoundCarriesTheExpectedResultShape(): void
    {
        $result = $this->service()->calculateStrategy(
            ['id' => 1, 'name' => 'Imola'],
            ['lvlEngine' => 1, 'lvlElectronics' => 1, 'lvlSusp' => 1],
            ['concentration' => 50, 'aggressiveness' => 50, 'experience' => 50,
             'technical_insight' => 50, 'weight' => 75],
            ['concentration' => 0, 'stressHandling' => 0],
            ['id' => 0, 'ownTD' => 0, 'experience' => 0, 'pitCoordination' => 0],
            $this->inputs(),
        );

        foreach ($result['tyres'] as $row) {
            $this->assertArrayHasKey('stops', $row);
            $this->assertArrayHasKey('fuel_load', $row);
            $this->assertArrayHasKey('pit_time_est', $row);
            $this->assertArrayHasKey('lost_pits', $row);
            $this->assertArrayHasKey('lost_fuel', $row);
            $this->assertArrayHasKey('lost_tcd', $row);
            $this->assertArrayHasKey('total_lost', $row);
        }
    }

    public function testFuelDryEqualsDistanceTimesBaseRateWhenAdjustmentsAreZero(): void
    {
        // With every fuel_factor at 0, fuelAdj == 0, so:
        //   l/km = fuel_per_lap = 2.0
        //   simulatedDistance = laps * lap_length = 50 * 5.0 = 250 km
        //   totalFuelDry = 250 * 2.0 = 500 L (ceil → 500)
        $result = $this->service()->calculateStrategy(
            ['id' => 1, 'name' => 'Imola'],
            ['lvlEngine' => 1, 'lvlElectronics' => 1, 'lvlSusp' => 1],
            ['concentration' => 50, 'aggressiveness' => 50, 'experience' => 50,
             'technical_insight' => 50, 'weight' => 75],
            ['concentration' => 0, 'stressHandling' => 0],
            ['id' => 0, 'ownTD' => 0, 'experience' => 0, 'pitCoordination' => 0],
            $this->inputs(),
        );

        $this->assertSame(500.0, (float) $result['fuel']['dry']);
        $this->assertSame(625.0, (float) $result['fuel']['wet']);
    }

    public function testPitTimeFloorsAtFifteenSecondsEvenWithNegativeStaffEffects(): void
    {
        // Set staff/TD multipliers to large negatives so the unfloored pit time
        // would drop below 15. The service clamps to 15.0.
        $secrets = self::SECRETS;
        $secrets['pit_stop']['factor_staff_conc_no_td'] = -100.0;

        $svc = new StrategyService($this->db(), $secrets);

        $result = $svc->calculateStrategy(
            ['id' => 1, 'name' => 'Imola'],
            ['lvlEngine' => 1, 'lvlElectronics' => 1, 'lvlSusp' => 1],
            ['concentration' => 50, 'aggressiveness' => 50, 'experience' => 50,
             'technical_insight' => 50, 'weight' => 75],
            ['concentration' => 5, 'stressHandling' => 0],
            ['id' => 0, 'ownTD' => 0, 'experience' => 0, 'pitCoordination' => 0],
            $this->inputs(),
        );

        foreach ($result['tyres'] as $row) {
            $this->assertGreaterThanOrEqual(15.0, $row['pit_time_est']);
        }
    }
}
