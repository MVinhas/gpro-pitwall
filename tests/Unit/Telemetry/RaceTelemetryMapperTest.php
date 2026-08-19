<?php

declare(strict_types=1);

namespace App\Tests\Unit\Telemetry;

use App\Telemetry\RaceTelemetryMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RaceTelemetryMapper::class)]
final class RaceTelemetryMapperTest extends TestCase
{
    /**
     * Trimmed but structurally faithful RaceAnalysis payload, shaped after the
     * example in the OpenAPI spec.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $base = [
            'selSeasonNb' => '99',
            'selRaceNb'   => '8',
            'group'       => 'Rookie - 31',
            'trackName'   => 'Baku City (Azerbaijan)',
            'trackId'     => '58',
            'q1Time'      => '1:59.502s',
            'q1Pos'       => '12',
            'q2Time'      => '2:02.455s',
            'q2Pos'       => '9',
            'q1Risk'      => 'Push the car a little',
            'q2Risk'      => 'Push the car a lot',
            'startRisk'   => 'Avoid trouble',
            'overtakeRisk'  => '40',
            'defendRisk'    => '30',
            'clearDryRisk'  => '20',
            'clearWetRisk'  => '10',
            'problemRisk'   => '5',
            'otAttempts'      => '2',
            'overtakes'       => '3',
            'otAttemptsOnYou' => '3',
            'overtakesOnYou'  => '2',
            'startFuel'   => 73,
            'finishFuel'  => 27,
            'finishTyres' => 57,
            'carPower'    => 13,
            'carHandl'    => 14,
            'carAccel'    => 15,
            'driver' => [
                'name' => 'Test Driver', 'id' => 30357, 'OA' => '80',
                'con' => '96', 'tal' => '170', 'agr' => '18', 'exp' => '29',
                'tei' => '67', 'sta' => '41', 'cha' => '89', 'mot' => '0',
                'rep' => '0', 'wei' => '62',
            ],
            'tyreSupplier' => [
                'name' => 'Pipirelli', 'peakTemp' => 31, 'dryPerf' => 1,
                'wetPerf' => 0, 'durability' => 0, 'warmup' => 6,
            ],
            'weather' => [
                'q1Weather' => 'Sunny', 'q1Temp' => 26,
                'q2Weather' => 'Sunny', 'q2Temp' => 21,
            ],
            'raceEnergy' => ['from' => 100, 'to' => 94],
            'setupsUsed' => [
                ['session' => 'Q1', 'setFWing' => '500', 'setRWing' => '500', 'setEng' => '500',
                 'setBra' => '500', 'setGear' => '500', 'setSusp' => '500', 'setTyres' => 'Soft'],
                ['session' => 'Race', 'setFWing' => '511', 'setRWing' => '522', 'setEng' => '533',
                 'setBra' => '544', 'setGear' => '555', 'setSusp' => '566', 'setTyres' => 'Medium'],
            ],
            'practiceLaps' => [
                ['idx' => 1, 'misTime' => '1.014'],
                ['idx' => 2, 'misTime' => '0.275'],
            ],
            'laps' => [
                ['idx' => 0, 'pos' => 14, 'tyres' => 'Medium', 'weather' => 'Sunny', 'temp' => 21, 'hum' => 11],
                ['idx' => 1, 'pos' => 13, 'tyres' => 'Medium', 'weather' => 'Sunny', 'temp' => 21, 'hum' => 11, 'boostLap' => 0],
                ['idx' => 2, 'pos' => 12, 'tyres' => 'Medium', 'weather' => 'Sunny', 'temp' => 23, 'hum' => 13, 'boostLap' => 1],
                ['idx' => 3, 'pos' => 11, 'tyres' => 'Medium', 'weather' => 'Sunny', 'temp' => 22, 'hum' => 12, 'boostLap' => 0],
            ],
            'pits' => [
                ['idx' => 1, 'lap' => 14, 'pitTime' => '22.183'],
                ['idx' => 2, 'lap' => 28, 'pitTime' => '23.817'],
            ],
            'problems' => [],
            'chassis' => ['lvl' => 2, 'startWear' => 30, 'finishWear' => 46],
            'engine'  => ['lvl' => 4, 'startWear' => 10, 'finishWear' => 20],
        ];

        return array_merge($base, $overrides);
    }

    public function testMapsCoreRaceIdentityAndResult(): void
    {
        $row = (new RaceTelemetryMapper())->map($this->payload());

        $this->assertNotNull($row);
        $this->assertSame(99, $row['season']);
        $this->assertSame(8, $row['race']);
        $this->assertSame('Rookie', $row['level']);
        $this->assertSame('Rookie - 31', $row['group_label']);
        $this->assertSame(58, $row['track_id']);

        // Final position is the last lap's pos; lap 0 is the grid slot.
        $this->assertSame(11, $row['final_pos']);
        $this->assertSame(14, $row['start_pos']);
        $this->assertSame(3, $row['positions_gained']);
        $this->assertSame(3, $row['laps_completed']);
        $this->assertSame(0, $row['dnf']);
    }

    public function testDerivesPointsFromFinishingPosition(): void
    {
        $mapper = new RaceTelemetryMapper();

        $podium = $this->payload(['laps' => [
            ['idx' => 0, 'pos' => 5],
            ['idx' => 1, 'pos' => 2],
        ]]);
        $this->assertSame(18, $mapper->map($podium)['points']);

        $outOfPoints = $this->payload(['laps' => [
            ['idx' => 0, 'pos' => 15],
            ['idx' => 1, 'pos' => 14],
        ]]);
        $this->assertSame(0, $mapper->map($outOfPoints)['points']);
    }

    public function testRecordsDriverAttributesAndRisks(): void
    {
        $row = (new RaceTelemetryMapper())->map($this->payload());

        $this->assertSame(80, $row['driver_oa']);
        $this->assertSame(170, $row['driver_tal']);
        $this->assertSame(62, $row['driver_wei']);

        $this->assertSame('Push the car a little', $row['q1_risk']);
        $this->assertSame('Push the car a lot', $row['q2_risk']);
        $this->assertSame(40, $row['overtake_risk']);
        $this->assertSame(30, $row['defend_risk']);
        $this->assertSame(20, $row['clear_dry_risk']);
        $this->assertSame(10, $row['clear_wet_risk']);
    }

    public function testSumsPracticeMistakeSeconds(): void
    {
        $row = (new RaceTelemetryMapper())->map($this->payload());

        $this->assertSame(1.289, $row['mistake_seconds']);
    }

    public function testUsesTheRaceSetupRowNotQualifying(): void
    {
        $row = (new RaceTelemetryMapper())->map($this->payload());

        $this->assertSame(511, $row['setup_fwing']);
        $this->assertSame(566, $row['setup_susp']);
        $this->assertSame('Medium', $row['race_tyre']);
    }

    public function testDetectsWetRaceFromLapsAndComputesShare(): void
    {
        $wet = $this->payload(['laps' => [
            ['idx' => 0, 'pos' => 8, 'weather' => 'Sunny', 'temp' => 20, 'hum' => 10],
            ['idx' => 1, 'pos' => 8, 'weather' => 'Rain', 'temp' => 18, 'hum' => 80],
            ['idx' => 2, 'pos' => 7, 'weather' => 'Rain', 'temp' => 18, 'hum' => 80],
            ['idx' => 3, 'pos' => 6, 'weather' => 'Sunny', 'temp' => 20, 'hum' => 40],
        ]]);

        $row = (new RaceTelemetryMapper())->map($wet);

        $this->assertSame(1, $row['was_wet']);
        // 2 wet laps of 3 counted (lap 0 is the grid slot, not a run lap).
        $this->assertEqualsWithDelta(0.6667, $row['wet_lap_share'], 0.001);
    }

    public function testDryRaceIsNotFlaggedWet(): void
    {
        $row = (new RaceTelemetryMapper())->map($this->payload());

        $this->assertSame(0, $row['was_wet']);
        $this->assertSame(0.0, $row['wet_lap_share']);
    }

    public function testAggregatesStrategyPitsAndBoost(): void
    {
        $row = (new RaceTelemetryMapper())->map($this->payload());

        $this->assertSame(2, $row['pit_stops']);
        $this->assertSame(23.0, $row['avg_pit_time']);
        $this->assertSame(1, $row['boost_laps']);
        $this->assertSame(73, $row['start_fuel']);
    }

    public function testAggregatesCarPartLevelsAndWear(): void
    {
        $row = (new RaceTelemetryMapper())->map($this->payload());

        $this->assertSame(3.0, $row['avg_part_level']);
        $this->assertSame(26, $row['total_wear_gain']);
    }

    public function testTechnicalDirectorPresenceAndAttributes(): void
    {
        $mapper = new RaceTelemetryMapper();

        $without = $mapper->map($this->payload());
        $this->assertSame(0, $without['has_td']);
        $this->assertNull($without['td_overall']);

        $with = $mapper->map($this->payload(), [
            'overall' => 55, 'leadership' => 60, 'mechanics' => 70,
            'electronics' => 40, 'aerodynamics' => 50, 'pitCoord' => 65,
            'experience' => 30, 'motivation' => 80,
        ]);
        $this->assertSame(1, $with['has_td']);
        $this->assertSame(55, $with['td_overall']);
        $this->assertSame(65, $with['td_pit_coord']);
    }

    #[DataProvider('lapTimeProvider')]
    public function testParsesQualifyingLapTimes(string $input, ?int $expected): void
    {
        $row = (new RaceTelemetryMapper())->map($this->payload(['q1Time' => $input]));

        $this->assertSame($expected, $row['q1_time_ms']);
    }

    /** @return list<array{string, int|null}> */
    public static function lapTimeProvider(): array
    {
        return [
            ['1:59.502s', 119502],
            ['1:59.502', 119502],
            ['59.250', 59250],
            ['-', null],
            ['', null],
        ];
    }

