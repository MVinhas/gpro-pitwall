<?php

declare(strict_types=1);

namespace App\Tests\Unit\Telemetry;

use App\Telemetry\RaceHistoryMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RaceHistoryMapper::class)]
final class RaceHistoryMapperTest extends TestCase
{
    /**
     * A structurally faithful RaceAnalysis payload: a 20-lap race with two
     * stops, so every derived block (stints, pits, per-km burn) has something
     * real to compute from.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $laps = [['idx' => 0, 'pos' => 8, 'tyres' => 'Hard', 'weather' => 'Sunny', 'temp' => 44, 'hum' => 35]];
        for ($i = 1; $i <= 20; $i++) {
            $laps[] = [
                'idx'      => $i,
                'pos'      => $i >= 15 ? 3 : 6,
                'tyres'    => 'Hard',
                'weather'  => 'Sunny',
                'temp'     => 44,
                'hum'      => 35,
                'lapTime'  => $i === 9 ? '1:13.264' : '1:14.100',
                'boostLap' => $i === 12 ? 1 : 0,
            ];
        }

        $base = [
            'selSeasonNb' => '107',
            'selRaceNb'   => '13',
            'group'       => 'Amateur - 4',
            'trackName'   => 'Hockenheim (Germany)',
            'trackId'     => '17',
            'q1Time'      => '1:14.839',
            'q1Pos'       => '9',
            'q2Time'      => '1:16.612',
            'q2Pos'       => '8',
            'q1Risk'      => 'Push the car a lot',
            'q2Risk'      => 'Push the car to the limit',
            'startRisk'   => 'Overtake where possible',
            'overtakeRisk' => '17',
            'defendRisk'   => '28',
            'clearDryRisk' => '35',
            'clearWetRisk' => '35',
            'problemRisk'  => '25',
            'otAttempts' => '5', 'overtakes' => '4',
            'otAttemptsOnYou' => '2', 'overtakesOnYou' => '1',
            'startFuel'   => 100,
            'finishFuel'  => 6,
            'finishTyres' => 41,
            'carPower'    => 90,
            'carHandl'    => 87,
            'carAccel'    => 88,
            'driver' => [
                'name' => 'Test Driver', 'id' => 1234, 'age' => 28, 'OA' => '140',
                'con' => '239', 'tal' => '206', 'agr' => '12', 'exp' => '96',
                'tei' => '89', 'sta' => '25', 'cha' => '115', 'mot' => '250',
                'rep' => '0', 'wei' => '72',
            ],
            'driverChanges' => [
                'OA' => '1', 'con' => '0', 'tal' => '0', 'agr' => '-2', 'exp' => '1',
                'tei' => '1', 'sta' => '0', 'cha' => '0', 'mot' => '10',
                'rep' => '0', 'wei' => '0',
            ],
            'tyreSupplier' => ['name' => 'Pipirelli'],
            'weather' => [
                'q1Weather' => 'Sunny', 'q1Temp' => 44, 'q1Hum' => 40,
                'q2Weather' => 'Sunny', 'q2Temp' => 41, 'q2Hum' => 35,
            ],
            'raceEnergy' => ['from' => 77, 'to' => 12],
            'q1Energy' => ['from' => 100, 'to' => 90],
            'q2Energy' => ['from' => 90, 'to' => 80],
            'setupsUsed' => [
                ['session' => 'Q1', 'setFWing' => '343', 'setRWing' => '781', 'setEng' => '737',
                 'setBra' => '383', 'setGear' => '552', 'setSusp' => '244', 'setTyres' => 'Hard'],
                ['session' => 'Q2', 'setFWing' => '331', 'setRWing' => '767', 'setEng' => '748',
                 'setBra' => '365', 'setGear' => '564', 'setSusp' => '262', 'setTyres' => 'Hard'],
                ['session' => 'Race', 'setFWing' => '339', 'setRWing' => '777', 'setEng' => '741',
                 'setBra' => '377', 'setGear' => '556', 'setSusp' => '250', 'setTyres' => 'Hard'],
            ],
            'laps' => $laps,
            'pits' => [
                ['idx' => 1, 'lap' => 8,  'reason' => 'Scheduled', 'fuelLeft' => 60, 'refilledTo' => 90, 'tyreCond' => 27, 'pitTime' => '20.863'],
                ['idx' => 2, 'lap' => 15, 'reason' => 'Scheduled', 'fuelLeft' => 55, 'refilledTo' => 80, 'tyreCond' => 34, 'pitTime' => '20.558'],
            ],
            'problems' => [['lap' => 11, 'reason' => 'Gearbox glitch']],
            'chassis'  => ['lvl' => 6, 'startWear' => 31, 'finishWear' => 50],
            'engine'   => ['lvl' => 6, 'startWear' => 16, 'finishWear' => 35],
        ];

        return array_merge($base, $overrides);
    }

    public function testMapsIdentityResultAndUserOwnership(): void
    {
        $row = (new RaceHistoryMapper())->map(42, $this->payload());

        $this->assertNotNull($row);
        $this->assertSame(42, $row['user_id']);
        $this->assertSame(107, $row['season']);
        $this->assertSame(13, $row['race']);
        $this->assertSame('Amateur', $row['level']);
        $this->assertSame('Amateur - 4', $row['group_label']);

        // Lap 0 is the grid slot; the last lap carrying a position is the flag.
        $this->assertSame(8, $row['grid_pos']);
        $this->assertSame(3, $row['final_pos']);
        $this->assertSame(5, $row['positions_gained']);
        $this->assertSame(15, $row['points']);
        $this->assertSame(0, $row['dnf']);
        $this->assertSame(20, $row['laps_completed']);
    }

    public function testReturnsNullForAPayloadWithNoRace(): void
    {
        $mapper = new RaceHistoryMapper();

        $this->assertNull($mapper->map(1, []));
        $this->assertNull($mapper->map(1, $this->payload(['laps' => []])));
        $this->assertNull($mapper->map(1, $this->payload(['driver' => []])));
    }

    public function testTakesBestLapAndBestPitFromTheirOwnBlocks(): void
    {
        $row = (new RaceHistoryMapper())->map(1, $this->payload());

        $this->assertNotNull($row);
        // 1:13.264 is the single quick lap among 1:14.100s.
        $this->assertSame(73264, $row['best_lap_ms']);
        // The quicker of the two stops, in milliseconds.
        $this->assertSame(20558, $row['best_pit_ms']);
    }

    public function testCountsLapConditionsAndProblems(): void
    {
        $row = (new RaceHistoryMapper())->map(1, $this->payload());

        $this->assertNotNull($row);
        $this->assertSame(20, $row['laps_total']);
        $this->assertSame(20, $row['dry_laps']);
        $this->assertSame(0, $row['rain_laps']);
        $this->assertSame(0, $row['mist_laps']);
        $this->assertSame(0, $row['was_wet']);
        $this->assertSame(1, $row['problem_laps']);
        $this->assertSame(1, $row['problems_count']);
    }

    public function testSplitsStintsAtEveryPitStop(): void
    {
        $row = (new RaceHistoryMapper())->map(1, $this->payload());

        $this->assertNotNull($row);
        /** @var list<array<string, mixed>> $stints */
        $stints = json_decode((string) $row['stints_json'], true);

