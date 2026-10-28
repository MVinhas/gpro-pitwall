<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Database\DatabaseSeeder;
use App\Engineer\CtrCeiling;
use App\Engineer\CtrPlanner;
use App\Engineer\SeasonBudget;
use App\Engineer\UpgradeRanker;
use App\Repository\EconomyLedgerRepository;
use App\Repository\SeasonBudgetRepository;
use App\Repository\SeasonObjectiveRepository;
use App\Repository\StandingsRepository;
use App\Repository\TrackRepository;
use App\Repository\WeekendStateRepository;
use App\Security\ApiTokenCrypto;
use App\Service\BoostFuelService;
use App\Service\CarWearPlannerService;
use App\Service\CarWearService;
use App\Service\FuelModel;
use App\Service\RaceEngineerService;
use App\Service\RiskAdvisorService;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RaceEngineerService::class)]
final class RaceEngineerServiceTest extends TestCase
{
    private const int SEASON = 112;
    private const int USER = 1;

    private PDO $db;
    private SeasonObjectiveRepository $pins;
    private StandingsRepository $standings;
    private WeekendStateRepository $weekends;
    private EconomyLedgerRepository $ledger;
    private SeasonBudgetRepository $budgets;
    private RaceEngineerService $service;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        (new DatabaseSeeder(
            $this->db,
            ['Concentration' => 'concentration'],
            ['Rookie', 'Amateur', 'Pro', 'Master', 'Elite'],
            [],
            new ApiTokenCrypto('engineer-test-secret'),
        ))->migrate();

        $this->pins = new SeasonObjectiveRepository($this->db);
        $this->standings = new StandingsRepository($this->db);
        $this->weekends = new WeekendStateRepository($this->db);
        $this->ledger = new EconomyLedgerRepository($this->db);
        $this->budgets = new SeasonBudgetRepository($this->db);

        $this->service = $this->engineer();

