<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\TestingProjectionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TestingProjectionService::class)]
final class TestingProjectionServiceTest extends TestCase
{
    /** @var array<string, array{power: float, handling: float, acceleration: float}> */
    private const array POINTS = [
        'No special priority' => ['power' => 1.3, 'handling' => 1.3, 'acceleration' => 1.3],
        'Top speed'           => ['power' => 3.2, 'handling' => 0.4, 'acceleration' => 0.4],
    ];

    private TestingProjectionService $svc;

    protected function setUp(): void
    {
        // 0.5 decay → 0.125 net surviving over 3 races.
        $this->svc = new TestingProjectionService(self::POINTS, 0.5);
    }

    public function testRawGainScalesByLapsOverFive(): void
    {
        $car = ['power' => 100, 'handling' => 100, 'acceleration' => 100];
        $r = $this->svc->project($car, 100);
        // Top speed: 3.2 P × (100 / 5) = 64.
        $this->assertEqualsWithDelta(64.0, $r['Top speed']['raw']['power'], 0.001);
        $this->assertEqualsWithDelta(8.0,  $r['Top speed']['raw']['handling'], 0.001);
    }

    public function testLandedGainAppliesDecayCubed(): void
    {
        $car = ['power' => 100, 'handling' => 100, 'acceleration' => 100];
        $r = $this->svc->project($car, 100);
        // 64 raw × 0.5^3 = 64 × 0.125 = 8.
        $this->assertEqualsWithDelta(8.0, $r['Top speed']['landed']['power'], 0.001);
        $this->assertEqualsWithDelta(108.0, $r['Top speed']['new_car']['power'], 0.001);
    }

    public function testLapsHalfYieldsHalfTheGain(): void
    {
        $car = ['power' => 0, 'handling' => 0, 'acceleration' => 0];
        $full = $this->svc->project($car, 100);
        $half = $this->svc->project($car, 50);
        $this->assertEqualsWithDelta(
            $full['Top speed']['raw']['power'] / 2.0,
            $half['Top speed']['raw']['power'],
            0.001,
        );
    }

    public function testLapsClampedToMaxAndZero(): void
    {
        $car = ['power' => 0, 'handling' => 0, 'acceleration' => 0];
        $r = $this->svc->project($car, -10);
        $this->assertSame(0.0, $r['Top speed']['raw']['power']);

        $r = $this->svc->project($car, 500);
        // Same as MAX_LAPS = 100.
        $this->assertEqualsWithDelta(64.0, $r['Top speed']['raw']['power'], 0.001);
    }
}