        // Two stops means three stints, bounded by the pit laps.
        $this->assertCount(3, $stints);
        $this->assertSame([1, 8], [$stints[0]['lap_from'], $stints[0]['lap_to']]);
        $this->assertSame([9, 15], [$stints[1]['lap_from'], $stints[1]['lap_to']]);
        $this->assertSame([16, 20], [$stints[2]['lap_from'], $stints[2]['lap_to']]);

        // Stint 1 burns start fuel (100) down to what was left at the stop (60).
        $this->assertSame(40, $stints[0]['fuel_used']);
        // Tyres start each stint fresh, so wear is the drop from 100.
        $this->assertSame(73, $stints[0]['tyre_used']);
    }

    public function testBurnRatesArePerKilometreWhenALapLengthIsKnown(): void
    {
        $mapper = new RaceHistoryMapper();

        // Hockenheim's lap is 4.574 km. Stint 1 is 8 laps using 40 litres:
        // 40 / (8 * 4.574) = 1.093 l/km.
        $row = $mapper->map(1, $this->payload(), [], [], 4.574);
        $this->assertNotNull($row);

        /** @var list<array<string, mixed>> $stints */
        $stints = json_decode((string) $row['stints_json'], true);
        $this->assertSame(1.093, $stints[0]['fuel_per_km']);
        $this->assertNotNull($row['fuel_per_km']);
        $this->assertNotNull($row['tyre_per_km']);
    }

    public function testBurnRatesStayNullWithoutALapLength(): void
    {
        // A per-lap number wearing a per-km label would be silently wrong, so
        // an unknown lap length must yield no rate at all.
        $row = (new RaceHistoryMapper())->map(1, $this->payload(), [], [], null);

        $this->assertNotNull($row);
        $this->assertNull($row['fuel_per_km']);
        $this->assertNull($row['tyre_per_km']);
    }

    public function testCapturesBothQualifyingRunsAndTheSetupDelta(): void
    {
        $row = (new RaceHistoryMapper())->map(1, $this->payload());

        $this->assertNotNull($row);
        /** @var array<string, mixed> $quali */
        $quali = json_decode((string) $row['qualifying_json'], true);

        $this->assertCount(2, $quali['sessions']);
        $this->assertSame('Q1', $quali['sessions'][0]['session']);
        $this->assertSame(343, $quali['sessions'][0]['setup']['fwing']);
        $this->assertSame(331, $quali['sessions'][1]['setup']['fwing']);

        // Delta is Q2 − Q1, matching how GPRO prints the comparison.
        $this->assertSame(-12, $quali['delta']['fwing']);
        $this->assertSame(11, $quali['delta']['engine']);
        $this->assertSame(1773, $quali['delta']['lap_ms']);
    }

    public function testRecordsTheRaceSetupNotTheQualifyingTrim(): void
    {
        $row = (new RaceHistoryMapper())->map(1, $this->payload());

        $this->assertNotNull($row);
        $this->assertSame(339, $row['setup_fwing']);
        $this->assertSame(777, $row['setup_rwing']);
        $this->assertSame('Hard', $row['race_tyre']);
        $this->assertSame('Pipirelli', $row['tyre_supplier']);
    }

    public function testRecordsBoostLapNumbersNotACount(): void
    {
        $row = (new RaceHistoryMapper())->map(1, $this->payload());

        $this->assertNotNull($row);
        $this->assertSame(12, $row['boost_lap_1']);
        $this->assertNull($row['boost_lap_2']);
        $this->assertNull($row['boost_lap_3']);
    }

    public function testReconstructsPreRaceDriverValuesFromTheChangeBlock(): void
    {
        $row = (new RaceHistoryMapper())->map(1, $this->payload());

        $this->assertNotNull($row);
        /** @var array<string, mixed> $driver */
        $driver = json_decode((string) $row['driver_json'], true);

        $byLabel = [];
        foreach ($driver['attributes'] as $attr) {
            $byLabel[$attr['label']] = $attr;
        }

        // Post 140 with a +1 change means the driver started the race on 139.
        $this->assertSame(139, $byLabel['Overall']['pre']);
        $this->assertSame(140, $byLabel['Overall']['post']);
        $this->assertSame(1, $byLabel['Overall']['delta']);

        // Aggressiveness fell, so the pre value is higher than the post one.
        $this->assertSame(14, $byLabel['Aggressiveness']['pre']);
        $this->assertSame(-2, $byLabel['Aggressiveness']['delta']);

        $this->assertSame(77, $driver['energy']['pre']);
        $this->assertSame(12, $driver['energy']['post']);
    }

    public function testCapturesPerPartWearGain(): void
    {
        $row = (new RaceHistoryMapper())->map(1, $this->payload());

        $this->assertNotNull($row);
        /** @var list<array<string, mixed>> $parts */
        $parts = json_decode((string) $row['parts_json'], true);

        $byKey = [];
        foreach ($parts as $part) {
            $byKey[$part['key']] = $part;
        }

        $this->assertSame(6, $byKey['chassis']['level']);
        $this->assertSame(31, $byKey['chassis']['wear_start']);
        $this->assertSame(50, $byKey['chassis']['wear_end']);
        $this->assertSame(19, $byKey['chassis']['wear_gain']);
    }

    public function testRecordsPitStopFuelMath(): void
    {
        $row = (new RaceHistoryMapper())->map(1, $this->payload());

        $this->assertNotNull($row);
        /** @var list<array<string, mixed>> $pits */
        $pits = json_decode((string) $row['pits_json'], true);

        $this->assertCount(2, $pits);
        $this->assertSame(8, $pits[0]['lap']);
        $this->assertSame(60, $pits[0]['fuel_left']);
        $this->assertSame(90, $pits[0]['refilled_to']);
        $this->assertSame(30, $pits[0]['fuel_added']);
        $this->assertSame(20.863, $pits[0]['pit_time']);
    }

    public function testDetectsARetirementAsADnf(): void
    {
        $row = (new RaceHistoryMapper())->map(1, $this->payload(['laps' => [
            ['idx' => 0, 'pos' => 4],
            ['idx' => 1, 'pos' => 4],
            ['idx' => 2, 'pos' => 5, 'events' => [['event' => 'Driver retired from the race']]],
        ]]));

        $this->assertNotNull($row);
        $this->assertSame(1, $row['dnf']);
    }

    public function testStoresAnEmptyTeamBlockRatherThanInventingOne(): void
    {
        $row = (new RaceHistoryMapper())->map(1, $this->payload(), [], []);

        $this->assertNotNull($row);
        $this->assertSame('[]', $row['td_json']);
        $this->assertSame('[]', $row['staff_json']);
    }
}
