<?php

declare(strict_types=1);

namespace App\Tests\Unit\Calibration;

use App\Calibration\WearCalibration;
use App\Service\CarWearService;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WearCalibration::class)]
final class WearCalibrationTest extends TestCase
{
    private function calibration(): WearCalibration
    {
        $neutralLevels = array_fill_keys(range(1, 11), 1.0);

        return new WearCalibration(new CarWearService(new PDO('sqlite::memory:'), [
            'driver_wear_factors' => ['concentration' => 1.0, 'talent' => 1.0, 'experience' => 1.0],
            'part_level_factors' => $neutralLevels,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function track(): array
    {
        return [
            'name' => 'Monza',
            'wear_chassis' => 10, 'wear_engine' => 20, 'wear_fwing' => 8, 'wear_rwing' => 8,
            'wear_underbody' => 6, 'wear_sidepod' => 5, 'wear_cooling' => 7, 'wear_gearbox' => 9,
            'wear_brakes' => 12, 'wear_suspension' => 11, 'wear_electronics' => 4,
        ];
    }

    /**
     * @param list<array<string, mixed>>|null $parts
     * @return array<string, mixed>
     */
    private function row(int $engineGain = 22, bool $wet = false, ?array $parts = null, string $track = 'Monza'): array
    {
        $parts ??= [
            ['key' => 'engine', 'label' => 'Engine', 'level' => 5, 'wear_start' => 0, 'wear_end' => $engineGain, 'wear_gain' => $engineGain],
        ];

        return [
            'track_name' => $track,
            'clear_dry_risk' => 20,
            'was_wet' => $wet ? 1 : 0,
            'parts_json' => json_encode($parts, JSON_THROW_ON_ERROR),
            'driver_json' => json_encode([
                'id' => 1,
                'name' => 'A. Driver',
                'attributes' => [
                    ['key' => 'con', 'label' => 'Concentration', 'pre' => 100, 'post' => 101, 'delta' => 1],
                    ['key' => 'tal', 'label' => 'Talent', 'pre' => null, 'post' => 90, 'delta' => null],
                    ['key' => 'exp', 'label' => 'Experience', 'pre' => 80, 'post' => 81, 'delta' => 1],
                ],
            ], JSON_THROW_ON_ERROR),
        ];
    }

    public function testPredictedIsTrackBaseWhenFactorsAreNeutral(): void
    {
        $result = $this->calibration()->forRace($this->row(22), $this->track());

        $this->assertNotNull($result);
        $this->assertFalse($result['wet']);
        $this->assertCount(1, $result['parts']);
        $part = $result['parts'][0];
        $this->assertSame('engine', $part['key']);
        $this->assertSame(5, $part['level']);
        $this->assertSame(20.0, $part['predicted']);
        $this->assertSame(22, $part['actual']);
        $this->assertSame(2.0, $part['gap']);
        $this->assertFalse($part['flagged']);
        $this->assertSame(1, $result['within']);
        $this->assertSame(1, $result['total']);
    }

    public function testGapOfThreePointsIsFlagged(): void
    {
        $result = $this->calibration()->forRace($this->row(23), $this->track());

        $this->assertNotNull($result);
        $this->assertSame(3.0, $result['parts'][0]['gap']);
        $this->assertTrue($result['parts'][0]['flagged']);
        $this->assertSame(0, $result['within']);
        $this->assertSame(1, $result['total']);
    }

    public function testNegativeGapIsFlaggedByMagnitude(): void
    {
        $result = $this->calibration()->forRace($this->row(16), $this->track());

        $this->assertNotNull($result);
        $this->assertSame(-4.0, $result['parts'][0]['gap']);
        $this->assertTrue($result['parts'][0]['flagged']);
    }

    public function testMissingTrackRowSkipsTheRace(): void
    {
        $this->assertNull($this->calibration()->forRace($this->row(), null));
    }

    public function testUndecodablePartsJsonSkipsTheRace(): void
    {
        $row = $this->row();
        $row['parts_json'] = '{oops';

        $this->assertNull($this->calibration()->forRace($row, $this->track()));
    }

    public function testMissingDriverOrRiskSkipsTheRace(): void
    {
        $noDriver = $this->row();
        $noDriver['driver_json'] = null;
        $noRisk = $this->row();
        $noRisk['clear_dry_risk'] = null;

        $this->assertNull($this->calibration()->forRace($noDriver, $this->track()));
        $this->assertNull($this->calibration()->forRace($noRisk, $this->track()));
    }

    public function testPartWithNullLevelIsLeftOut(): void
    {
        $parts = [
            ['key' => 'engine', 'label' => 'Engine', 'level' => null, 'wear_start' => 0, 'wear_end' => 20, 'wear_gain' => 20],
            ['key' => 'brakes', 'label' => 'Brakes', 'level' => 4, 'wear_start' => 0, 'wear_end' => 12, 'wear_gain' => 12],
            ['key' => 'gear', 'label' => 'Gearbox', 'level' => 4, 'wear_start' => null, 'wear_end' => null, 'wear_gain' => null],
        ];

        $result = $this->calibration()->forRace($this->row(parts: $parts), $this->track());

        $this->assertNotNull($result);
        $this->assertCount(1, $result['parts']);
        $this->assertSame('brakes', $result['parts'][0]['key']);
        $this->assertSame(1, $result['total']);
    }

    public function testRetiredRaceIsLeftOutBecauseItsWearIsPartial(): void
    {
        $dnf = [...$this->row(), 'dnf' => 1, 'laps_total' => 50, 'laps_completed' => 20];
        $short = [...$this->row(), 'dnf' => 0, 'laps_total' => 50, 'laps_completed' => 49];
        $finished = [...$this->row(), 'dnf' => 0, 'laps_total' => 50, 'laps_completed' => 50];

        $this->assertNull($this->calibration()->forRace($dnf, $this->track()));
        $this->assertNull($this->calibration()->forRace($short, $this->track()));
        $this->assertNotNull($this->calibration()->forRace($finished, $this->track()));
    }

    public function testRetiredRacesAreCountedAsSkippedInBias(): void
    {
        $dnf = [...$this->row(5), 'dnf' => 1, 'laps_total' => 50, 'laps_completed' => 10];

        $bias = $this->calibration()->bias(
            [$this->row(22), $this->row(22), $this->row(22), $dnf],
            ['Monza' => $this->track()]
        );

        $this->assertSame(1, $bias['skipped']);
        $this->assertSame(3, $bias['parts']['engine']['n']);
        $this->assertSame(2.0, $bias['parts']['engine']['mean_gap']);
    }

    public function testRaceWithNoScoredPartIsSkippedNotShownAsZeroOfZero(): void
    {
        $unscorable = [
            ['key' => 'engine', 'label' => 'Engine', 'level' => null, 'wear_gain' => 20],
            ['key' => 'mystery', 'label' => 'Mystery', 'level' => 3, 'wear_gain' => 5],
        ];

        $this->assertNull($this->calibration()->forRace($this->row(parts: $unscorable), $this->track()));
        $this->assertNull($this->calibration()->forRace($this->row(parts: []), $this->track()));
    }

    public function testWetRaceIsReportedAsWet(): void
    {
        $result = $this->calibration()->forRace($this->row(wet: true), $this->track());

        $this->assertNotNull($result);
        $this->assertTrue($result['wet']);
    }

    public function testWetRacesAreExcludedFromBias(): void
    {
        $rows = [$this->row(22), $this->row(22), $this->row(22), $this->row(40, true)];

        $bias = $this->calibration()->bias($rows, ['Monza' => $this->track()]);

        $this->assertSame(3, $bias['parts']['engine']['n']);
        $this->assertSame(2.0, $bias['parts']['engine']['mean_gap']);
        $this->assertSame(0, $bias['skipped']);
    }

    public function testBiasNeedsThreeDryRaces(): void
    {
        $tracks = ['Monza' => $this->track()];

        $two = $this->calibration()->bias([$this->row(22), $this->row(24)], $tracks);
        $this->assertFalse($two['enough']);
        $this->assertNull($two['parts']['engine']['mean_gap']);
        $this->assertSame(2, $two['parts']['engine']['n']);

        $three = $this->calibration()->bias([$this->row(22), $this->row(24), $this->row(26)], $tracks);
        $this->assertTrue($three['enough']);
        $this->assertSame(4.0, $three['parts']['engine']['mean_gap']);
        $this->assertSame('Engine', $three['parts']['engine']['label']);
    }

    public function testBiasCountsRacesItCouldNotScoreAsSkipped(): void
    {
        $rows = [$this->row(22), $this->row(22, track: 'Unknown Ring')];

        $bias = $this->calibration()->bias($rows, ['Monza' => $this->track()]);

        $this->assertSame(1, $bias['skipped']);
        $this->assertSame(1, $bias['parts']['engine']['n']);
    }

    public function testCorpusCheckSkipsRowsWithMissingInputs(): void
    {
        $complete = [
            'track_name' => 'Monza', 'was_wet' => 0, 'clear_dry_risk' => 20,
            'avg_part_level' => 5.0, 'total_wear_gain' => 110,
            'driver_con' => 100, 'driver_tal' => 90, 'driver_exp' => 80,
        ];
        $rows = [
            $complete,
            $complete,
            [...$complete, 'avg_part_level' => null],
            [...$complete, 'driver_tal' => null],
            [...$complete, 'was_wet' => 1],
            [...$complete, 'dnf' => 1],
            [...$complete, 'track_name' => 'Unknown Ring'],
        ];

        $check = $this->calibration()->corpusCheck($rows, ['Monza' => $this->track()]);

        $this->assertSame(2, $check['n']);
        $this->assertSame(110 - 100.0, $check['mean_gap']);
    }

    public function testCorpusCheckWithNoUsableRowsHasNoMean(): void
    {
        $check = $this->calibration()->corpusCheck([], ['Monza' => $this->track()]);

        $this->assertSame(0, $check['n']);
        $this->assertNull($check['mean_gap']);
    }
}
