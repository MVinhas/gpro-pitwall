<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Calibration\FuelCalibration;
use App\Calibration\WearCalibration;
use App\Database\DatabaseSeeder;
use App\Repository\EconomyLedgerRepository;
use App\Repository\RaceHistoryRepository;
use App\Repository\StandingsRepository;
use App\Repository\TrackRepository;
use App\Repository\WeekendStateRepository;
use App\Security\ApiTokenCrypto;
use App\Service\CarWearService;
use App\Service\FuelModel;
use App\Service\RaceWeekendService;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RaceWeekendService::class)]
final class RaceWeekendServiceTest extends TestCase
{
    private PDO $db;
    private RaceHistoryRepository $history;
    private WeekendStateRepository $weekends;
    private StandingsRepository $standings;
    private EconomyLedgerRepository $ledger;
    private RaceWeekendService $service;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        (new DatabaseSeeder(
            $this->db,
            ['Concentration' => 'concentration'],
            ['Rookie', 'Amateur', 'Pro', 'Master', 'Elite'],
            [],
            new ApiTokenCrypto('weekend-test-secret'),
        ))->migrate();

        $this->history = new RaceHistoryRepository($this->db);
        $this->weekends = new WeekendStateRepository($this->db);
        $this->standings = new StandingsRepository($this->db);
        $this->ledger = new EconomyLedgerRepository($this->db);

        $wear = new CarWearService($this->db, [
            'driver_wear_factors' => ['concentration' => 1.0, 'talent' => 1.0, 'experience' => 1.0],
            'part_level_factors' => array_fill_keys(range(1, 11), 1.0),
        ]);
        $fuel = new FuelModel(['fuel_factors' => [
            'conc' => 0.0, 'agg' => 0.0, 'exp' => 0.0, 'te' => 0.0,
            'eng_lvl' => 0.0, 'ele_lvl' => 0.0, 'constant' => 0.0,
        ]]);

        $this->service = new RaceWeekendService(
            $this->history,
            $this->weekends,
            $this->standings,
            $this->ledger,
            new TrackRepository($this->db),
            new WearCalibration($wear),
            new FuelCalibration($fuel),
        );