        $this->db->exec(
            "INSERT INTO tracks (name, laps, distance, fuel_per_lap, fuel_per_lap_wet, boost_dry, boost_wet,
                overtaking, grip, tyre_wear, pit_time, wear_engine, wear_brakes, wear_electronics)
             VALUES ('Testring', 50, 250.0, 2.0, 3.0, 0.3, 0.4, 'Normal', 'Normal', 'Normal', 20.0, 40, 30, 10)"
        );
    }

    private function engineer(?SeasonBudgetRepository $budgets = null, ?UpgradeRanker $ranker = null): RaceEngineerService
    {
        $wear = new CarWearService($this->db, [
            'driver_wear_factors' => ['concentration' => 1.0, 'talent' => 1.0, 'experience' => 1.0],
            'part_level_factors' => [1 => 1.05] + array_fill_keys(range(2, 11), 1.0),
        ]);

        return new RaceEngineerService(
            $this->pins,
            $this->standings,
            $this->weekends,
            $this->ledger,
            new TrackRepository($this->db),
            $wear,
            new CarWearPlannerService($wear),
            new CtrPlanner(new CtrCeiling($wear), new SeasonBudget($wear)),
            $budgets ?? $this->budgets,
            new CtrCeiling($wear),
            new RiskAdvisorService(),
            new BoostFuelService(),
            new FuelModel(['fuel_factors' => [
                'conc' => 0.0, 'agg' => 0.0, 'exp' => 0.0, 'te' => 0.0,
                'eng_lvl' => 0.0, 'ele_lvl' => 0.0, 'constant' => 0.0,
            ]]),
            $ranker ?? new UpgradeRanker(new SeasonBudget($wear), [
                'Engine' => ['power' => 3, 'handling' => 1, 'acceleration' => 1],
                'Brakes' => ['power' => 0, 'handling' => 1, 'acceleration' => 2],
            ]),
        );
    }

    public function testBuildsObjectiveAndLinesFromStoredRows(): void
    {
        $this->seedPromotionFixture();

        $card = $this->service->forCockpit(self::USER, $this->cockpit());

        self::assertSame(self::SEASON, $card['season']);
        self::assertSame('promote', $card['objective']['objective']);
        self::assertFalse($card['objective']['pinned']);
        self::assertSame(['objective', 'cash'], array_slice(array_column($card['lines'], 'key'), 0, 2));
        $cash = $card['lines'][1];
        self::assertSame('Safe to spend', $cash['answer']);
        self::assertNotNull($cash['figure']);
    }

    public function testUsesTheLatestStandingsRowNotAfterTheNextRace(): void
    {
        $this->seedPromotionFixture();
        $this->standing(12, 20, 5);

        $card = $this->service->forCockpit(self::USER, $this->cockpit());

        self::assertSame(2, $card['objective']['numbers']['pos']);
        self::assertSame(9, $card['objective']['numbers']['races_left']);
    }

    public function testPinIsAppliedForTheCurrentSeasonOnly(): void
    {
        $this->seedPromotionFixture();

        self::assertTrue($this->service->pin(self::USER, self::SEASON - 1, 'survive'));
        self::assertFalse($this->service->forCockpit(self::USER, $this->cockpit())['objective']['pinned']);

        self::assertTrue($this->service->pin(self::USER, self::SEASON, 'survive'));
        $card = $this->service->forCockpit(self::USER, $this->cockpit());
        self::assertTrue($card['objective']['pinned']);
        self::assertSame('survive', $card['objective']['objective']);
        self::assertSame('promote', $card['objective']['proposed']);
    }

    public function testPinValues(): void
    {
        self::assertTrue($this->service->pin(self::USER, self::SEASON, 'hold'));
        self::assertSame('hold', $this->pins->find(self::USER, self::SEASON));

        self::assertFalse($this->service->pin(self::USER, self::SEASON, 'bogus'));
        self::assertSame('hold', $this->pins->find(self::USER, self::SEASON));

        self::assertTrue($this->service->pin(self::USER, self::SEASON, 'auto'));
        self::assertNull($this->pins->find(self::USER, self::SEASON));

        self::assertFalse($this->service->pin(self::USER, self::SEASON, 'bogus'));
        self::assertNull($this->pins->find(self::USER, self::SEASON));
    }

    public function testWearTrackAndWeatherDriveCtrRiskAndBoostLines(): void
    {
        $this->seedPromotionFixture();

        $keys = array_column($this->service->forCockpit(self::USER, $this->cockpit())['lines'], 'key');

        self::assertContains('ctr', $keys);
        self::assertContains('risk', $keys);
        self::assertContains('boost', $keys);
    }

    public function testBoostLitresUseTheWetCoefficientWhenTheRaceStartsWet(): void
    {
        $this->seedPromotionFixture();
        $weather = ['q1_wet' => false, 'q2_wet' => false, 'race_rain_avg' => 60.0, 'race_start_wet' => true];

        $lines = $this->service->forCockpit(self::USER, $this->cockpit(['weather' => $weather]))['lines'];
        $boost = array_values(array_filter($lines, static fn (array $l): bool => $l['key'] === 'boost'))[0];

        // 9 laps x 5 km x 0.4 = 18 L
        self::assertSame('Boost: 3 sets · +18 L', $boost['answer']);
    }

    public function testWithoutAnOutlookForcedSwapPartsCountAsFreshInTheCeilingAndArePricedFromTheSwapPlan(): void
    {
        $engine = ['level' => 1, 'start' => 95, 'est' => 40.0, 'end' => 135.0, 'track_base' => 8.0];
        $brakes = ['level' => 5, 'start' => 10, 'est' => 15.0, 'end' => 25.0, 'track_base' => 30.0];

        $lines = $this->service->forCockpit(99, $this->cockpit([
            'ctr' => 100,
            'wear' => ['track_name' => 'Testring', 'laps' => 50, 'parts' => ['Engine' => $engine, 'Brakes' => $brakes]],
            'wear_advice' => [
                'swap' => [['part' => 'Engine', 'level' => 5, 'start' => 95, 'est' => 40.0, 'end' => 135.0]],
                'risky' => [], 'watch' => [], 'headline' => '',
            ],
            'swap_plan' => ['cost' => 200_000],
        ]))['lines'];
        $byKey = array_column($lines, null, 'key');

        self::assertSame('CTR 51', $byKey['ctr']['answer']);
        self::assertSame('Swap Engine', $byKey['parts']['answer']);
        self::assertSame('$200k', $byKey['parts']['figure']);
    }

    public function testForcedSwapIsPricedFromThePartPricesAndPaidByTheBudget(): void
    {
        $this->seedPromotionFixture();
        $engine = ['level' => 1, 'start' => 95, 'est' => 40.0, 'end' => 135.0, 'track_base' => 8.0];
        $brakes = ['level' => 5, 'start' => 10, 'est' => 15.0, 'end' => 25.0, 'track_base' => 30.0];

        $lines = $this->service->forCockpit(self::USER, $this->cockpit([
            'ctr' => 100,
            'car' => $this->pricedCar(200_000),
            'wear' => ['track_name' => 'Testring', 'laps' => 50, 'parts' => ['Engine' => $engine, 'Brakes' => $brakes]],
        ]))['lines'];
        $byKey = array_column($lines, null, 'key');

        self::assertSame('Swap Engine', $byKey['parts']['answer']);
        self::assertSame('$200k', $byKey['parts']['figure']);
    }

    public function testCeilingIsStableWhenFollowed(): void
    {
        $this->seedPromotionFixture();
        $this->service->pin(self::USER, self::SEASON, 'survive');
        $parts = [
            'Engine' => ['level' => 1, 'start' => 60, 'est' => 0.0, 'end' => 0.0, 'track_base' => 8.0],
            'Brakes' => ['level' => 1, 'start' => 10, 'est' => 0.0, 'end' => 0.0, 'track_base' => 3.0],
        ];
        $swapAt100 = ['swap' => [['part' => 'Engine', 'level' => 5, 'start' => 60, 'est' => 50.0, 'end' => 110.0]]];
        $none = ['swap' => []];
        $targetAt = function (int $ctr, array $advice) use ($parts): string {
            $lines = $this->service->forCockpit(self::USER, $this->cockpit([
                'ctr' => $ctr,
                'wear' => ['track_name' => 'Testring', 'laps' => 50, 'parts' => $parts],
                'wear_advice' => $advice + ['risky' => [], 'watch' => [], 'headline' => ''],
            ]))['lines'];

            return array_column($lines, null, 'key')['ctr']['answer'];
        };

        $first = $targetAt(100, $swapAt100);
        self::assertMatchesRegularExpression('/^CTR \d+$/', $first);
        $target = (int) substr($first, 4);
        self::assertLessThan(100, $target);

        self::assertSame($first, $targetAt($target, $none));
    }

    public function testWithoutAnOutlookSurviveNeverAdvisesAboveTheSwapCeilingAndNamesTheStuckPart(): void
    {
        $parts = [
            'Engine' => ['level' => 1, 'start' => 85, 'est' => 0.0, 'end' => 0.0, 'track_base' => 6.0],
            'Gearbox' => ['level' => 1, 'start' => 10, 'est' => 0.0, 'end' => 0.0, 'track_base' => 6.0],
        ];
        // At CTR 100 the advisor forces the Engine swap; at the advised CTR it only calls it risky.
        $forcedAt100 = ['swap' => [['part' => 'Engine', 'level' => 1, 'start' => 85, 'est' => 40.7, 'end' => 125.7]], 'risky' => [], 'watch' => [], 'headline' => ''];
        $riskyOnly = ['swap' => [], 'risky' => [['part' => 'Engine', 'level' => 1, 'start' => 85, 'est' => 14.8, 'end' => 99.8]], 'watch' => [], 'headline' => ''];
        $ctrLine = function (int $ctr, array $advice) use ($parts): array {
            $lines = $this->service->forCockpit(99, $this->cockpit([
                'ctr' => $ctr,
                'wear' => ['track_name' => 'Testring', 'laps' => 50, 'parts' => $parts],
                'wear_advice' => $advice,
            ]))['lines'];

            return array_column($lines, null, 'key')['ctr'];
        };

        $this->service->pin(99, self::SEASON, 'hold');
        $breakCeiling = (int) substr($ctrLine(100, $forcedAt100)['answer'], 4);
        $this->service->pin(99, self::SEASON, 'survive');
        $survive = $ctrLine(100, $forcedAt100);
        $target = (int) substr($survive['answer'], 4);
        $followed = $ctrLine($target, $riskyOnly);

        self::assertLessThan(100, $breakCeiling);
        self::assertLessThanOrEqual($breakCeiling, $target);
        self::assertStringContainsString('Engine stays in the red at any CTR', $survive['reason']);
        self::assertStringNotContainsString('keeps parts out of the red', $survive['reason']);
        self::assertSame($survive['answer'], $followed['answer']);
        self::assertSame($survive['reason'], $followed['reason']);
    }

    public function testAForcedSwapPartIsNotReportedAsStuckInTheRed(): void
    {
        $this->seedPromotionFixture();
        $this->service->pin(self::USER, self::SEASON, 'survive');
        $engine = ['level' => 1, 'start' => 95, 'est' => 40.0, 'end' => 135.0, 'track_base' => 8.0];

        $lines = $this->service->forCockpit(self::USER, $this->cockpit([
            'ctr' => 100,
            'wear' => ['track_name' => 'Testring', 'laps' => 50, 'parts' => ['Engine' => $engine]],
            'wear_advice' => [
                'swap' => [['part' => 'Engine', 'level' => 1, 'start' => 95, 'est' => 40.0, 'end' => 135.0]],
                'risky' => [], 'watch' => [], 'headline' => '',
            ],
            'swap_plan' => ['cost' => 200_000],
        ]))['lines'];

        self::assertStringNotContainsString('stays in the red', array_column($lines, null, 'key')['ctr']['reason']);
    }

    public function testWithoutAnOutlookAForcedSwapPartIsNotReportedAsStuckInTheRed(): void
    {
        $this->service->pin(99, self::SEASON, 'survive');
        $engine = ['level' => 1, 'start' => 95, 'est' => 40.0, 'end' => 135.0, 'track_base' => 8.0];

        $lines = $this->service->forCockpit(99, $this->cockpit([
            'ctr' => 100,
            'wear' => ['track_name' => 'Testring', 'laps' => 50, 'parts' => ['Engine' => $engine]],
            'wear_advice' => [
                'swap' => [['part' => 'Engine', 'level' => 1, 'start' => 95, 'est' => 40.0, 'end' => 135.0]],
                'risky' => [], 'watch' => [], 'headline' => '',
            ],
            'swap_plan' => ['cost' => 200_000],
        ]))['lines'];
        $reason = array_column($lines, null, 'key')['ctr']['reason'];

        self::assertStringContainsString('capped at the highest CTR that keeps parts out of the red', $reason);
        self::assertStringNotContainsString('stays in the red', $reason);
    }

    public function testWithoutWearTheCtrLineIsOmitted(): void
    {
        $this->seedPromotionFixture();

        $keys = array_column($this->service->forCockpit(self::USER, $this->cockpit(['wear' => null]))['lines'], 'key');

        self::assertNotContains('ctr', $keys);
        self::assertContains('risk', $keys);
        self::assertContains('boost', $keys);
    }

    public function testATrackMissingFromTheDatabaseKeepsCtrButOmitsRiskAndBoost(): void
    {
        $this->seedPromotionFixture();

        $keys = array_column(
            $this->service->forCockpit(self::USER, $this->cockpit(['track_name' => 'Nowhere']))['lines'],
            'key',
        );

        self::assertContains('ctr', $keys);
        self::assertNotContains('risk', $keys);
        self::assertNotContains('boost', $keys);
    }

    public function testPushAdviceFollowsTheNextRacesRound(): void
    {
        $this->seedPromotionFixture();
        $this->seedWearTracks();
        $calendar = [
            ['race' => 9, 'track_name' => 'Alpha'],
            ['race' => 10, 'track_name' => 'Beta'],
            ['race' => 11, 'track_name' => 'Gamma'],
        ];
        $reasonFor = function (int $raceNb) use ($calendar): string {
            $lines = $this->service->forCockpit(self::USER, $this->cockpit([
                'office' => ['seasonNb' => self::SEASON, 'raceNb' => $raceNb],
                'car' => $this->pricedCar(),
                'season_calendar' => $calendar,
            ]))['lines'];

            return array_column($lines, null, 'key')['ctr']['reason'];
        };

        // Alpha wears the 10x engine, Beta the cheap brakes, Gamma everything alike.
        self::assertStringStartsWith('Race rated Hold back: ', $reasonFor(9));
        self::assertStringStartsWith('Race rated Push: the highest CTR where no part breaks', $reasonFor(10));
        self::assertStringStartsWith('Race rated Normal: ', $reasonFor(11));
    }

    public function testStaleSetupKeepsTheRiskLineWithoutWeather(): void
    {
        $this->seedPromotionFixture();

        $lines = $this->service->forCockpit(
            self::USER,
            $this->cockpit(['weather' => null, 'setup_stale' => true]),
        )['lines'];
        $risk = array_values(array_filter($lines, static fn (array $l): bool => $l['key'] === 'risk'))[0];

        self::assertSame('After you save the race setup', $risk['answer']);
    }

    public function testTestingCostIsTheMeanOfThisSeasonsSessions(): void
    {
        $this->seedPromotionFixture();
        $this->movement('Testing session 1', -80_000, 8);
        $this->movement('Testing session 2', -120_000, 9);
        $this->movement('Practice lap', -5_000, 9);

        $lines = $this->service->forCockpit(self::USER, $this->cockpit([
            'testing_open' => true,
            'testing_track' => 'Silverstone',
            'testing_targets' => [[
                'offset' => 3, 'season' => 'current', 'race' => 13, 'track_id' => 1, 'track_name' => 'Testring',
                'power' => 1, 'handling' => 1, 'acceleration' => 1,
            ]],
        ]))['lines'];
        $testing = array_values(array_filter($lines, static fn (array $l): bool => $l['key'] === 'testing'))[0];

        self::assertSame('Test at Silverstone', $testing['answer']);
        self::assertSame('The budget covers it; tunes for Testring (race 13)', $testing['reason']);
        self::assertSame('$100k', $testing['figure']);
    }

    public function testNoStoredRowsStillReturnsLines(): void
    {
        $card = $this->service->forCockpit(99, $this->cockpit(['menu' => ['cash' => 1_000_000]]));

        self::assertNull($card['objective']['objective']);
        self::assertSame('Objective unknown', $card['objective']['label']);
        self::assertSame('objective', $card['lines'][0]['key']);
        self::assertSame('cash', $card['lines'][1]['key']);
        self::assertSame('Costs unknown — sync once after a race', $card['lines'][1]['answer']);
    }

    public function testSeasonOverReturnsOnlyTheObjectiveLine(): void
    {
        $this->seedPromotionFixture();
        $this->standing(17, 2, 120);

        $card = $this->service->forCockpit(self::USER, $this->cockpit(['office' => [
            'seasonNb' => self::SEASON, 'raceNb' => 18,
        ]]));

        self::assertTrue($card['objective']['over']);
        self::assertCount(1, $card['lines']);
    }

    public function testAdviceIgnoresTheCurrentCtr(): void
    {
        $this->seedPromotionFixture();
        $this->service->pin(self::USER, self::SEASON, 'survive');

        // The Cockpit's own advice changes with the CTR: at 100 it forces the Engine swap, lower it doesn't.
        $lines = [];
        foreach ([0, 30, 60, 100] as $ctr) {
            $lines[$ctr] = $this->surviveCtrLine($ctr, $ctr === 100 ? $this->swapAt100() : $this->noSwap());
        }

        self::assertLessThan(100, (int) substr($lines[0]['answer'], 4));
        foreach ($lines as $line) {
            self::assertSame($lines[0]['answer'], $line['answer']);
            self::assertSame($lines[0]['reason'], $line['reason']);
        }
    }

    public function testFollowingUseNGivesNAgain(): void
    {
        $this->seedPromotionFixture();
        $this->service->pin(self::USER, self::SEASON, 'survive');

        $first = $this->surviveCtrLine(100, $this->swapAt100());
        $target = (int) substr($first['answer'], 4);
        self::assertLessThan(100, $target);
        self::assertSame('Use ' . $target, $first['action']['label'] ?? null);

        $followed = $this->surviveCtrLine($target, $this->noSwap());

        self::assertSame($first['answer'], $followed['answer']);
        self::assertSame($first['reason'], $followed['reason']);
        self::assertNull($followed['action']);
    }

    public function testPlanIsSavedForTheSeason(): void
    {
        $this->seedPromotionFixture();

        $this->service->forCockpit(self::USER, $this->cockpit());
        $saved = $this->budgets->find(self::USER, self::SEASON);

        self::assertNotNull($saved);
        self::assertSame(1, $saved['plan']['v']);
        self::assertSame(10, $saved['race']);
        self::assertSame(10, $saved['plan']['race']);
    }

    public function testSeasonStartComesFromTheLedger(): void
    {
        $this->seedPromotionFixture();
        $this->service->pin(self::USER, self::SEASON, 'hold');
        $this->ledger->save([
            'user_id' => self::USER, 'season' => self::SEASON, 'race' => 1, 'source' => 'economy',
            'occurred_at' => '2026-10-01 12:00:00', 'description' => 'Sponsor Acme', 'amount' => 300_000,
            'balance_after' => 4_300_000, 'fingerprint' => sha1('season-start'),
        ]);

        $lines = $this->service->forCockpit(self::USER, $this->cockpit())['lines'];

        self::assertStringContainsString('Ends the season above your', array_column($lines, null, 'key')['cash']['reason']);
    }

    public function testSeasonStartReadsRaceOneTheWayTheProjectorStoresIt(): void
    {
        $this->seedPromotionFixture();
        $this->ledger->save([
            'user_id' => self::USER, 'season' => self::SEASON, 'race' => 1, 'source' => 'race',
            'occurred_at' => null, 'description' => 'Race prize', 'amount' => 1_200_000,
            'balance_after' => null, 'fingerprint' => sha1('race|1|prize'),
        ]);
        // EconomyHistory's race 1 lines, in API order: one shared second, newest first.
        $lines = [
            ['Tyre supplier costs (Pipirelli)', -250_000, 27_472_820],
            ['Facility costs', 0, 27_722_820],
            ['Salary for your staff', -298_000, 27_722_820],
            ['Salary for your driver', -888_060, 28_020_820],
            ['Sponsor money', 1_000_000, 28_908_880],
        ];
        foreach ($lines as [$description, $amount, $after]) {
            $this->ledger->save([
                'user_id' => self::USER, 'season' => self::SEASON, 'race' => 1, 'source' => 'economy',
                'occurred_at' => 'Jun 11th 2024, 23:06:43', 'description' => $description, 'amount' => $amount,
                'balance_after' => $after, 'fingerprint' => sha1('economy|' . $description . $after),
            ]);
        }

        $this->service->forCockpit(self::USER, $this->cockpit());

        self::assertSame(27_908_880, $this->budgets->find(self::USER, self::SEASON)['plan']['season_start'] ?? null);
    }

    public function testUnknownOutlookKeepsTheP12aCashLineAndSafetyCtr(): void
    {
        $lines = $this->service->forCockpit(99, $this->cockpit(['menu' => ['cash' => 1_000_000]]))['lines'];
        $byKey = array_column($lines, null, 'key');

        self::assertSame('Costs unknown — sync once after a race', $byKey['cash']['answer']);
        self::assertStringEndsWith('season costs unknown', $byKey['ctr']['reason']);
        self::assertNull($this->budgets->find(99, self::SEASON));
    }

    public function testUnknownNextRaceWearGivesNoPlan(): void
    {
        $this->seedPromotionFixture();

        $lines = $this->service->forCockpit(self::USER, $this->cockpit(['season_calendar' => [
            ['race' => 9, 'track_name' => 'Testring'],
            ['race' => 10, 'track_name' => 'Nowhere'],
            ['race' => 11, 'track_name' => 'Testring'],
        ]]))['lines'];

        self::assertStringEndsWith('season costs unknown', array_column($lines, null, 'key')['ctr']['reason']);
        self::assertNull($this->budgets->find(self::USER, self::SEASON));
    }

    public function testNextRaceBaseMissingAPartGivesNoPlan(): void
    {
        $this->seedPromotionFixture();
        $parts = $this->cockpit()['wear']['parts'];
        $parts['Phantom'] = ['level' => 5, 'start' => 10, 'est' => 15.0, 'end' => 25.0, 'track_base' => 30.0];

        $lines = $this->service->forCockpit(self::USER, $this->cockpit([
            'wear' => ['track_name' => 'Testring', 'laps' => 50, 'parts' => $parts],
        ]))['lines'];

        self::assertStringEndsWith('season costs unknown', array_column($lines, null, 'key')['ctr']['reason']);
        self::assertNull($this->budgets->find(self::USER, self::SEASON));
    }

    public function testUnderAPlanTheDiscretionaryLinesIgnoreTheCurrentCtr(): void
    {
        $this->seedPromotionFixture();
        $this->seedWearTracks();
        $this->movement('Testing session 1', -150_000, 8);
        // At the current CTR 30 the Cockpit calls the Engine risky; at 60 and 100 it forces it; at 0 neither.
        $parts = [
            'Engine' => ['level' => 1, 'start' => 60, 'est' => 0.0, 'end' => 0.0, 'track_base' => 8.0],
            'Brakes' => ['level' => 1, 'start' => 10, 'est' => 0.0, 'end' => 0.0, 'track_base' => 8.0],
        ];
        $linesAt = function (int $ctr, int $trainingCost) use ($parts): array {
            $lines = $this->service->forCockpit(self::USER, $this->cockpit([
                'ctr' => $ctr,
                'car' => $this->pricedCar(200_000),
                'season_calendar' => [['race' => 10, 'track_name' => 'Gamma'], ['race' => 11, 'track_name' => 'Gamma']],
                'track_name' => 'Gamma',
                'wear' => ['track_name' => 'Gamma', 'laps' => 50, 'parts' => $parts],
                'wear_advice' => $this->adviceAt($ctr, $parts),
                'training_picks' => [['name' => 'Talent course', 'score' => 1.0, 'cost' => $trainingCost, 'gains' => []]],
                'testing_open' => true,
                'testing_track' => 'Silverstone',
                'testing_targets' => [[
                    'offset' => 1, 'season' => 'current', 'race' => 11, 'track_id' => 1, 'track_name' => 'Gamma',
                    'power' => 1, 'handling' => 1, 'acceleration' => 1,
                ]],
            ]))['lines'];
            $byKey = array_column($lines, null, 'key');

            return [$byKey['parts'], $byKey['training'], $byKey['testing']];
        };

        $linesAt(0, 0);
        $plan = $this->budgets->find(self::USER, self::SEASON)['plan'] ?? [];
        self::assertSame('push', $plan['mode'] ?? null);
        self::assertSame(['Engine'], array_column($this->adviceAt(30, $parts)['risky'], 'part'));
        // Training takes all but $100k of the envelope, so a risky Engine charged on top would push it out.
        $trainingCost = (int) $plan['envelope'] - 100_000;

        $seen = [];
        foreach ([0, 30, 60, 100] as $ctr) {
            $seen[$ctr] = $linesAt($ctr, $trainingCost);
        }

        self::assertSame('Train Talent course', $seen[0][1]['answer']);
        foreach ($seen as $lines) {
            self::assertSame($seen[0], $lines);
        }
    }

    public function testPlanSavesTheUpgradeRanking(): void
    {
        $this->seedPromotionFixture();
        $this->seedWearTracks();

        $this->service->forCockpit(self::USER, $this->upgradeCockpit(30));
        $plan = $this->budgets->find(self::USER, self::SEASON)['plan'] ?? [];

        self::assertArrayHasKey('upgrades', $plan);
        self::assertSame(['Engine', 'Brakes'], array_column($plan['upgrades'], 'part'));
        self::assertSame(1, $plan['upgrades'][0]['from']);
        self::assertSame(2, $plan['upgrades'][0]['to']);
        self::assertSame(1, $plan['v']);
    }

    public function testTheUpgradesLineAgreesWithTheSavedRanking(): void
    {
        $this->seedPromotionFixture();
        $this->seedWearTracks();

        $lines = $this->service->forCockpit(self::USER, $this->upgradeCockpit(30))['lines'];
        $plan = $this->budgets->find(self::USER, self::SEASON)['plan'] ?? [];

        $line = array_column($lines, null, 'key')['upgrades'] ?? null;
        self::assertNotNull($line);
        $affordable = array_values(array_filter($plan['upgrades'] ?? [], static fn (array $u): bool => $u['affordable']));
        self::assertNotSame([], $affordable, 'the fixture must leave an upgrade that fits');
        self::assertSame('Upgrade ' . $affordable[0]['part'] . ' to L' . $affordable[0]['to'], $line['answer']);
    }

    public function testAnInfeasiblePlanForecastSaysThePlanRunsShortNotThatNothingAddsPha(): void
    {
        $this->seedPromotionFixture();
        $this->seedWearTracks();
        // At Gamma a fresh engine wears past the limit inside one race, even at CTR 0.
        $this->db->exec("UPDATE tracks SET wear_engine = 120.0 WHERE name = 'Gamma'");

        $lines = $this->service->forCockpit(self::USER, $this->upgradeCockpit(30))['lines'];
        $plan = $this->budgets->find(self::USER, self::SEASON)['plan'] ?? [];
        $line = array_column($lines, null, 'key')['upgrades'] ?? null;

        self::assertTrue($plan['infeasible'] ?? null);
        self::assertSame([], $plan['upgrades'] ?? null);
        self::assertNotNull($line);
        self::assertSame('No upgrade fits the budget', $line['answer']);
        self::assertSame('The season plan already runs short', $line['reason']);
    }

    public function testWholeCardIsIdenticalAtAnyCurrentCtr(): void
    {
        $this->seedPromotionFixture();
        $this->seedWearTracks();
        $this->movement('Testing session 1', -150_000, 8);

        $cardAt = function (int $ctr): array {
            $cockpit = $this->upgradeCockpit($ctr);
            $cockpit['wear_advice'] = $this->adviceAt($ctr, $cockpit['wear']['parts']);

            return $this->service->forCockpit(self::USER, $cockpit)['lines'];
        };

        // The CTR line's figure is the one place the current CTR is meant to show ("On N now").
        $withoutCurrentCtr = static function (array $lines, int $ctr): array {
            foreach ($lines as &$line) {
                if ($line['key'] === 'ctr') {
                    self::assertSame('On ' . $ctr . ' now', $line['figure']);
                    $line['figure'] = null;
                }
            }

            return $lines;
        };
        $low = $withoutCurrentCtr($cardAt(0), 0);
        $high = $withoutCurrentCtr($cardAt(80), 80);

        self::assertContains('upgrades', array_column($low, 'key'));
        self::assertSame($low, $high);
    }

    public function testRankerFailureDropsOnlyTheUpgradesLine(): void
    {
        $this->seedPromotionFixture();
        $this->seedWearTracks();
        $baseline = array_column($this->service->forCockpit(self::USER, $this->upgradeCockpit(30))['lines'], 'key');
        self::assertContains('upgrades', $baseline);

        $wear = new CarWearService($this->db, [
            'driver_wear_factors' => ['concentration' => 1.0, 'talent' => 1.0, 'experience' => 1.0],
            'part_level_factors' => [1 => 1.05] + array_fill_keys(range(2, 11), 1.0),
        ]);
        $throwing = new readonly class (new SeasonBudget($wear), []) extends UpgradeRanker {
            public function rank(
                array $plan,
                string $objective,
                array $rounds,
                array $demands,
                array $parts,
                array $prices,
                array $options,
                float $driverFactor,
                int $cash,
                int $committed,
                int $income,
                int $spent,
            ): array {
                throw new \RuntimeException('secret detail');
            }
        };
        $service = $this->engineer(null, $throwing);

        $log = tempnam(sys_get_temp_dir(), 'engineer-log');
        self::assertIsString($log);
        $prior = ini_set('error_log', $log);
        try {
            $lines = $service->forCockpit(self::USER, $this->upgradeCockpit(30))['lines'];
        } finally {
            ini_set('error_log', $prior === false ? '' : $prior);
        }
        $logged = (string) file_get_contents($log);
        unlink($log);

        self::assertSame(array_values(array_diff($baseline, ['upgrades'])), array_column($lines, 'key'));
        self::assertSame(1, substr_count($logged, '[Engineer] '));
        self::assertStringContainsString('[Engineer] RuntimeException', $logged);
        self::assertStringNotContainsString('secret', $logged);
        $plan = $this->budgets->find(self::USER, self::SEASON)['plan'] ?? [];
        self::assertArrayNotHasKey('upgrades', $plan);
        self::assertSame(10, $plan['race']);
    }

    public function testStaffNudgeFromGroupStaff(): void
    {
        $this->seedPromotionFixture();
        $this->service->pin(self::USER, self::SEASON, 'promote');

        $lines = $this->service->forCockpit(self::USER, $this->cockpit([
            'group_staff' => $this->groupStaff(40, 20),
        ]))['lines'];
        $staff = array_column($lines, null, 'key')['staff'] ?? null;

        self::assertNotNull($staff);
        self::assertSame('Staff and facilities trail your group', $staff['answer']);
        self::assertStringContainsString('20th of 40', $staff['reason']);
    }

    public function testNoNudgeWhenGroupStaffIsEmpty(): void
    {
        $this->seedPromotionFixture();
        $this->service->pin(self::USER, self::SEASON, 'promote');

        foreach ([[], ['managers' => []]] as $groupStaff) {
            $lines = $this->service->forCockpit(self::USER, $this->cockpit(['group_staff' => $groupStaff]))['lines'];
            self::assertNotContains('staff', array_column($lines, 'key'));
        }
        $lines = $this->service->forCockpit(self::USER, $this->cockpit())['lines'];
        self::assertNotContains('staff', array_column($lines, 'key'));
    }

    /** @return array{managers: list<array<string, int>>} staff OA falls with rank; the manager is at $ownRank */
    private function groupStaff(int $size, int $ownRank): array
    {
        $managers = [];
        for ($rank = 1; $rank <= $size; $rank++) {
            $managers[] = ['IDM' => $rank === $ownRank ? 7 : 100 + $rank, 'staffOA' => 1000 - $rank * 10];
        }

        return ['managers' => $managers];
    }

    /**
     * A cockpit with a priced car that offers Engine and Brakes one level up, a two-race calendar with
     * known PHA demand, and level 1 parts.
     *
     * @return array<string, mixed>
     */
    private function upgradeCockpit(int $ctr): array
    {
        $car = $this->pricedCar(200_000);
        foreach (['Engine' => 300_000, 'Brakes' => 150_000] as $label => $price) {
            $options = CarWearService::PARTS_MAP[$label]['options'];
            $car[$options][] = ['value' => ['value' => 1, 'cost' => $price], 'newLvl' => '2'];
        }
        $demand = ['power' => 5, 'handling' => 3, 'acceleration' => 2];

        return $this->cockpit([
            'ctr' => $ctr,
            'car' => $car,
            'season_calendar' => [
                ['race' => 10, 'track_name' => 'Gamma'] + $demand,
                ['race' => 11, 'track_name' => 'Gamma'] + $demand,
            ],
            'track_name' => 'Gamma',
            'wear' => ['track_name' => 'Gamma', 'laps' => 50, 'parts' => [
                'Engine' => ['level' => 1, 'start' => 10, 'est' => 0.0, 'end' => 0.0, 'track_base' => 8.0],
                'Brakes' => ['level' => 1, 'start' => 10, 'est' => 0.0, 'end' => 0.0, 'track_base' => 8.0],
            ]],
        ]);
    }

    /**
     * The Cockpit's wear advice at the given CTR, the way WearAdvisorService buckets it.
     *
     * @param array<string, array{level: int, start: int, track_base: float}> $parts
     * @return array{swap: list<array<string, mixed>>, risky: list<array<string, mixed>>, watch: list<mixed>, headline: string}
     */
    private function adviceAt(int $ctr, array $parts): array
    {
        $advice = ['swap' => [], 'risky' => [], 'watch' => [], 'headline' => ''];
        foreach ($parts as $label => $part) {
            $est = round($part['track_base'] * 1.05 ** $ctr, 1);
            $end = $part['start'] + $est;
            $row = ['part' => $label, 'level' => $part['level'], 'start' => $part['start'], 'est' => $est, 'end' => $end];
            if ($end >= 100) {
                $advice['swap'][] = $row;
            } elseif ($end >= 90) {
                $advice['risky'][] = $row;
            }
        }

        return $advice;
    }

    public function testUnderANursePlanTheOptionalSwapsAreThePartsInTheRedAtThePlanCtr(): void
    {
        $this->seedPromotionFixture();
        $this->seedWearTracks();
        // The Engine caps the CTR and has no price, so there is no push; at that CTR the Brakes end in the red.
        $parts = [
            'Engine' => ['level' => 1, 'start' => 60, 'est' => 0.0, 'end' => 0.0, 'track_base' => 8.0],
            'Brakes' => ['level' => 1, 'start' => 55, 'est' => 0.0, 'end' => 0.0, 'track_base' => 8.0],
        ];
        $car = $this->pricedCar(200_000);
        $car[CarWearService::PARTS_MAP['Engine']['options']] = [];
        $partsLineAt = function (int $ctr) use ($parts, $car): array {
            $lines = $this->service->forCockpit(self::USER, $this->cockpit([
                'ctr' => $ctr,
                'car' => $car,
                'season_calendar' => [['race' => 10, 'track_name' => 'Gamma'], ['race' => 11, 'track_name' => 'Gamma']],
                'track_name' => 'Gamma',
                'wear' => ['track_name' => 'Gamma', 'laps' => 50, 'parts' => $parts],
                'wear_advice' => $this->adviceAt($ctr, $parts),
            ]))['lines'];

            return array_column($lines, null, 'key')['parts'];
        };

        $seen = [];
        foreach ([0, 30, 60, 100] as $ctr) {
            $seen[$ctr] = $partsLineAt($ctr);
        }

        self::assertSame('nurse', $this->budgets->find(self::USER, self::SEASON)['plan']['mode'] ?? null);
        self::assertSame('Swap Brakes (optional)', $seen[0]['answer']);
        foreach ($seen as $line) {
            self::assertSame($seen[0], $line);
        }
    }

    public function testASurvivePlanCallsARedZoneSwapARedFinishNotABreak(): void
    {
        $this->seedPromotionFixture();
        $this->seedWearTracks();
        $this->service->pin(self::USER, self::SEASON, 'survive');
        // Engine 85 ends a race at 93 even at CTR 0: past Survive's 90, short of the 100 that breaks it.
        $parts = [
            'Engine' => ['level' => 1, 'start' => 85, 'est' => 0.0, 'end' => 0.0, 'track_base' => 8.0],
            'Brakes' => ['level' => 1, 'start' => 10, 'est' => 0.0, 'end' => 0.0, 'track_base' => 8.0],
        ];

        $lines = $this->service->forCockpit(self::USER, $this->cockpit([
            'ctr' => 0,
            'car' => $this->pricedCar(100_000),
            'season_calendar' => [['race' => 10, 'track_name' => 'Gamma'], ['race' => 11, 'track_name' => 'Gamma']],
            'track_name' => 'Gamma',
            'wear' => ['track_name' => 'Gamma', 'laps' => 50, 'parts' => $parts],
            'wear_advice' => $this->adviceAt(0, $parts),
        ]))['lines'];
        $byKey = array_column($lines, null, 'key');

        self::assertNotNull($this->budgets->find(self::USER, self::SEASON));
        self::assertSame('Swap Engine', $byKey['parts']['answer']);
        self::assertSame('Would finish in the red if kept', $byKey['parts']['reason']);
        self::assertStringNotContainsString('stays in the red', $byKey['ctr']['reason']);
    }

    public function testACalendarGapAtTheNextRaceGivesNoPlan(): void
    {
        $this->seedPromotionFixture();

        $lines = $this->service->forCockpit(self::USER, $this->cockpit(['season_calendar' => [
            ['race' => 9, 'track_name' => 'Testring'],
            ['race' => 11, 'track_name' => 'Testring'],
            ['race' => 12, 'track_name' => 'Testring'],
        ]]))['lines'];

        self::assertStringEndsWith('season costs unknown', array_column($lines, null, 'key')['ctr']['reason']);
        self::assertNull($this->budgets->find(self::USER, self::SEASON));
    }

    public function testAFailedPlanSaveIsLoggedAndTheLinesStillCome(): void
    {
        $this->seedPromotionFixture();
        $failing = new class ($this->db) extends SeasonBudgetRepository {
            public function save(int $userId, int $season, int $race, array $plan): void
            {
                throw new \PDOException('database is locked');
            }
        };
        $service = $this->engineer($failing);
        $log = tempnam(sys_get_temp_dir(), 'engineer-log');
        self::assertIsString($log);
        $prior = ini_set('error_log', $log);

        try {
            $lines = $service->forCockpit(self::USER, $this->cockpit())['lines'];
        } finally {
            ini_set('error_log', $prior === false ? '' : $prior);
        }
        $logged = (string) file_get_contents($log);
        unlink($log);

        self::assertSame('Safe to spend', array_column($lines, null, 'key')['cash']['answer']);
        self::assertStringContainsString('[Engineer] PDOException', $logged);
        self::assertStringNotContainsString('locked', $logged);
    }

    /** @return array{swap: list<array<string, mixed>>, risky: list<mixed>, watch: list<mixed>, headline: string} */
    private function swapAt100(): array
    {
        return [
            'swap' => [['part' => 'Engine', 'level' => 5, 'start' => 60, 'est' => 50.0, 'end' => 110.0]],
            'risky' => [], 'watch' => [], 'headline' => '',
        ];
    }

    /** @return array{swap: list<array<string, mixed>>, risky: list<mixed>, watch: list<mixed>, headline: string} */
    private function noSwap(): array
    {
        return ['swap' => [], 'risky' => [], 'watch' => [], 'headline' => ''];
    }

    /**
     * @param array<string, mixed> $advice
     * @return array<string, mixed>
     */
    private function surviveCtrLine(int $ctr, array $advice): array
    {
        $lines = $this->service->forCockpit(self::USER, $this->cockpit([
            'ctr' => $ctr,
            'wear' => ['track_name' => 'Testring', 'laps' => 50, 'parts' => [
                'Engine' => ['level' => 1, 'start' => 60, 'est' => 0.0, 'end' => 0.0, 'track_base' => 8.0],
                'Brakes' => ['level' => 1, 'start' => 10, 'est' => 0.0, 'end' => 0.0, 'track_base' => 3.0],
            ]],
            'wear_advice' => $advice,
        ]))['lines'];

        return array_column($lines, null, 'key')['ctr'];
    }

    /** @param array<string, mixed> $overrides */
    private function cockpit(array $overrides = []): array
    {
        return array_merge([
            'office' => ['seasonNb' => self::SEASON, 'raceNb' => 10],
            'menu' => ['IDM' => 7, 'cash' => 5_000_000],
            'money_levels' => ['managers' => [['IDM' => 7, 'carLevelRank' => 2]]],
            'division' => 'Amateur',
            'car' => [],
            'driver' => ['concentration' => 100, 'talent' => 90, 'experience' => 80],
            'wear' => ['track_name' => 'Testring', 'laps' => 50, 'parts' => [
                'Engine' => ['level' => 5, 'start' => 10, 'est' => 20.0, 'end' => 30.0, 'track_base' => 40.0],
                'Brakes' => ['level' => 5, 'start' => 10, 'est' => 15.0, 'end' => 25.0, 'track_base' => 30.0],
            ]],
            'wear_advice' => ['swap' => [], 'risky' => [], 'watch' => [], 'headline' => ''],
            'swap_plan' => null,
            'training_picks' => [],
            'testing_targets' => [],
            'testing_open' => false,
            'season_calendar' => [
                ['race' => 9, 'track_name' => 'Testring'],
                ['race' => 10, 'track_name' => 'Testring'],
            ],
            'track_name' => 'Testring',
            'weather' => ['q1_wet' => false, 'q2_wet' => false, 'race_rain_avg' => 0.0, 'race_start_wet' => false],
            'setup_stale' => false,
            'ctr' => 30,
        ], $overrides);
    }

    private function seedWearTracks(): void
    {
        $columns = array_column(CarWearService::PARTS_MAP, 'db');
        $insert = $this->db->prepare(
            'INSERT INTO tracks (name, laps, distance, ' . implode(', ', $columns) . ')
             VALUES (:name, 50, 250.0, ' . implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)) . ')'
        );
        $heavy = ['Alpha' => 'wear_engine', 'Beta' => 'wear_brakes'];
        foreach (['Alpha', 'Beta', 'Gamma'] as $name) {
            $params = [':name' => $name];
            foreach ($columns as $column) {
                $params[':' . $column] = match (true) {
                    ($heavy[$name] ?? null) === $column => 20.0,
                    isset($heavy[$name]) => 5.0,
                    default => 8.0,
                };
            }
            $insert->execute($params);
        }
    }

    /** @return array<string, mixed> level 1 parts, an engine ten times the price of any other */
    private function pricedCar(int $enginePrice = 10_000_000): array
    {
        $car = [];
        foreach (CarWearService::PARTS_MAP as $label => $map) {
            $car[$map['lvl']] = 1;
            $car[$map['wear']] = 0;
            $car[$map['options']] = [
                ['value' => ['value' => 1, 'cost' => $label === 'Engine' ? $enginePrice : 1_000_000], 'newLvl' => '1'],
            ];
        }

        return $car;
    }

    private function seedPromotionFixture(): void
    {
        $this->standing(9, 2, 60);
        $this->weekends->save([
            'user_id' => self::USER, 'season' => self::SEASON, 'race' => 9, 'source' => 'snapshot',
            'driver_json' => json_encode(['salary' => 100_000], JSON_THROW_ON_ERROR),
            'td_json' => null,
            'staff_json' => json_encode(['salary' => 50_000], JSON_THROW_ON_ERROR),
            'facilities_json' => null, 'car_json' => null,
            'cash' => 5_000_000, 'pts' => 60, 'pos' => 2,
        ]);
        foreach ([8, 9] as $race) {
            $this->movement('Sponsor Acme', 300_000, $race);
            $this->movement('Race ' . $race . ' finish position', 100_000, $race);
            $this->movement('Facilities upkeep', -50_000, $race);
            $this->movement('Tyre supplier', -20_000, $race);
        }
    }

    private function standing(int $race, int $pos, int $pts): void
    {
        $managers = [];
        for ($i = 1; $i <= 28; $i++) {
            $managers[] = [
                'pos' => $i,
                'pts' => $i === $pos ? $pts : max(0, 70 - $i * 2),
                'manBgColor' => match (true) {
                    $i <= 3 => '#007700',
                    $i >= 26 => '#990000',
                    default => '#ffffff',
                },
            ];
        }
        $this->standings->save([
            'user_id' => self::USER, 'season' => self::SEASON, 'race' => $race,
            'own_pos' => $pos, 'own_pts' => $pts, 'group_label' => 'Amateur - 4',
            'managers_json' => json_encode($managers, JSON_THROW_ON_ERROR),
        ]);
    }

    private function movement(string $description, int $amount, int $race): void
    {
        $at = sprintf('2026-10-%02d 12:00:00', $race);
        $this->ledger->save([
            'user_id' => self::USER, 'season' => self::SEASON, 'race' => $race, 'source' => 'economy',
            'occurred_at' => $at, 'description' => $description, 'amount' => $amount,
            'balance_after' => null, 'fingerprint' => sha1($description . $race . $amount),
        ]);
    }
}
