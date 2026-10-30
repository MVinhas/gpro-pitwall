<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Database\DatabaseSeeder;
use App\Repository\RaceTelemetryRepository;
use App\Security\ApiTokenCrypto;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RaceTelemetryRepository::class)]
final class RaceTelemetryRepositoryTest extends TestCase
{
    private PDO $db;
    private RaceTelemetryRepository $repo;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        (new DatabaseSeeder(
            $this->db,
            ['Concentration' => 'concentration'],
            ['Rookie', 'Amateur', 'Pro', 'Master', 'Elite'],
            [],
            new ApiTokenCrypto('telemetry-test-secret'),
        ))->migrate();

        $this->repo = new RaceTelemetryRepository($this->db);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'season' => 99, 'race' => 8, 'level' => 'Rookie',
            'group_label' => 'Rookie - 31', 'track_id' => 58, 'track_name' => 'Baku',
            'final_pos' => 5, 'start_pos' => 8, 'points' => 10,
            'q1_pos' => 7, 'q2_pos' => 6, 'q1_time_ms' => 119502, 'q2_time_ms' => 122455,
            'positions_gained' => 3, 'dnf' => 0, 'laps_completed' => 51,
            'driver_id' => 30357, 'driver_oa' => 80, 'driver_con' => 96,
            'driver_tal' => 170, 'driver_agg' => 18, 'driver_exp' => 29,
            'driver_tei' => 67, 'driver_sta' => 41, 'driver_cha' => 89,
            'driver_mot' => 50, 'driver_rep' => 10, 'driver_wei' => 62,
            'q1_risk' => 'Push the car a little', 'q2_risk' => 'Push the car a lot',
            'start_risk' => 'Avoid trouble', 'overtake_risk' => 40,
            'defend_risk' => 30, 'clear_dry_risk' => 20, 'clear_wet_risk' => 10,
            'problem_risk' => 5,
            'mistake_seconds' => 1.2, 'ot_attempts' => 2, 'overtakes' => 3,
            'ot_attempts_on_you' => 3, 'overtakes_on_you' => 2,
            'has_td' => 1, 'td_overall' => 55, 'td_leadership' => 60,
            'td_mechanics' => 70, 'td_electronics' => 40, 'td_aerodynamics' => 50,
            'td_pit_coord' => 65, 'td_experience' => 30, 'td_motivation' => 80,
            'race_tyre' => 'Medium', 'tyre_supplier' => 'Pipirelli',
            'tyre_peak_temp' => 31, 'tyre_dry_perf' => 1, 'tyre_wet_perf' => 0,
            'tyre_durability' => 0, 'tyre_warmup' => 6,
            'was_wet' => 0, 'wet_lap_share' => 0.0, 'avg_temp' => 21.0,
            'avg_humidity' => 12.0, 'q1_weather' => 'Sunny', 'q2_weather' => 'Sunny',
            'pit_stops' => 2, 'start_fuel' => 73, 'finish_fuel' => 27,
            'finish_tyres' => 57, 'avg_pit_time' => 22.5, 'boost_laps' => 1,
            'car_power' => 13, 'car_handling' => 14, 'car_accel' => 15,
            'avg_part_level' => 3.0, 'total_wear_gain' => 26, 'problems_count' => 0,
            'setup_fwing' => 511, 'setup_rwing' => 522, 'setup_engine' => 533,
            'setup_brakes' => 544, 'setup_gear' => 555, 'setup_susp' => 566,
            'race_energy_from' => 100, 'race_energy_to' => 94,
        ], $overrides);
    }

    public function testTableCarriesNoUserIdentifyingColumn(): void
    {
        $columns = $this->db->query('PRAGMA table_info(race_telemetry)')
            ->fetchAll(PDO::FETCH_ASSOC);

        $this->assertNotEmpty($columns);

        foreach ($columns as $column) {
            $this->assertDoesNotMatchRegularExpression(
                '/user|manager|account|email|token|submission|owner/i',
                (string) $column['name'],
                'race_telemetry must not carry a user-identifying column'
            );
        }
    }

    public function testInsertStoresRowAndReportsNewness(): void
    {
        $this->assertTrue($this->repo->insertIfNew($this->row()));
        $this->assertSame(1, $this->repo->total());
    }

    public function testRepeatedIngestOfTheSameRaceIsDeduplicated(): void
    {
        $this->assertTrue($this->repo->insertIfNew($this->row()));
        $this->assertFalse($this->repo->insertIfNew($this->row()));

        $this->assertSame(1, $this->repo->total());
    }

    public function testDistinctRacesBothLand(): void
    {
        $this->repo->insertIfNew($this->row());
        $this->repo->insertIfNew($this->row(['race' => 9, 'q1_time_ms' => 118000]));

        $this->assertSame(2, $this->repo->total());
    }

    public function testLevelSummarySegmentsByLevel(): void
    {
        $this->repo->insertIfNew($this->row());
        $this->repo->insertIfNew($this->row(['race' => 9, 'q1_time_ms' => 1]));
        $this->repo->insertIfNew($this->row([
            'level' => 'Elite', 'group_label' => 'Elite', 'race' => 10, 'q1_time_ms' => 2,
        ]));

        $summary = $this->repo->levelSummary();
        $byLevel = array_column($summary, null, 'level');

        $this->assertArrayHasKey('Rookie', $byLevel);
        $this->assertArrayHasKey('Elite', $byLevel);
        $this->assertSame(2, (int) $byLevel['Rookie']['races']);
        $this->assertSame(1, (int) $byLevel['Elite']['races']);
    }

    public function testDriverAttributeCorrelationDetectsAPerfectRelationship(): void
    {
        // Talent rises exactly as finishing position improves, so r must be
        // a perfect -1 against final_pos (lower position = better).
        $positions = [1, 2, 3, 4, 5, 6, 7, 8];
        foreach ($positions as $i => $pos) {
            $this->repo->insertIfNew($this->row([
                'race'        => $i + 1,
                'q1_time_ms'  => 100000 + $i,
                'final_pos'   => $pos,
                'driver_tal'  => 200 - ($pos * 10),
            ]));
        }

        $rows = $this->repo->driverAttributeCorrelations('final_pos', 5);
        $talent = array_values(array_filter(
            $rows,
            static fn (array $r): bool => $r['attribute'] === 'driver_tal' && $r['level'] === 'Rookie'
        ));

        $this->assertCount(1, $talent);
        $this->assertEqualsWithDelta(-1.0, (float) $talent[0]['r'], 0.0001);
        $this->assertSame(8, (int) $talent[0]['n']);
    }

    public function testCorrelationRespectsMinimumSample(): void
    {
        $this->repo->insertIfNew($this->row());

        $this->assertSame([], $this->repo->driverAttributeCorrelations('final_pos', 5));
    }

    public function testTyrePerformanceSeparatesWetFromDry(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->repo->insertIfNew($this->row([
                'race' => $i + 1, 'q1_time_ms' => 100 + $i,
                'race_tyre' => 'Medium', 'was_wet' => 0, 'final_pos' => 4,
            ]));
            $this->repo->insertIfNew($this->row([
                'race' => $i + 20, 'q1_time_ms' => 200 + $i,
                'race_tyre' => 'Medium', 'was_wet' => 1, 'final_pos' => 12,
            ]));
        }

        $rows = $this->repo->tyrePerformance(3);
        $this->assertCount(2, $rows);

        $dry = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['was_wet'] === 0));
        $wet = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['was_wet'] === 1));

        $this->assertSame(4.0, (float) $dry[0]['avg_pos']);
        $this->assertSame(12.0, (float) $wet[0]['avg_pos']);
    }

    public function testTechnicalDirectorEffectSplitsBothArms(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->repo->insertIfNew($this->row([
                'race' => $i + 1, 'q1_time_ms' => 300 + $i, 'has_td' => 1, 'final_pos' => 3,
            ]));
            $this->repo->insertIfNew($this->row([
                'race' => $i + 30, 'q1_time_ms' => 400 + $i, 'has_td' => 0, 'final_pos' => 9,
            ]));
        }

        $rows = $this->repo->technicalDirectorEffect(3);
        $byTd = [];
        foreach ($rows as $row) {
            $byTd[(int) $row['has_td']] = $row;
        }

        $this->assertSame(3.0, (float) $byTd[1]['avg_pos']);
        $this->assertSame(9.0, (float) $byTd[0]['avg_pos']);
    }

    public function testRiskPerformanceRejectsUnknownColumn(): void
    {
        $this->repo->insertIfNew($this->row());

        // A column outside the whitelist must never reach the SQL.
        $this->assertSame([], $this->repo->riskPerformance('driver_oa'));
        $this->assertSame([], $this->repo->riskPerformance('1; DROP TABLE race_telemetry'));

        // The table is still there.
        $this->assertSame(1, $this->repo->total());
    }

    public function testWinningPrototypeSplitsPodiumFromRest(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->repo->insertIfNew($this->row([
                'race' => $i + 1, 'q1_time_ms' => 500 + $i,
                'final_pos' => 2, 'driver_tal' => 180,
            ]));
            $this->repo->insertIfNew($this->row([
                'race' => $i + 40, 'q1_time_ms' => 600 + $i,
                'final_pos' => 15, 'driver_tal' => 90,
            ]));
        }

        $rows = $this->repo->winningDriverPrototype(3);
        $byBand = array_column($rows, null, 'band');

        $this->assertSame(180.0, (float) $byBand['podium']['driver_tal']);
        $this->assertSame(90.0, (float) $byBand['rest']['driver_tal']);
    }

    public function testCalibrationRowsExposeOnlyTheCalibrationColumns(): void
    {
        $this->repo->insertIfNew($this->row(['was_wet' => 1]));
        $this->repo->insertIfNew($this->row(['race' => 9, 'q1_time_ms' => 118000]));

        $rows = $this->repo->calibrationRows();

        $this->assertCount(2, $rows);
        $this->assertSame(
            [
                'track_name', 'driver_con', 'driver_tal', 'driver_exp',
                'clear_dry_risk', 'avg_part_level', 'total_wear_gain', 'was_wet', 'dnf',
            ],
            array_keys($rows[0])
        );
        $this->assertSame('Baku', $rows[0]['track_name']);
        $this->assertSame(96, (int) $rows[0]['driver_con']);
    }

    public function testCalibrationRowsAreEmptyOnAnEmptyCorpus(): void
    {
        $this->assertSame([], $this->repo->calibrationRows());
    }

    public function testTrackConditionsGroupByBaseNameWithACorpusWideRow(): void
    {
        // One row per manager: four races, the third stored under both spellings of Monza.
        $rows = [
            [1, 'Monza (Italy)', 1, 18.0], [1, 'Monza (Italy)', 1, 22.0],
            [2, 'Monza (Italy)', 0, 30.0], [2, 'Monza (Italy)', 0, 30.0], [2, 'Monza (Italy)', 0, 30.0],
            [3, 'Monza (Italy)', 1, null], [3, 'Monza', 0, null],
            [4, 'Monza', 0, 10.0],
        ];
        foreach ($rows as $i => [$race, $name, $wet, $temp]) {
            self::assertTrue($this->repo->insertIfNew($this->row([
                'race' => $race, 'driver_id' => 1000 + $i, 'track_name' => $name,
                'was_wet' => $wet, 'avg_temp' => $temp,
            ])));
        }

        $conditions = $this->repo->trackConditions();

        $this->assertSame(['Monza'], array_keys($conditions['tracks']));
        $this->assertSame(
            ['races' => 4, 'wet' => 0.5, 'temp_races' => 3, 'temp' => 20.0],
            $conditions['tracks']['Monza']
        );
        $this->assertSame(['races' => 4, 'wet' => 0.5, 'temp' => 20.0], $conditions['all']);
    }

    public function testManyManagerRowsForOneRaceCountAsOneRace(): void
    {
        for ($i = 0; $i < 20; $i++) {
            self::assertTrue($this->repo->insertIfNew($this->row([
                'season' => 99, 'race' => 5, 'driver_id' => 2000 + $i, 'track_name' => 'Monza (Italy)',
                'was_wet' => $i === 0 ? 1 : 0, 'avg_temp' => 20.0 + $i,
            ])));
        }

        $conditions = $this->repo->trackConditions();

        self::assertSame(
            ['races' => 1, 'wet' => 1.0, 'temp_races' => 1, 'temp' => 29.5],
            $conditions['tracks']['Monza']
        );
        self::assertSame(['races' => 1, 'wet' => 1.0, 'temp' => 29.5], $conditions['all']);
    }

    public function testTrackConditionsOnAnEmptyCorpus(): void
    {
        $this->assertSame(
            ['tracks' => [], 'all' => ['races' => 0, 'wet' => null, 'temp' => null]],
            $this->repo->trackConditions()
        );
    }
}
