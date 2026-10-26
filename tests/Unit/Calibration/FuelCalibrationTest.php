<?php

declare(strict_types=1);

namespace App\Tests\Unit\Calibration;

use App\Calibration\FuelCalibration;
use App\Service\FuelModel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FuelCalibration::class)]
final class FuelCalibrationTest extends TestCase
{
    private function calibration(): FuelCalibration
    {
        return new FuelCalibration(new FuelModel(['fuel_factors' => [
            'conc' => 0.0, 'agg' => 0.0, 'exp' => 0.0, 'te' => 0.0,
            'eng_lvl' => 0.0, 'ele_lvl' => 0.0, 'constant' => 0.0,
        ]]));
    }

    /**
     * @return array<string, mixed>
     */
    private function track(): array
    {
        return [
            'name' => 'Monza', 'laps' => 60, 'distance' => 300.0,
            'fuel_per_lap' => 2.0, 'fuel_per_lap_wet' => 3.0,
            'boost_dry' => 0.3, 'boost_wet' => 0.4,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return [...[
            'track_name' => 'Monza',
            'was_wet' => 0,
            'fuel_per_km' => 2.0,
            'laps_completed' => 60,
            'dry_laps' => 60,
            'mist_laps' => 0,
            'rain_laps' => 0,
            'boost_lap_1' => null,
            'boost_lap_2' => null,
            'boost_lap_3' => null,
            'parts_json' => json_encode([
                ['key' => 'engine', 'label' => 'Engine', 'level' => 5, 'wear_start' => 0, 'wear_end' => 10, 'wear_gain' => 10],
                ['key' => 'electronics', 'label' => 'Electronics', 'level' => 4, 'wear_start' => 0, 'wear_end' => 5, 'wear_gain' => 5],
            ], JSON_THROW_ON_ERROR),
            'driver_json' => json_encode([
                'id' => 1,
                'name' => 'A. Driver',
                'attributes' => [
                    ['key' => 'con', 'label' => 'Concentration', 'pre' => 100, 'post' => 101, 'delta' => 1],
                    ['key' => 'agr', 'label' => 'Aggressiveness', 'pre' => null, 'post' => 20, 'delta' => null],
                    ['key' => 'exp', 'label' => 'Experience', 'pre' => 80, 'post' => 81, 'delta' => 1],
                    ['key' => 'tei', 'label' => 'Technical insight', 'pre' => 50, 'post' => 51, 'delta' => 1],
                ],
            ], JSON_THROW_ON_ERROR),
        ], ...$overrides];
    }

    public function testPredictedIsLapWeightedDryWetMix(): void
    {
        $result = $this->calibration()->forRace(
            $this->row(['dry_laps' => 30, 'rain_laps' => 10, 'laps_completed' => 40, 'fuel_per_km' => 2.25, 'was_wet' => 1]),
            $this->track(),
        );

        $this->assertNotNull($result);
        $this->assertSame(2.25, $result['predicted']);
        $this->assertSame(2.25, $result['actual']);
        $this->assertSame(0.0, $result['gap_pct']);
        $this->assertFalse($result['flagged']);
    }

    public function testMistLapsCountAsDry(): void
    {
        $result = $this->calibration()->forRace(
            $this->row(['dry_laps' => 20, 'mist_laps' => 10, 'rain_laps' => 0, 'laps_completed' => 30]),
            $this->track(),
        );

        $this->assertNotNull($result);
        $this->assertSame(2.0, $result['predicted']);
        $this->assertFalse($result['wet']);
    }

    public function testRainMajorityMarksTheRaceWet(): void
    {
        $result = $this->calibration()->forRace(
            $this->row(['dry_laps' => 10, 'rain_laps' => 30, 'laps_completed' => 40, 'was_wet' => 1]),
            $this->track(),
        );

        $this->assertNotNull($result);
        $this->assertTrue($result['wet']);
    }

    public function testBoostLitresAreSpreadOverTheDistanceRaced(): void
    {
        $result = $this->calibration()->forRace(
            $this->row(['boost_lap_1' => 10, 'boost_lap_2' => 40]),
            $this->track(),
        );

        $this->assertNotNull($result);
        $this->assertSame(2.03, $result['predicted']);
    }

    public function testZeroLapsSkipsTheRace(): void
    {
        $this->assertNull($this->calibration()->forRace($this->row(['laps_completed' => 0]), $this->track()));
    }

    public function testNoLapSplitSkipsTheRace(): void
    {
        $this->assertNull($this->calibration()->forRace(
            $this->row(['dry_laps' => 0, 'mist_laps' => 0, 'rain_laps' => 0]),
            $this->track(),
        ));
    }

    public function testMissingInputsSkipTheRace(): void
    {
        $calibration = $this->calibration();

        $this->assertNull($calibration->forRace($this->row(), null));
        $this->assertNull($calibration->forRace($this->row(['fuel_per_km' => null]), $this->track()));
        $this->assertNull($calibration->forRace($this->row(['driver_json' => '{oops']), $this->track()));
        $this->assertNull($calibration->forRace($this->row(['parts_json' => json_encode([])]), $this->track()));
    }

    public function testGapAboveThreePercentIsFlagged(): void
    {
        $result = $this->calibration()->forRace(
            $this->row(['dry_laps' => 30, 'rain_laps' => 10, 'laps_completed' => 40, 'fuel_per_km' => 2.4, 'was_wet' => 1]),
            $this->track(),
        );

        $this->assertNotNull($result);
        $this->assertSame(2.25, $result['predicted']);
        $this->assertSame(2.4, $result['actual']);
        $this->assertSame(6.3, $result['gap_pct']);
        $this->assertTrue($result['flagged']);
    }

    public function testGapWithinThreePercentIsNotFlagged(): void
    {
        $result = $this->calibration()->forRace($this->row(['fuel_per_km' => 2.05]), $this->track());

        $this->assertNotNull($result);
        $this->assertSame(2.4, $result['gap_pct']);
        $this->assertFalse($result['flagged']);
    }

    public function testBiasNeedsThreeDryRaces(): void
    {
        $tracks = ['Monza' => $this->track()];
        $wetRace = $this->row(['fuel_per_km' => 9.0, 'was_wet' => 1, 'rain_laps' => 60, 'dry_laps' => 0]);

        $two = $this->calibration()->bias([$this->row(['fuel_per_km' => 2.1]), $this->row(['fuel_per_km' => 2.2]), $wetRace], $tracks);
        $this->assertFalse($two['enough']);
        $this->assertNull($two['mean_gap_pct']);
        $this->assertSame(2, $two['n']);

        $rows = [
            $this->row(['fuel_per_km' => 2.1]),
            $this->row(['fuel_per_km' => 2.2]),
            $this->row(['fuel_per_km' => 2.3]),
            $this->row(['fuel_per_km' => 2.4, 'track_name' => 'Unknown Ring']),
        ];
        $three = $this->calibration()->bias($rows, $tracks);
        $this->assertTrue($three['enough']);
        $this->assertSame(3, $three['n']);
        $this->assertSame(1, $three['skipped']);
        $this->assertSame(9.0, $three['mean_gap_pct']);
    }
}
