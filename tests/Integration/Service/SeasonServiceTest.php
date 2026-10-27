<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Calibration\FuelCalibration;
use App\Calibration\WearCalibration;
use App\Database\DatabaseSeeder;
use App\Repository\EconomyLedgerRepository;
use App\Repository\RaceHistoryRepository;
use App\Repository\RaceTelemetryRepository;
use App\Repository\SeasonBudgetRepository;
use App\Repository\StandingsRepository;
use App\Repository\TrackRepository;
use App\Repository\WeekendStateRepository;
use App\Security\ApiTokenCrypto;
use App\Service\CarWearService;
use App\Service\FuelModel;
use App\Service\SeasonService;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SeasonService::class)]
final class SeasonServiceTest extends TestCase
{
    private PDO $db;
    private RaceHistoryRepository $history;
    private WeekendStateRepository $weekends;
    private StandingsRepository $standings;
    private EconomyLedgerRepository $ledger;
    private SeasonBudgetRepository $budgets;
    private SeasonService $service;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        (new DatabaseSeeder(
            $this->db,
            ['Concentration' => 'concentration'],
            ['Rookie', 'Amateur', 'Pro', 'Master', 'Elite'],
            [],
            new ApiTokenCrypto('season-test-secret'),
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

        $this->budgets = new SeasonBudgetRepository($this->db);

        $this->service = new SeasonService(
            $this->history,
            $this->weekends,
            $this->standings,
            $this->ledger,
            new RaceTelemetryRepository($this->db),
            new TrackRepository($this->db),
            new WearCalibration($wear),
            new FuelCalibration($fuel),
            $this->budgets,
        );

        $this->db->exec(
            "INSERT INTO tracks (name, laps, distance, fuel_per_lap, fuel_per_lap_wet, boost_dry, boost_wet,
                wear_engine, wear_brakes, wear_electronics)
             VALUES ('Testring', 60, 300.0, 2.0, 3.0, 0.3, 0.4, 20, 12, 4)"
        );
    }

