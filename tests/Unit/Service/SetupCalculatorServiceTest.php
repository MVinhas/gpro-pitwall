<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\SetupCalculatorService;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Minimal fixture — round coefficients keep the arithmetic readable. Real
 * secrets differ (see config/secrets.php). The key property under test is
 * that the calculator emits exactly the sessions the caller asks for via
 * $weatherInputs: Race Strategy passes Q1/Q2/Race, the Testing tab passes a
 * single 'Testing' session.
 */
#[CoversClass(SetupCalculatorService::class)]
final class SetupCalculatorServiceTest extends TestCase
{
    private const array SECRETS = [
        'setup' => [
            'weather_coeffs' => [
                'Wings'      => ['dry' => 1.0, 'wet' => 1.0, 'wet_offset' => 0.0],
                'Engine'     => ['dry' => 1.0, 'wet' => 1.0, 'wet_offset' => 0.0],
                'Brakes'     => ['dry' => 1.0, 'wet' => 1.0, 'wet_offset' => 0.0],
                'Gear'       => ['dry' => 1.0, 'wet' => 1.0, 'wet_offset' => 0.0],
                'Suspension' => ['dry' => 1.0, 'wet' => 1.0, 'wet_offset' => 0.0],
            ],
            'driver' => [
                'wings_talent'       => 0.0,
                'eng_agg'            => 0.0,
                'eng_exp_pow'        => 0.0,
                'eng_exp_add'        => 0.0,
                'bra_talent'         => 0.0,
                'gear_conc'          => 0.0,
                'susp_exp'           => 0.0,
                'susp_wgt'           => 0.0,
                'susp_tech'          => 0.0,
                'special_track_mult' => 0.39,
            ],
            'car' => [
                'Wings'      => ['lvl' => ['FWing' => 0.0]],
                'Engine'     => ['lvl' => ['Engine' => 0.0]],
                'Brakes'     => ['lvl' => ['Brakes' => 0.0]],
                'Gear'       => ['lvl' => ['Gear' => 0.0]],
                'Suspension' => ['lvl' => ['Susp' => 0.0]],
            ],
            'final' => [
                'a_talent'   => 0.0,
                'b_wing_lvl' => 0.0,
                'c_step5'    => 0.0,
                'd_temp'     => 0.0,
                'wet_const'  => 0.0,
            ],
        ],
    ];

    private SetupCalculatorService $svc;

    /** @var array<string, mixed> */
    private array $driver = [
        'talent' => 100, 'aggressiveness' => 100, 'experience' => 100,
        'concentration' => 100, 'technical_insight' => 100, 'weight' => 70,
    ];

    protected function setUp(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('
            CREATE TABLE tracks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT UNIQUE NOT NULL,
                base_wings INTEGER, base_engine INTEGER, base_brakes INTEGER,
                base_gear INTEGER, base_suspension INTEGER, wing_split REAL
            )
        ');
        $db->exec("
            INSERT INTO tracks
                (name, base_wings, base_engine, base_brakes, base_gear, base_suspension, wing_split)
            VALUES ('Monza', 100, 200, 300, 400, 500, 0)
        ");

        $this->svc = new SetupCalculatorService($db, self::SECRETS);
    }

    public function testEmitsOnlyTheTestingSessionWhenAskedForOne(): void
    {
        $result = $this->svc->calculateSetups(
            ['id' => 0, 'name' => 'Monza'],
            [],
            $this->driver,
            ['Testing' => ['temp' => 25, 'weather' => 'Dry']],
        );

        $this->assertSame(['Testing'], array_keys($result));
        $this->assertSame(
            ['Front Wing', 'Rear Wing', 'Engine', 'Brakes', 'Gearbox', 'Suspension'],
            array_keys($result['Testing']),
        );
    }

    public function testStillEmitsAllThreeRaceSessions(): void
    {
        $weather = ['temp' => 20, 'weather' => 'Dry'];
        $result = $this->svc->calculateSetups(
            ['id' => 0, 'name' => 'Monza'],
            [],
            $this->driver,
            ['Q1' => $weather, 'Q2' => $weather, 'Race' => $weather],
        );

        $this->assertSame(['Q1', 'Q2', 'Race'], array_keys($result));
    }

    public function testReturnsEmptyWhenTrackUnknown(): void
    {
        $result = $this->svc->calculateSetups(
            ['id' => 0, 'name' => 'Nowhere'],
            [],
            $this->driver,
            ['Testing' => ['temp' => 25, 'weather' => 'Dry']],
        );

        $this->assertSame([], $result);
    }
}
