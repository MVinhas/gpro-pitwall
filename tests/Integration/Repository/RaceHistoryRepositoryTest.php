<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Database\DatabaseSeeder;
use App\Repository\RaceHistoryRepository;
use App\Security\ApiTokenCrypto;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RaceHistoryRepository::class)]
final class RaceHistoryRepositoryTest extends TestCase
{
    private PDO $db;
    private RaceHistoryRepository $repo;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        (new DatabaseSeeder(
            $this->db,
            ['Concentration' => 'concentration'],
            ['Rookie', 'Amateur', 'Pro', 'Master', 'Elite'],
            [],
            new ApiTokenCrypto('history-test-secret'),
        ))->migrate();

        $this->repo = new RaceHistoryRepository($this->db);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'user_id' => 1, 'season' => 107, 'race' => 13,
            'track_id' => 17, 'track_name' => 'Hockenheim', 'group_label' => 'Amateur - 4',
            'level' => 'Amateur',
            'laps_total' => 67, 'laps_completed' => 67, 'grid_pos' => 8,
            'final_pos' => 3, 'points' => 15, 'positions_gained' => 5, 'dnf' => 0,
            'best_lap_ms' => 73264, 'best_pit_ms' => 20558,
            'q1_pos' => 9, 'q2_pos' => 8, 'q1_time_ms' => 74839, 'q2_time_ms' => 76612,
            'pit_stops' => 2, 'start_fuel' => 100, 'finish_fuel' => 6,
            'finish_tyres' => 41, 'problems_count' => 0,
            'avg_temp' => 44.87, 'avg_humidity' => 35.9,
            'dry_laps' => 67, 'rain_laps' => 0, 'mist_laps' => 0, 'problem_laps' => 0,
            'was_wet' => 0, 'fuel_per_km' => 0.718, 'tyre_per_km' => 0.647,
            'race_tyre' => 'Hard', 'tyre_supplier' => 'Pipirelli',
            'setup_fwing' => 339, 'setup_rwing' => 777, 'setup_engine' => 741,
            'setup_brakes' => 377, 'setup_gear' => 556, 'setup_susp' => 250,
            'q1_risk' => 'Push the car a lot', 'q2_risk' => 'Push the car to the limit',
            'start_risk' => 'Overtake where possible',
            'overtake_risk' => 17, 'defend_risk' => 28, 'clear_dry_risk' => 35,
            'clear_wet_risk' => 35, 'problem_risk' => 25,
            'boost_lap_1' => 23, 'boost_lap_2' => null, 'boost_lap_3' => null,
            'ot_attempts' => 5, 'overtakes' => 4,
            'ot_attempts_on_you' => 2, 'overtakes_on_you' => 1,
            'car_power' => 90, 'car_handling' => 87, 'car_accel' => 88,
            'energy_from' => 77, 'energy_to' => 12,
            'qualifying_json' => '{"sessions":[],"delta":{}}',
            'stints_json' => '[{"number":1,"laps":25}]',
            'pits_json' => '[]', 'parts_json' => '[]',
            'driver_json' => '{"attributes":[]}', 'td_json' => '[]',
            'staff_json' => '[]', 'problems_json' => '[]',
        ], $overrides);
    }

    public function testStoresOneRaceAndIgnoresARepeatOfTheSameOne(): void
    {
        $this->assertTrue($this->repo->insertIfNew($this->row()));
        $this->assertFalse($this->repo->insertIfNew($this->row()));
        $this->assertSame(1, $this->repo->countFor(1));
    }

    public function testTwoManagersMayArchiveTheSameRaceIndependently(): void
    {
        $this->repo->insertIfNew($this->row(['user_id' => 1]));
        $this->repo->insertIfNew($this->row(['user_id' => 2]));

        $this->assertSame(1, $this->repo->countFor(1));
        $this->assertSame(1, $this->repo->countFor(2));
    }

    public function testNeverReturnsAnotherManagersRaces(): void
    {
        $this->repo->insertIfNew($this->row(['user_id' => 1]));
        $this->repo->insertIfNew($this->row(['user_id' => 2, 'final_pos' => 1]));

        $rows = $this->repo->search(1);

        $this->assertCount(1, $rows);
        $this->assertSame(3, (int) $rows[0]['final_pos']);
        $this->assertNull($this->repo->find(1, 107, 14));
    }

    public function testFindDecodesTheJsonBlocks(): void
    {
        $this->repo->insertIfNew($this->row());

        $race = $this->repo->find(1, 107, 13);

        $this->assertNotNull($race);
        $this->assertIsArray($race['stints']);
        $this->assertSame(1, $race['stints'][0]['number']);
        // The raw column is replaced by the decoded block, not duplicated.
        $this->assertArrayNotHasKey('stints_json', $race);
    }

    public function testImpressivenessRanksAClimbAboveAQuietWin(): void
    {
        // P1 from pole: 25 points, no places gained, win bonus  => 50 + 0 + 20.
        $this->repo->insertIfNew($this->row([
            'race' => 1, 'grid_pos' => 1, 'final_pos' => 1,
            'points' => 25, 'positions_gained' => 0,
        ]));
        // P2 from 18th: 18 points, 16 places gained, podium bonus => 36 + 64 + 10.
        $this->repo->insertIfNew($this->row([
            'race' => 2, 'grid_pos' => 18, 'final_pos' => 2,
            'points' => 18, 'positions_gained' => 16,
        ]));

        $rows = $this->repo->search(1, [], 'impressive');

        $this->assertSame(2, (int) $rows[0]['race']);
        $this->assertSame(1, (int) $rows[1]['race']);
    }

    public function testARetirementNeverOutranksAFinish(): void
    {
        $this->repo->insertIfNew($this->row([
            'race' => 1, 'final_pos' => null, 'points' => 0,
            'positions_gained' => 20, 'dnf' => 1,
        ]));
        $this->repo->insertIfNew($this->row([
            'race' => 2, 'final_pos' => 18, 'points' => 0, 'positions_gained' => 0,
        ]));

        $rows = $this->repo->search(1, [], 'impressive');

        $this->assertSame(2, (int) $rows[0]['race']);
        $this->assertSame(-20, (int) $rows[1]['score']);
    }

    public function testFiltersNarrowTheSliceByEveryWhitelistedKey(): void
    {
        $this->repo->insertIfNew($this->row(['race' => 1, 'track_id' => 17, 'race_tyre' => 'Hard']));
        $this->repo->insertIfNew($this->row(['race' => 2, 'track_id' => 11, 'race_tyre' => 'Soft']));
        $this->repo->insertIfNew($this->row(['race' => 3, 'track_id' => 17, 'race_tyre' => 'Soft']));

        $this->assertCount(2, $this->repo->search(1, ['track' => 17]));
        $this->assertCount(2, $this->repo->search(1, ['compound' => 'Soft']));
        $this->assertCount(1, $this->repo->search(1, ['track' => 17, 'compound' => 'Soft']));
    }

    public function testPositionFiltersAreRangesNotEqualityChecks(): void
    {
        $this->repo->insertIfNew($this->row(['race' => 1, 'final_pos' => 1, 'q2_pos' => 1]));
        $this->repo->insertIfNew($this->row(['race' => 2, 'final_pos' => 3, 'q2_pos' => 9]));
        $this->repo->insertIfNew($this->row(['race' => 3, 'final_pos' => 14, 'q2_pos' => 12]));

        $this->assertCount(2, $this->repo->search(1, ['finish_max' => 3]));
        $this->assertCount(1, $this->repo->search(1, ['quali_max' => 3]));
    }

    public function testAnUnknownFilterKeyIsIgnoredRatherThanApplied(): void
    {
        $this->repo->insertIfNew($this->row());

        // Whitelisting means a stray key changes nothing — and cannot reach SQL.
        $this->assertCount(1, $this->repo->search(1, ['no_such_filter' => "'; DROP TABLE users; --"]));
    }

    public function testSummaryDescribesTheSameSliceTheListShows(): void
    {
        $this->repo->insertIfNew($this->row(['race' => 1, 'final_pos' => 1, 'points' => 25, 'q2_pos' => 1]));
        $this->repo->insertIfNew($this->row(['race' => 2, 'final_pos' => 3, 'points' => 15]));
        $this->repo->insertIfNew($this->row(['race' => 3, 'final_pos' => null, 'points' => 0, 'dnf' => 1]));

        $all = $this->repo->summary(1);
        $this->assertSame(3, (int) $all['races']);
        $this->assertSame(1, (int) $all['wins']);
        $this->assertSame(2, (int) $all['podiums']);
        $this->assertSame(1, (int) $all['poles']);
        $this->assertSame(1, (int) $all['dnfs']);
        $this->assertSame(40, (int) $all['points']);

        $filtered = $this->repo->summary(1, ['finish_max' => 1]);
        $this->assertSame(1, (int) $filtered['races']);
    }

    public function testLatestPicksTheNewestRaceBySeasonThenRace(): void
    {
        $this->repo->insertIfNew($this->row(['season' => 107, 'race' => 13]));
        $this->repo->insertIfNew($this->row(['season' => 108, 'race' => 2]));
        $this->repo->insertIfNew($this->row(['season' => 107, 'race' => 17]));

        $this->assertSame(['season' => 108, 'race' => 2], $this->repo->latest(1));
        $this->assertNull($this->repo->latest(99));
    }

    public function testFilterOptionsOnlyOfferValuesThatOccur(): void
    {
        $this->repo->insertIfNew($this->row(['race' => 1, 'race_tyre' => 'Hard']));
        $this->repo->insertIfNew($this->row(['race' => 2, 'race_tyre' => 'Soft']));

        $options = $this->repo->filterOptions(1);

        $compounds = array_column($options['compounds'], 'value');
        sort($compounds);
        $this->assertSame(['Hard', 'Soft'], $compounds);
        $this->assertCount(1, $options['tracks']);
    }

    public function testAtTrackReturnsOnlyThatCircuitsRaces(): void
    {
        $this->repo->insertIfNew($this->row(['race' => 1, 'track_id' => 17]));
        $this->repo->insertIfNew($this->row(['race' => 2, 'track_id' => 11]));

        $rows = $this->repo->atTrack(1, 17);

        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]['race']);
    }
}