        $this->db->exec(
            "INSERT INTO tracks (name, laps, distance, fuel_per_lap, fuel_per_lap_wet, boost_dry, boost_wet,
                wear_engine, wear_brakes, wear_electronics)
             VALUES ('Testring', 60, 300.0, 2.0, 3.0, 0.3, 0.4, 20, 12, 4)"
        );
    }

    /** @param array<string, mixed> $overrides */
    private function race(array $overrides = []): void
    {
        $this->history->upsert(array_merge([
            'user_id' => 1, 'season' => 107, 'race' => 13,
            'track_id' => 17, 'track_name' => 'Testring', 'group_label' => 'Amateur - 4', 'level' => 'Amateur',
            'laps_total' => 60, 'laps_completed' => 60, 'dnf' => 0, 'was_wet' => 0,
            'dry_laps' => 60, 'rain_laps' => 0, 'mist_laps' => 0, 'fuel_per_km' => 2.0,
            'clear_dry_risk' => 20,
            'parts_json' => json_encode([
                ['key' => 'engine', 'label' => 'Engine', 'level' => 5, 'wear_start' => 0, 'wear_end' => 22, 'wear_gain' => 22],
                ['key' => 'brakes', 'label' => 'Brakes', 'level' => 4, 'wear_start' => 0, 'wear_end' => 20, 'wear_gain' => 20],
                ['key' => 'electronics', 'label' => 'Electronics', 'level' => 4, 'wear_start' => 0, 'wear_end' => 4, 'wear_gain' => 4],
            ], JSON_THROW_ON_ERROR),
            'driver_json' => json_encode(['id' => 1, 'name' => 'A. Driver', 'attributes' => [
                ['key' => 'con', 'pre' => 100, 'post' => 101],
                ['key' => 'tal', 'pre' => 90, 'post' => 90],
                ['key' => 'exp', 'pre' => 80, 'post' => 81],
                ['key' => 'agr', 'pre' => 20, 'post' => 20],
                ['key' => 'tei', 'pre' => 50, 'post' => 51],
            ]], JSON_THROW_ON_ERROR),
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function weekend(int $race, array $overrides = [], int $user = 1, int $season = 107): void
    {
        $this->weekends->save(array_merge([
            'user_id' => $user, 'season' => $season, 'race' => $race, 'source' => 'snapshot',
            'driver_json' => json_encode(['id' => 7, 'name' => 'A. Driver', 'profile' => ['concentration' => 100]]),
            'td_json' => null,
            'staff_json' => null,
            'facilities_json' => json_encode(['windtunnel' => 3, 'pitstop' => 2]),
            'car_json' => json_encode(['power' => 10, 'handling' => 20, 'accel' => 30,
                'parts' => ['engine' => ['level' => 5, 'wear' => 10]]]),
            'cash' => 5_000_000, 'pts' => 30, 'pos' => 5,
        ], $overrides));
    }

    private function standingsRow(int $race, int $pos, int $pts, int $user = 1, int $season = 107): void
    {
        $this->standings->save([
            'user_id' => $user, 'season' => $season, 'race' => $race,
            'own_pos' => $pos, 'own_pts' => $pts, 'group_label' => 'Amateur - 4',
            'managers_json' => json_encode([
                ['name' => 'Alice', 'pos' => 1, 'pts' => 60, 'IDM' => 11, 'cash' => 1],
                ['name' => 'Me', 'pos' => $pos, 'pts' => $pts, 'IDM' => 12, 'cash' => 2],
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    private function line(string $description, int $amount, string $at, ?int $balance = null, int $race = 13, int $user = 1): void
    {
        $this->ledger->save([
            'user_id' => $user, 'season' => 107, 'race' => $race, 'source' => 'economy',
            'occurred_at' => $at, 'description' => $description, 'amount' => $amount,
            'balance_after' => $balance, 'fingerprint' => sha1($user . $description . $at . $amount),
        ]);
    }

    public function testFullRaceGivesAllFourBlocks(): void
    {
        $this->race();
        $this->weekend(12);
        $this->weekend(13, ['facilities_json' => json_encode(['windtunnel' => 4, 'pitstop' => 2])]);
        $this->standingsRow(14, 4, 37);
        $this->line('Sponsor payment', 2_000_000, '2026-10-01 10:00:00', 7_000_000);

        $result = $this->service->forRace(1, 107, 13);

        $this->assertNotNull($result['weekend']);
        $this->assertNotNull($result['economy']);
        $this->assertNotNull($result['standings']);
        $this->assertNotNull($result['calibration']);
        $this->assertSame(5_000_000, $result['weekend']['cash']);
        $this->assertSame(7, $result['weekend']['driver']['id']);
        $this->assertSame(
            [['group' => 'facilities', 'key' => 'windtunnel', 'label' => 'Wind tunnel', 'from' => 3, 'to' => 4]],
            $result['weekend']['changes'],
        );
        $this->assertSame(['facilities.windtunnel' => true], $result['weekend']['changed']);
    }

    public function testCarPartChangesAreKeyedByGroupAndPart(): void
    {
        $this->weekend(12);
        $this->weekend(13, ['car_json' => json_encode(['power' => 10, 'handling' => 20, 'accel' => 30,
            'parts' => ['engine' => ['level' => 6, 'wear' => 0]]])]);

        $result = $this->service->forRace(1, 107, 13);

        $this->assertNotNull($result['weekend']);
        $this->assertSame(['car.engine' => true], $result['weekend']['changed']);
    }

    public function testAHireReportsNamesNotIds(): void
    {
        $this->weekend(12, ['td_json' => json_encode(['id' => 3, 'name' => 'Old TD', 'profile' => ['pitCoord' => 10]])]);
        $this->weekend(13, [
            'driver_json' => json_encode(['id' => 9, 'name' => 'B. Newcomer', 'profile' => ['concentration' => 100]]),
            'td_json' => json_encode(['id' => 4, 'name' => 'New TD', 'profile' => ['pitCoord' => 12]]),
        ]);

        $weekend = $this->service->forRace(1, 107, 13)['weekend'];

        $this->assertNotNull($weekend);
        $this->assertSame(
            [
                ['group' => 'driver', 'key' => 'id', 'label' => 'Driver', 'from' => 'A. Driver', 'to' => 'B. Newcomer'],
                ['group' => 'td', 'key' => 'id', 'label' => 'Technical director', 'from' => 'Old TD', 'to' => 'New TD'],
            ],
            $weekend['changes'],
        );
        $this->assertSame(['driver.id' => true, 'td.id' => true], $weekend['changed']);
    }

    public function testAHireFallsBackToTheIdWhenANameIsMissing(): void
    {
        $this->weekend(12);
        $this->weekend(13, ['driver_json' => json_encode(['id' => 9, 'profile' => ['concentration' => 100]])]);

        $weekend = $this->service->forRace(1, 107, 13)['weekend'];

        $this->assertNotNull($weekend);
        $this->assertSame('A. Driver', $weekend['changes'][0]['from']);
        $this->assertSame(9, $weekend['changes'][0]['to']);
    }

    public function testFirstStoredWeekendHasNoChanges(): void
    {
        $this->weekend(13);

        $result = $this->service->forRace(1, 107, 13);

        $this->assertNotNull($result['weekend']);
        $this->assertSame([], $result['weekend']['changes']);
        $this->assertSame([], $result['weekend']['changed']);
    }

    public function testStandingsAfterRaceComeFromTheNextRaceRow(): void
    {
        $this->standingsRow(13, 9, 10);
        $this->standingsRow(14, 4, 37);

        $standings = $this->service->forRace(1, 107, 13)['standings'];

        $this->assertNotNull($standings);
        $this->assertSame(4, $standings['own_pos']);
        $this->assertSame(37, $standings['own_pts']);
        $this->assertSame('Amateur - 4', $standings['group_label']);
        $this->assertSame([1, 4], array_column($standings['managers'], 'pos'));
        $this->assertSame([false, true], array_column($standings['managers'], 'own'));
        $this->assertArrayNotHasKey('IDM', $standings['managers'][0]);
        $this->assertArrayNotHasKey('cash', $standings['managers'][0]);
    }

    public function testLastRaceOfSeasonHasNoStandingsBlock(): void
    {
        $this->standingsRow(13, 9, 10);
        $this->standingsRow(1, 2, 8, season: 108);

        $this->assertNull($this->service->forRace(1, 107, 13)['standings']);
    }

    public function testUndecodableStandingsJsonHidesTheBlockAndLogsOnlyTheClass(): void
    {
        $this->standings->save([
            'user_id' => 1, 'season' => 107, 'race' => 14, 'own_pos' => 4, 'own_pts' => 37,
            'group_label' => 'Amateur - 4', 'managers_json' => '{secret-oops',
        ]);
        $log = tempnam(sys_get_temp_dir(), 'weekend-log');
        $this->assertNotFalse($log);
        $previous = ini_set('error_log', $log);

        try {
            $result = $this->service->forRace(1, 107, 13);
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $logged = (string) file_get_contents($log);
        unlink($log);
        $this->assertNull($result['standings']);
        $this->assertStringContainsString('[Debrief]', $logged);
        $this->assertStringContainsString('JsonException', $logged);
        $this->assertStringNotContainsString('secret-oops', $logged);
    }

    public function testUnknownTrackGivesNoCalibrationBlock(): void
    {
        $this->race(['track_name' => 'Nowhere Ring']);

        $this->assertNull($this->service->forRace(1, 107, 13)['calibration']);
    }

    public function testMissingRaceRowGivesNoCalibrationBlock(): void
    {
        $this->assertNull($this->service->forRace(1, 107, 13)['calibration']);
    }

    public function testCalibrationCarriesWearAndFuelAgainstTheStoredRace(): void
    {
        $this->race();

        $calibration = $this->service->forRace(1, 107, 13)['calibration'];

        $this->assertNotNull($calibration);
        $this->assertNotNull($calibration['wear']);
        $this->assertSame(3, $calibration['wear']['total']);
        $this->assertSame(2, $calibration['wear']['within']);
        $this->assertNotNull($calibration['fuel']);
        $this->assertSame(0.0, $calibration['fuel']['gap_pct']);
        $this->assertFalse($calibration['fuel']['flagged']);
    }

    public function testBackfilledRaceWithoutWeekendRowHidesWeekend(): void
    {
        $this->race();

        $this->assertNull($this->service->forRace(1, 107, 13)['weekend']);
    }

    public function testEconomyTotalsSplitIncomeAndCosts(): void
    {
        $this->line('Salaries', -500_000, '2026-10-01 12:00:00', 6_500_000);
        $this->line('Sponsor payment', 2_000_000, '2026-10-01 10:00:00', 7_000_000);
        $this->line('Tyres bought', -250_000, '2026-10-01 13:00:00', 6_250_000);

        $economy = $this->service->forRace(1, 107, 13)['economy'];

        $this->assertNotNull($economy);
        $this->assertSame(2_000_000, $economy['income']);
        $this->assertSame(750_000, $economy['costs']);
        $this->assertSame(1_250_000, $economy['net']);
        $this->assertSame(['Sponsor payment', 'Salaries', 'Tyres bought'], array_column($economy['lines'], 'description'));
        $this->assertSame(['Sponsors', 'Salaries', 'Tyres'], array_column($economy['lines'], 'category'));
        $this->assertSame(7_000_000, $economy['lines'][0]['balance_after']);
    }

    public function testRaceWithoutLedgerLinesHasNoEconomyBlock(): void
    {
        $this->assertNull($this->service->forRace(1, 107, 13)['economy']);
    }

    public function testOtherUsersRowsNeverLeakIn(): void
    {
        $this->race(['user_id' => 2]);
        $this->weekend(13, user: 2);
        $this->standingsRow(14, 1, 99, user: 2);
        $this->line('Sponsor payment', 1_000, '2026-10-01 10:00:00', null, user: 2);

        $result = $this->service->forRace(1, 107, 13);

        $this->assertSame(['weekend' => null, 'economy' => null, 'standings' => null, 'calibration' => null], $result);
    }
}