    #[DataProvider('levelProvider')]
    public function testExtractsLevelFromGroupLabel(string $group, ?string $expected): void
    {
        $row = (new RaceTelemetryMapper())->map($this->payload(['group' => $group]));

        if ($expected === null) {
            $this->assertNull($row);
            return;
        }

        $this->assertSame($expected, $row['level']);
    }

    /** @return list<array{string, string|null}> */
    public static function levelProvider(): array
    {
        return [
            ['Rookie - 31', 'Rookie'],
            ['Amateur - 4', 'Amateur'],
            ['Pro - 12', 'Pro'],
            ['Master - 1', 'Master'],
            ['Elite', 'Elite'],
            ['Nonsense - 9', null],
        ];
    }

    public function testRejectsNaPayloadWithNoLapsOrDriver(): void
    {
        $mapper = new RaceTelemetryMapper();

        $this->assertNull($mapper->map([]));
        $this->assertNull($mapper->map(['selSeasonNb' => '99', 'selRaceNb' => '8', 'group' => 'Rookie - 3']));
        $this->assertNull($mapper->map($this->payload(['laps' => []])));
        $this->assertNull($mapper->map($this->payload(['driver' => []])));
    }

    public function testOutputNeverCarriesUserIdentifyingKeys(): void
    {
        $row = (new RaceTelemetryMapper())->map($this->payload());

        foreach (array_keys($row) as $key) {
            $this->assertDoesNotMatchRegularExpression(
                '/user|manager|account|email|token|owner/i',
                $key,
                "mapper produced a user-identifying column: {$key}"
            );
        }

        // The driver's display name is a market entity, but still not stored:
        // the corpus needs the attributes, not the label.
        $this->assertArrayNotHasKey('driver_name', $row);
    }
}
