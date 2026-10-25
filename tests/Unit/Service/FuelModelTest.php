<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\FuelModel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FuelModel::class)]
final class FuelModelTest extends TestCase
{
    /** @var array<string, float> */
    private const array FACTORS = [
        'conc' => 0.01, 'agg' => 0.02, 'exp' => 0.03, 'te' => 0.04,
        'eng_lvl' => 0.05, 'ele_lvl' => 0.06, 'constant' => 0.1,
    ];

    private const array DRIVER = [
        'concentration' => 10, 'aggressiveness' => 20,
        'experience' => 30, 'technical_insight' => 40,
    ];

    private const array CAR = ['lvlEngine' => 2, 'lvlElectronics' => 3];

    /**
     * @param array<string, float> $factors
     */
    private function model(array $factors = self::FACTORS): FuelModel
    {
        return new FuelModel(['fuel_factors' => $factors]);
    }

    public function testPerKmIsBaseRatePlusTheWeightedAdjustment(): void
    {
        $rates = $this->model()->perKm(
            ['fuel_per_lap' => 2.0, 'fuel_per_lap_wet' => 2.5],
            self::DRIVER,
            self::CAR,
        );

        $this->assertEqualsWithDelta(5.38, $rates['dry'], 1e-9);
        $this->assertEqualsWithDelta(5.88, $rates['wet'], 1e-9);
    }

    public function testMissingWetRateFallsBackToDry(): void
    {
        $rates = $this->model()->perKm(
            ['fuel_per_lap' => 2.0, 'fuel_per_lap_wet' => null],
            self::DRIVER,
            self::CAR,
        );

        $this->assertEqualsWithDelta(5.38, $rates['wet'], 1e-9);
        $this->assertSame($rates['dry'], $rates['wet']);
    }

    public function testRatesNeverDropBelowPointOne(): void
    {
        $rates = $this->model([...self::FACTORS, 'constant' => -10.0])->perKm(
            ['fuel_per_lap' => 2.0, 'fuel_per_lap_wet' => 2.5],
            self::DRIVER,
            self::CAR,
        );

        $this->assertSame(0.1, $rates['dry']);
        $this->assertSame(0.1, $rates['wet']);
    }

    public function testLapLengthIsDistanceOverLapsWithAFallbackWhenLapsAreMissing(): void
    {
        $model = $this->model();

        $this->assertEqualsWithDelta(5.0, $model->lapLength(['distance' => 300, 'laps' => 60]), 1e-9);
        $this->assertSame(4.5, $model->lapLength(['distance' => 300, 'laps' => 0]));
    }

    public function testBoostExtraIsLapsTimesLapLengthTimesCoefficient(): void
    {
        $extra = $this->model()->boostExtra(
            ['distance' => 300, 'laps' => 60, 'boost_dry' => 0.3, 'boost_wet' => 0.5],
            9,
            false,
        );

        $this->assertEqualsWithDelta(13.5, $extra, 1e-9);
    }

    public function testWetBoostUsesTheWetCoefficient(): void
    {
        $track = ['distance' => 300, 'laps' => 60, 'boost_dry' => 0.3, 'boost_wet' => 0.5];

        $this->assertEqualsWithDelta(22.5, $this->model()->boostExtra($track, 9, true), 1e-9);

        $dryOnly = ['distance' => 300, 'laps' => 60, 'boost_dry' => 0.3, 'boost_wet' => null];
        $this->assertEqualsWithDelta(13.5, $this->model()->boostExtra($dryOnly, 9, true), 1e-9);
    }

    public function testNoBoostLapsCostsNothing(): void
    {
        $track = ['distance' => 300, 'laps' => 60, 'boost_dry' => 0.3, 'boost_wet' => 0.5];

        $this->assertSame(0.0, $this->model()->boostExtra($track, 0, false));
        $this->assertSame(0.0, $this->model()->boostExtra(['distance' => 300, 'laps' => 60], 9, false));
    }
}