    /** @param array<string, mixed> $overrides */
    private function race(int $race, array $overrides = [], int $season = 107, int $user = 1): void
    {
        $this->history->upsert(array_merge([
            'user_id' => $user, 'season' => $season, 'race' => $race,
            'track_id' => 17, 'track_name' => 'Testring', 'group_label' => 'Amateur - 4', 'level' => 'Amateur',
            'laps_total' => 60, 'laps_completed' => 60, 'dnf' => 0, 'was_wet' => 0,
            'dry_laps' => 60, 'rain_laps' => 0, 'mist_laps' => 0, 'fuel_per_km' => 2.0,
            'final_pos' => 5, 'points' => 12,
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
    private function weekend(int $race, array $overrides = [], int $season = 107, int $user = 1): void
    {
        $this->weekends->save(array_merge([
            'user_id' => $user, 'season' => $season, 'race' => $race, 'source' => 'snapshot',
            'driver_json' => json_encode(['id' => 7, 'name' => 'A. Driver', 'profile' => ['concentration' => 100]]),
            'td_json' => null,
            'staff_json' => null,
            'facilities_json' => json_encode(['windtunnel' => 3, 'pitstop' => 2]),
            'car_json' => null,
            'cash' => 5_000_000, 'pts' => 30, 'pos' => 5,
        ], $overrides));
    }

    private function standingsRow(int $race, int $pos, int $pts, int $season = 107, int $user = 1): void
    {
        $this->standings->save([
            'user_id' => $user, 'season' => $season, 'race' => $race,
            'own_pos' => $pos, 'own_pts' => $pts, 'group_label' => 'Amateur - 4',
            'managers_json' => json_encode([['name' => 'Me', 'pos' => $pos, 'pts' => $pts]], JSON_THROW_ON_ERROR),
        ]);
    }

    private function line(
        string $description,
        int $amount,
        string $at,
        ?int $balance,
        int $race = 13,
        int $user = 1,
        int $season = 107,
    ): void {
        $this->ledger->save([
            'user_id' => $user, 'season' => $season, 'race' => $race, 'source' => 'economy',
            'occurred_at' => $at, 'description' => $description, 'amount' => $amount,
            'balance_after' => $balance, 'fingerprint' => sha1($user . $season . $description . $at . $amount),
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function plan(array $overrides = []): array
    {
        return array_merge([
            'v' => 1, 'ctr' => 41, 'mode' => 'nurse', 'safety' => 41,
            'swaps' => [], 'refused_swap_cost' => null,
            'target' => 4_000_000, 'target_kind' => 'start', 'cushion' => 300_000, 'season_start' => 4_000_000,
            'envelope' => 1_200_000, 'shortfall' => null,
            'rows' => [
                [
                    'race' => 9, 'track_name' => 'Testring',
                    'replaced' => [['part' => 'Engine', 'cost' => 180_000]],
                    'repairs' => 180_000, 'running' => 300_000, 'income' => 900_000, 'balance' => 4_420_000,
                    'wear_known' => true,
                ],
            ],
            'partial' => [], 'race' => 9,
        ], $overrides);
    }

    public function testBudgetForTheLatestSeason(): void
    {
        $this->race(1);
        $this->budgets->save(1, 107, 9, $this->plan());

        $budget = $this->service->build(1, null)['budget'];

        $this->assertNotNull($budget);
        $this->assertSame(9, $budget['race']);
        $this->assertSame(41, $budget['plan']['ctr']);
        $this->assertSame('End above $4M', $budget['view']['target']);
        $this->assertSame('$1.2M', $budget['view']['spend']['value']);
        $this->assertSame('ink', $budget['view']['spend']['tone']);
        $this->assertSame('Engine · $180k', $budget['view']['rows'][0]['replaced']);
        $this->assertSame('$4.4M', $budget['view']['rows'][0]['balance']);
        $this->assertSame('', $budget['view']['rows'][0]['balance_note']);
    }

    public function testBudgetOnlyForTheLatestSeason(): void
    {
        $this->race(1, season: 106);
        $this->race(1);
        $this->budgets->save(1, 106, 9, $this->plan());

        $this->assertNull($this->service->build(1, 106)['budget']);
        $this->assertNull($this->service->build(1, null)['budget']);
    }

    public function testNoBudgetRowGivesNull(): void
    {
        $this->race(1);

        $this->assertNull($this->service->build(1, null)['budget']);
    }

    public function testNoHistoryHasTheBudgetKeyAsNull(): void
    {
        $this->assertArrayHasKey('budget', $this->service->build(1, null));
        $this->assertNull($this->service->build(1, null)['budget']);
    }

    public function testAnotherUsersBudgetNeverLeaksIn(): void
    {
        $this->race(1);
        $this->budgets->save(2, 107, 9, $this->plan());

        $this->assertNull($this->service->build(1, null)['budget']);
    }

    public function testASpareOfZeroReadsNothingToSpareInAmber(): void
    {
        $this->race(1);
        $this->budgets->save(1, 107, 9, $this->plan(['envelope' => 0]));

        $spend = $this->service->build(1, null)['budget']['view']['spend'] ?? null;

        $this->assertSame(['value' => 'Nothing to spare', 'tone' => 'amber'], $spend);
    }

    public function testAShortModeReadsOverBudgetInRed(): void
    {
        $this->race(1);
        $this->budgets->save(1, 107, 9, $this->plan(['mode' => 'short', 'envelope' => 0, 'shortfall' => 250_000]));

        $spend = $this->service->build(1, null)['budget']['view']['spend'] ?? null;

        $this->assertSame(['value' => 'Over budget by $250k', 'tone' => 'red'], $spend);
    }

    public function testTheTargetReadsOneRaceOfCostsUnlessItIsTheStart(): void
    {
        $this->race(1);
        $this->budgets->save(1, 107, 9, $this->plan(['target_kind' => 'cushion']));

        $this->assertSame(
            'Keep one race of costs in hand',
            $this->service->build(1, null)['budget']['view']['target'] ?? null,
        );
    }

    public function testBalancesBelowTheTargetAreAmberAndNegativeOnesRed(): void
    {
        $this->race(1);
        $row = static fn (int $race, int $balance): array => [
            'race' => $race, 'track_name' => null, 'replaced' => [], 'repairs' => 0,
            'running' => 300_000, 'income' => 900_000, 'balance' => $balance, 'wear_known' => false,
        ];
        $this->budgets->save(1, 107, 9, $this->plan(['rows' => [
            $row(9, 4_100_000), $row(10, 3_000_000), $row(11, -50_000),
        ]]));

        $rows = $this->service->build(1, null)['budget']['view']['rows'] ?? [];

        $this->assertSame(['ink', ''], [$rows[0]['tone'], $rows[0]['balance_note']]);
        $this->assertSame(['amber', ' · below target'], [$rows[1]['tone'], $rows[1]['balance_note']]);
        $this->assertSame(['red', ' · negative'], [$rows[2]['tone'], $rows[2]['balance_note']]);
        $this->assertSame('-$50k', $rows[2]['balance']);
        $this->assertSame('—', $rows[0]['track']);
        $this->assertSame('wear unknown', $rows[0]['replaced']);
    }

    public function testAFailingBudgetReadIsLoggedByClassAndGivesNull(): void
    {
        $this->race(1);
        $broken = new class ($this->db) extends SeasonBudgetRepository {
            public function find(int $userId, int $season): ?array
            {
                throw new \RuntimeException('secret detail');
            }
        };
        $service = new SeasonService(
            $this->history,
            $this->weekends,
            $this->standings,
            $this->ledger,
            new RaceTelemetryRepository($this->db),
            new TrackRepository($this->db),
            new WearCalibration(new CarWearService($this->db, [
                'driver_wear_factors' => ['concentration' => 1.0, 'talent' => 1.0, 'experience' => 1.0],
                'part_level_factors' => array_fill_keys(range(1, 11), 1.0),
            ])),
            new FuelCalibration(new FuelModel(['fuel_factors' => [
                'conc' => 0.0, 'agg' => 0.0, 'exp' => 0.0, 'te' => 0.0,
                'eng_lvl' => 0.0, 'ele_lvl' => 0.0, 'constant' => 0.0,
            ]])),
            $broken,
        );
        $log = tempnam(sys_get_temp_dir(), 'season-log');
        $this->assertIsString($log);
        $previous = ini_set('error_log', $log);

        try {
            $budget = $service->build(1, null)['budget'];
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $this->assertNull($budget);
        $logged = (string) file_get_contents($log);
        unlink($log);
        $this->assertStringContainsString('[Season] RuntimeException', $logged);
        $this->assertStringNotContainsString('secret detail', $logged);
    }

    public function testDefaultsToTheNewestSeason(): void
    {
        $this->race(1, season: 106);
        $this->race(1);

        $view = $this->service->build(1, null);

        $this->assertSame(107, $view['season']);
        $this->assertSame([107, 106], $view['seasons']);
        $this->assertTrue($view['has_races']);
    }

    public function testARequestedKnownSeasonIsHonoured(): void
    {
        $this->race(1, season: 106);
        $this->race(1);

        $this->assertSame(106, $this->service->build(1, 106)['season']);
    }

    public function testUnknownSeasonFallsBackToNewest(): void
    {
        $this->race(1, season: 106);
        $this->race(1);

        $this->assertSame(107, $this->service->build(1, 999)['season']);
    }

    public function testNoHistoryGivesNoRaces(): void
    {
        $view = $this->service->build(1, 107);

        $this->assertNull($view['season']);
        $this->assertSame([], $view['seasons']);
        $this->assertFalse($view['has_races']);
        $this->assertSame([], $view['results']);
        $this->assertSame([], $view['money']['cash']);
        $this->assertSame([], $view['changes']);
    }

    public function testResultsCarryTheChampionshipAfterEachRaceFromTheNextRow(): void
    {
        $this->race(1, ['final_pos' => 3, 'points' => 15]);
        $this->race(2, ['final_pos' => null, 'points' => null, 'dnf' => 1]);
        $this->standingsRow(1, 9, 0);
        $this->standingsRow(2, 6, 15);
        $this->standingsRow(3, 7, 15);

        $results = $this->service->build(1, 107)['results'];

        $this->assertSame(['race', 'track_name', 'final_pos', 'points', 'dnf', 'champ_pos', 'champ_pts'], array_keys($results[0]));
        $this->assertSame(3, $results[0]['final_pos']);
        $this->assertFalse($results[0]['dnf']);
        $this->assertSame(6, $results[0]['champ_pos']);
        $this->assertSame(15, $results[0]['champ_pts']);
        $this->assertTrue($results[1]['dnf']);
        $this->assertNull($results[1]['final_pos']);
        $this->assertSame(7, $results[1]['champ_pos']);
    }

    public function testChampionshipIsNullWhenTheNextRowIsMissing(): void
    {
        $this->race(1);
        $this->standingsRow(1, 9, 0);

        $result = $this->service->build(1, 107)['results'][0];

        $this->assertNull($result['champ_pos']);
        $this->assertNull($result['champ_pts']);
    }

    public function testResultsLineKeepsEachRaceAtItsOwnX(): void
    {
        $this->race(1);
        $this->race(2);
        $this->race(3);
        $this->standingsRow(2, 8, 0);
        $this->standingsRow(4, 4, 0);

        $view = $this->service->build(1, 107);

        $this->assertSame('0,40 240,0', $view['results_line']);
    }

    public function testBackfilledSeasonWithoutLedgerStillBuilds(): void
    {
        $this->race(1);
        $this->race(2);

        $view = $this->service->build(1, 107);

        $this->assertTrue($view['has_races']);
        $this->assertCount(2, $view['results']);
        $this->assertSame([], $view['money']['cash']);
        $this->assertSame('', $view['money']['cash_line']);
        $this->assertSame([], $view['money']['categories']);
        $this->assertSame(0, $view['money']['income']);
        $this->assertSame(0, $view['money']['costs']);
        $this->assertSame([], $view['changes']);
    }

    public function testCashAfterARaceIsItsLastLedgerBalance(): void
    {
        $this->race(1);
        $this->race(2);
        $this->line('Sponsor payment', 2_000_000, '2026-10-01 10:00:00', 7_000_000, race: 1);
        $this->line('Salaries', -500_000, '2026-10-01 12:00:00', 6_500_000, race: 1);
        $this->line('Sponsor payment', 1_000_000, '2026-10-08 10:00:00', 7_500_000, race: 2);

        $cash = $this->service->build(1, 107)['money']['cash'];

        $this->assertSame([['race' => 1, 'cash' => 6_500_000], ['race' => 2, 'cash' => 7_500_000]], $cash);
    }

    public function testARaceWithoutBalanceIsLeftOutOfTheCashSeries(): void
    {
        $this->race(1);
        $this->race(2);
        $this->line('Sponsor payment', 10, '2026-10-01 10:00:00', null, race: 1);
        $this->line('Sponsor payment', 10, '2026-10-08 10:00:00', 5_000, race: 2);

        $money = $this->service->build(1, null)['money'];

        $this->assertSame([['race' => 2, 'cash' => 5_000]], $money['cash']);
    }

    public function testCategoriesSumIncomeAndCosts(): void
    {
        $this->race(1);
        $this->line('Sponsor payment', 2_000_000, '2026-10-01 10:00:00', 1, race: 1);
        $this->line('Sponsor payment', 500_000, '2026-10-02 10:00:00', 2, race: 2);
        $this->line('Salaries', -300_000, '2026-10-01 12:00:00', 3, race: 1);
        $this->line('Tyres bought', -250_000, '2026-10-01 13:00:00', 4, race: 1);
        $this->line('Tyres refund', 50_000, '2026-10-01 14:00:00', 5, race: 1);

        $money = $this->service->build(1, 107)['money'];

        $this->assertSame(
            [
                ['category' => 'Sponsors', 'income' => 2_500_000, 'costs' => 0],
                ['category' => 'Salaries', 'income' => 0, 'costs' => 300_000],
                ['category' => 'Tyres', 'income' => 50_000, 'costs' => 250_000],
            ],
            $money['categories'],
        );
        $this->assertSame(2_550_000, $money['income']);
        $this->assertSame(550_000, $money['costs']);
    }

    public function testChangesListOnlyRacesWithChanges(): void
    {
        $this->race(1);
        $this->weekend(1);
        $this->weekend(2);
        $this->weekend(3, ['facilities_json' => json_encode(['windtunnel' => 4, 'pitstop' => 2])]);
        $this->weekend(4, ['facilities_json' => json_encode(['windtunnel' => 4, 'pitstop' => 2])]);

        $changes = $this->service->build(1, 107)['changes'];

        $this->assertCount(1, $changes);
        $this->assertSame(3, $changes[0]['race']);
        $this->assertSame(
            [['group' => 'facilities', 'key' => 'windtunnel', 'label' => 'Wind tunnel', 'from' => 3, 'to' => 4]],
            $changes[0]['items'],
        );
    }

    public function testTheFirstRaceIsComparedWithThePreviousSeasonsLastWeekend(): void
    {
        $this->race(1, season: 106);
        $this->race(1);
        $this->weekend(17, season: 106);
        $this->weekend(1, ['facilities_json' => json_encode(['windtunnel' => 5, 'pitstop' => 2])]);

        $changes = $this->service->build(1, 107)['changes'];

        $this->assertCount(1, $changes);
        $this->assertSame(1, $changes[0]['race']);
        $this->assertSame(5, $changes[0]['items'][0]['to']);
    }

    public function testAHireIsReportedByName(): void
    {
        $this->race(1);
        $this->weekend(1);
        $this->weekend(2, ['driver_json' => json_encode(['id' => 9, 'name' => 'B. Newcomer', 'profile' => ['concentration' => 100]])]);

        $items = $this->service->build(1, 107)['changes'][0]['items'];

        $this->assertSame('A. Driver', $items[0]['from']);
        $this->assertSame('B. Newcomer', $items[0]['to']);
    }

    public function testCalibrationAveragesTheDryRacesAndCountsTheMissingOnes(): void
    {
        foreach ([1, 2, 3] as $race) {
            $this->race($race);
        }
        $this->race(4, ['was_wet' => 1]);
        $this->race(5, ['track_name' => 'Nowhere Ring']);

        $calibration = $this->service->build(1, 107)['calibration'];

        $this->assertTrue($calibration['wear']['enough']);
        $this->assertSame(1, $calibration['wear']['skipped']);
        $this->assertSame(3, $calibration['wear']['parts']['engine']['n']);
        $this->assertSame(0.0, $calibration['fuel']['mean_gap_pct']);
        $this->assertSame(3, $calibration['fuel']['n']);
        $this->assertSame(['mean_gap' => null, 'n' => 0], $calibration['corpus']);
    }

    public function testCalibrationFindsTracksWhoseRaceNameCarriesTheCountry(): void
    {
        foreach ([1, 2, 3] as $race) {
            $this->race($race, ['track_name' => 'Testring (Spain)']);
        }

        $calibration = $this->service->build(1, 107)['calibration'];

        $this->assertTrue($calibration['wear']['enough']);
        $this->assertSame(0, $calibration['wear']['skipped']);
        $this->assertSame(3, $calibration['fuel']['n']);
    }

    public function testCorpusCheckFindsTelemetryWhoseTrackNameCarriesTheCountry(): void
    {
        $this->db->exec(
            "INSERT INTO tracks (name, laps, distance, fuel_per_lap, fuel_per_lap_wet, boost_dry, boost_wet,
                wear_chassis, wear_engine, wear_fwing, wear_rwing, wear_underbody, wear_sidepod, wear_cooling,
                wear_gearbox, wear_brakes, wear_suspension, wear_electronics)
             VALUES ('Fullring', 60, 300.0, 2.0, 3.0, 0.3, 0.4, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10)"
        );
        $this->race(1);
        $this->db->exec(
            "INSERT INTO race_telemetry (season, race, level, track_name, driver_con, driver_tal, driver_exp, clear_dry_risk,
                avg_part_level, total_wear_gain, was_wet)
             VALUES (107, 1, 'Amateur', 'Fullring (Spain)', 100, 90, 80, 20, 5, 36, 0)"
        );

        $corpus = $this->service->build(1, 107)['calibration']['corpus'];

        $this->assertSame(1, $corpus['n']);
    }

    public function testCorpusCheckReadsTheTelemetryRows(): void
    {
        $this->db->exec(
            "INSERT INTO tracks (name, laps, distance, fuel_per_lap, fuel_per_lap_wet, boost_dry, boost_wet,
                wear_chassis, wear_engine, wear_fwing, wear_rwing, wear_underbody, wear_sidepod, wear_cooling,
                wear_gearbox, wear_brakes, wear_suspension, wear_electronics)
             VALUES ('Fullring', 60, 300.0, 2.0, 3.0, 0.3, 0.4, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10)"
        );
        $this->race(1);
        $this->db->exec(
            "INSERT INTO race_telemetry (season, race, level, track_name, driver_con, driver_tal, driver_exp, clear_dry_risk,
                avg_part_level, total_wear_gain, was_wet)
             VALUES (107, 1, 'Amateur', 'Fullring', 100, 90, 80, 20, 5, 36, 0)"
        );

        $corpus = $this->service->build(1, 107)['calibration']['corpus'];

        $this->assertSame(1, $corpus['n']);
        $this->assertNotNull($corpus['mean_gap']);
    }

    public function testOtherUsersRowsNeverLeakIn(): void
    {
        $this->race(1);
        $this->race(1, ['final_pos' => 1, 'points' => 25], user: 2);
        $this->weekend(1, user: 2);
        $this->weekend(2, ['facilities_json' => json_encode(['windtunnel' => 9])], user: 2);
        $this->standingsRow(2, 1, 99, user: 2);
        $this->line('Sponsor payment', 1_000, '2026-10-01 10:00:00', 1_000, race: 1, user: 2);

        $view = $this->service->build(1, 107);

        $this->assertSame(107, $view['season']);
        $this->assertCount(1, $view['results']);
        $this->assertSame(5, $view['results'][0]['final_pos']);
        $this->assertSame(12, $view['results'][0]['points']);
        $this->assertNull($view['results'][0]['champ_pos']);
        $this->assertNull($view['results'][0]['champ_pts']);
        $this->assertSame([], $view['money']['cash']);
        $this->assertSame([], $view['money']['categories']);
        $this->assertSame(0, $view['money']['income']);
        $this->assertSame([], $view['changes']);
    }

    public function testAnUnknownTrackNameIsLookedUpOnce(): void
    {
        $tracks = new class ($this->db) extends TrackRepository {
            /** @var list<string> */
            public array $lookups = [];

            public function findByName(string $name): ?array
            {
                $this->lookups[] = $name;

                return parent::findByName($name);
            }
        };
        $service = new SeasonService(
            $this->history,
            $this->weekends,
            $this->standings,
            $this->ledger,
            new RaceTelemetryRepository($this->db),
            $tracks,
            new WearCalibration(new CarWearService($this->db, [
                'driver_wear_factors' => ['concentration' => 1.0, 'talent' => 1.0, 'experience' => 1.0],
                'part_level_factors' => array_fill_keys(range(1, 11), 1.0),
            ])),
            new FuelCalibration(new FuelModel(['fuel_factors' => [
                'conc' => 0.0, 'agg' => 0.0, 'exp' => 0.0, 'te' => 0.0,
                'eng_lvl' => 0.0, 'ele_lvl' => 0.0, 'constant' => 0.0,
            ]])),
            $this->budgets,
        );
        foreach ([1, 2, 3] as $race) {
            $this->race($race, ['track_name' => 'Nowhere Ring']);
            $this->db->exec(
                "INSERT INTO race_telemetry (season, race, level, track_name, driver_con, driver_tal, driver_exp,
                    clear_dry_risk, avg_part_level, total_wear_gain, was_wet)
                 VALUES (107, {$race}, 'Amateur', 'Nowhere Ring', 100, 90, 80, 20, 5, 36, 0)"
            );
        }
        $this->race(4);

        $service->build(1, 107);

        $this->assertSame(['Nowhere Ring', 'Testring'], $tracks->lookups);
    }
}
