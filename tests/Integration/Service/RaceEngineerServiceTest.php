<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Cache\Adapter\NullCache;
use App\Database\DatabaseSeeder;
use App\Engineer\CtrCeiling;
use App\Engineer\CtrPlanner;
use App\Engineer\SeasonBudget;
use App\Engineer\SwapLevelPicker;
use App\Engineer\SwapSeason;
use App\Engineer\UpgradeRanker;
use App\Repository\EconomyLedgerRepository;
use App\Repository\RaceHistoryRepository;
use App\Repository\RaceTelemetryRepository;
use App\Repository\SeasonBudgetRepository;
use App\Repository\SeasonObjectiveRepository;
use App\Repository\StandingsRepository;
use App\Repository\TrackRepository;
use App\Repository\WeekendStateRepository;
use App\Security\ApiTokenCrypto;
use App\Service\BoostFuelService;
use App\Service\CarWearPlannerService;
use App\Service\CarWearService;
use App\Service\CtrModelService;
use App\Service\FuelModel;
use App\Service\IdealPilotService;
use App\Service\PartSwapAdvisorService;
use App\Service\PartUpgradeAdvisorService;
use App\Service\PhaMatchService;
use App\Service\RaceEngineerService;
use App\Service\RiskAdvisorService;
use App\Service\WearAdvisorService;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RaceEngineerService::class)]
final class RaceEngineerServiceTest extends TestCase
{
    private const int SEASON = 112;
    private const int USER = 1;
    private const float CTR_GAIN_FRACTION = 0.001;
    /** With no energy corpus the cap is the division's estimate, which sits below these fixtures' wear ceiling. */
    private const string AMATEUR_ENERGY_REASON = "Capped at 35: a rough Amateur estimate of what your driver's energy allows";

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

    private function wearService(): CarWearService
    {
        return new CarWearService($this->db, [
            'driver_wear_factors' => ['concentration' => 1.0, 'talent' => 1.0, 'experience' => 1.0],
            'part_level_factors' => [1 => 1.05] + array_fill_keys(range(2, 11), 1.0),
        ]);
    }

    private function engineer(
        ?SeasonBudgetRepository $budgets = null,
        ?UpgradeRanker $ranker = null,
        ?SwapLevelPicker $picker = null,
        ?RaceTelemetryRepository $telemetry = null,
    ): RaceEngineerService {
        $wear = $this->wearService();
        $contribution = [
            'Engine' => ['power' => 3, 'handling' => 1, 'acceleration' => 1],
            'Brakes' => ['power' => 0, 'handling' => 1, 'acceleration' => 2],
        ];

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
            $ranker ?? new UpgradeRanker(new SeasonBudget($wear), $contribution),
            new PartSwapAdvisorService(
                $wear,
                new PhaMatchService(),
                new PartUpgradeAdvisorService(new PhaMatchService(), $contribution),
                $picker ?? new SwapLevelPicker(new SeasonBudget($wear), $contribution),
            ),
            $telemetry ?? new RaceTelemetryRepository($this->db),
            $this->idealPilot(193),
            new CtrModelService(new RaceTelemetryRepository($this->db), new RaceHistoryRepository($this->db), new NullCache()),
            self::CTR_GAIN_FRACTION,
        );
    }

    private function idealPilot(int $talent): IdealPilotService
    {
        return new class ($talent) extends IdealPilotService {
            public function __construct(private readonly int $talent)
            {
            }

            public function getIdealPilot(string $division): array
            {
                return ['stats' => ['Talent' => $this->talent], 'count' => 1];
            }
        };
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

    public function testOfficeCashWinsOverTheMenus(): void
    {
        $this->seedPromotionFixture();
        $cashLine = fn (array $overrides): array
            => array_column($this->service->forCockpit(self::USER, $this->cockpit($overrides))['lines'], null, 'key')['cash'];

        $fromOffice = $cashLine([
            'office' => ['seasonNb' => self::SEASON, 'raceNb' => 10, 'cash' => 9_000_000],
            'menu' => ['IDM' => 7, 'cash' => 5_000_000],
        ]);

        self::assertSame($cashLine(['menu' => ['IDM' => 7, 'cash' => 9_000_000]]), $fromOffice);
        self::assertNotSame($cashLine(['menu' => ['IDM' => 7, 'cash' => 5_000_000]]), $fromOffice);
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

        self::assertTrue($this->service->pin(self::USER, self::SEASON - 1, 'stay'));
        self::assertFalse($this->service->forCockpit(self::USER, $this->cockpit())['objective']['pinned']);

        self::assertTrue($this->service->pin(self::USER, self::SEASON, 'stay'));
        $card = $this->service->forCockpit(self::USER, $this->cockpit());
        self::assertTrue($card['objective']['pinned']);
        self::assertSame('stay', $card['objective']['objective']);
        self::assertSame('promote', $card['objective']['proposed']);
    }

    public function testTheOldObjectivesPinAsStay(): void
    {
        self::assertTrue($this->service->pin(self::USER, self::SEASON, 'survive'));
        self::assertSame('stay', $this->pins->find(self::USER, self::SEASON));

        $this->pins->clear(self::USER, self::SEASON);
        self::assertTrue($this->service->pin(self::USER, self::SEASON, 'hold'));
        self::assertSame('stay', $this->pins->find(self::USER, self::SEASON));
    }

    public function testARookieIsAlwaysProposedPromote(): void
    {
        $this->standing(9, 27, 5);

        $rookie = $this->service->forCockpit(self::USER, $this->cockpit(['division' => 'Rookie']))['objective'];
        $amateur = $this->service->forCockpit(self::USER, $this->cockpit(['division' => 'Amateur']))['objective'];

        self::assertSame('promote', $rookie['objective']);
        self::assertSame('stay', $amateur['objective']);
    }

    public function testPinValues(): void
    {
        self::assertTrue($this->service->pin(self::USER, self::SEASON, 'stay'));
        self::assertSame('stay', $this->pins->find(self::USER, self::SEASON));

        self::assertFalse($this->service->pin(self::USER, self::SEASON, 'bogus'));
        self::assertSame('stay', $this->pins->find(self::USER, self::SEASON));

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

        // Master's energy estimate (65) sits above the wear ceiling, so the ceiling is what the line shows.
        $lines = $this->service->forCockpit(99, $this->cockpit([
            'ctr' => 100,
            'division' => 'Master',
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
        $this->service->pin(self::USER, self::SEASON, 'stay');
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

    public function testWithoutAnOutlookStayAdvisesTheBreakCeilingAndHoldsStill(): void
    {
        $parts = [
            'Engine' => ['level' => 1, 'start' => 85, 'est' => 0.0, 'end' => 0.0, 'track_base' => 6.0],
            'Gearbox' => ['level' => 1, 'start' => 10, 'est' => 0.0, 'end' => 0.0, 'track_base' => 6.0],
        ];
        // At CTR 100 the advisor forces the Engine swap; at the advised CTR it ends in the red, which is safe.
        $forcedAt100 = ['swap' => [['part' => 'Engine', 'level' => 1, 'start' => 85, 'est' => 40.7, 'end' => 125.7]], 'risky' => [], 'watch' => [], 'headline' => ''];
        $riskyOnly = ['swap' => [], 'risky' => [['part' => 'Engine', 'level' => 1, 'start' => 85, 'est' => 14.0, 'end' => 99.0]], 'watch' => [], 'headline' => ''];
        $ctrLine = function (int $ctr, array $advice) use ($parts): array {
            $lines = $this->service->forCockpit(99, $this->cockpit([
                'ctr' => $ctr,
                'wear' => ['track_name' => 'Testring', 'laps' => 50, 'parts' => $parts],
                'wear_advice' => $advice,
            ]))['lines'];

            return array_column($lines, null, 'key')['ctr'];
        };

        $this->service->pin(99, self::SEASON, 'promote');
        $breakCeiling = (int) substr($ctrLine(100, $forcedAt100)['answer'], 4);
        $this->service->pin(99, self::SEASON, 'stay');
        $stay = $ctrLine(100, $forcedAt100);
        $target = (int) substr($stay['answer'], 4);
        $followed = $ctrLine($target, $riskyOnly);

        self::assertLessThan(100, $breakCeiling);
        self::assertSame($breakCeiling, $target);
        self::assertSame('the highest CTR where no part breaks; season costs unknown', $stay['reason']);
        self::assertSame($stay['answer'], $followed['answer']);
        self::assertSame($stay['reason'], $followed['reason']);
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
        // The rating only shows below the energy cap: a worn engine keeps the wear ceiling under it, and a manager
        // with no stored costs (no plan) keeps the line on the wear ceiling.
        $reasonFor = function (int $raceNb) use ($calendar): string {
            $lines = $this->service->forCockpit(99, $this->cockpit([
                'office' => ['seasonNb' => self::SEASON, 'raceNb' => $raceNb],
                'car' => $this->pricedCar(),
                'season_calendar' => $calendar,
                'wear' => ['track_name' => 'Testring', 'laps' => 50, 'parts' => [
                    'Engine' => ['level' => 1, 'start' => 80, 'est' => 0.0, 'end' => 0.0, 'track_base' => 8.0],
                    'Brakes' => ['level' => 1, 'start' => 10, 'est' => 0.0, 'end' => 0.0, 'track_base' => 3.0],
                ]],
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

    public function testATestingSessionAlwaysCostsOneMillion(): void
    {
        $this->seedPromotionFixture();
        $testingAfter = function (): array {
            $lines = $this->service->forCockpit(self::USER, $this->cockpit([
                'testing_open' => true,
                'testing_track' => 'Silverstone',
                'testing_targets' => [[
                    'offset' => 3, 'season' => 'current', 'race' => 13, 'track_id' => 1, 'track_name' => 'Testring',
                    'power' => 1, 'handling' => 1, 'acceleration' => 1,
                ]],
            ]))['lines'];

            return array_column($lines, null, 'key')['testing'];
        };

        $firstOfTheSeason = $testingAfter();
        $this->movement('Testing session 1', -80_000, 8);
        $afterACheaperEntry = $testingAfter();

        self::assertSame('$1M', $firstOfTheSeason['figure']);
        self::assertStringNotContainsString('unknown', $firstOfTheSeason['answer'] . $firstOfTheSeason['reason']);
        self::assertSame($firstOfTheSeason, $afterACheaperEntry);
    }

    public function testASkippedSessionNamesTheCockpitTrainingLaps(): void
    {
        $this->seedPromotionFixture();
        $testingWith = function (int $laps): array {
            $lines = $this->service->forCockpit(self::USER, $this->cockpit([
                // Broke: no session fits, so the line skips it.
                'office' => ['seasonNb' => self::SEASON, 'raceNb' => 10, 'cash' => 0],
                'menu' => ['IDM' => 7, 'cash' => 0],
                'training_laps' => $laps,
                'testing_open' => true,
                'testing_track' => 'Silverstone',
                'testing_targets' => [[
                    'offset' => 3, 'season' => 'current', 'race' => 13, 'track_id' => 1, 'track_name' => 'Testring',
                    'power' => 1, 'handling' => 1, 'acceleration' => 1,
                ]],
            ]))['lines'];

            return array_column($lines, null, 'key')['testing'];
        };

        $counted = $testingWith(100);
        $none = $testingWith(0);

        self::assertSame('Skip testing at Silverstone', $counted['answer']);
        self::assertStringEndsWith('(it counts 100 laps of wear)', $counted['reason']);
        self::assertSame('/cockpit#cockpit-wear', $counted['anchor']);
        self::assertStringNotContainsString('training laps', $none['reason']);
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

    public function testSeasonOverGivesNoTyrePanel(): void
    {
        $this->seedPromotionFixture();
        $this->seedTyreFixture();
        $this->standing(17, 2, 120);

        $card = $this->service->forCockpit(self::USER, $this->tyreCockpit([
            'office' => ['seasonNb' => self::SEASON, 'raceNb' => 18, 'tyreSupplierId' => 0],
        ]));

        self::assertTrue($card['objective']['over']);
        self::assertNull($card['tyres']);
    }

    public function testAdviceIgnoresTheCurrentCtr(): void
    {
        $this->seedPromotionFixture();
        $this->service->pin(self::USER, self::SEASON, 'stay');

        // The Cockpit's own advice changes with the CTR: at 100 it forces the Engine swap, lower it doesn't.
        $lines = [];
        foreach ([0, 30, 60, 100] as $ctr) {
            $lines[$ctr] = $this->stayCtrLine($ctr, $ctr === 100 ? $this->swapAt100() : $this->noSwap());
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
        $this->service->pin(self::USER, self::SEASON, 'stay');

        $first = $this->stayCtrLine(100, $this->swapAt100());
        $target = (int) substr($first['answer'], 4);
        self::assertLessThan(100, $target);
        self::assertSame('Use ' . $target, $first['action']['label'] ?? null);

        $followed = $this->stayCtrLine($target, $this->noSwap());

        self::assertSame($first['answer'], $followed['answer']);
        self::assertSame($first['reason'], $followed['reason']);
        self::assertNull($followed['action']);
    }

    public function testStaleDataGivesOneLineAndSavesNothing(): void
    {
        $this->seedPromotionFixture();
        $this->service->forCockpit(self::USER, $this->cockpit());
        $before = $this->budgets->latest(self::USER);
        self::assertNotNull($before);
        $this->pins->save(self::USER, self::SEASON, 'stay');

        $log = tempnam(sys_get_temp_dir(), 'engineer-log');
        self::assertIsString($log);
        $prior = ini_set('error_log', $log);
        try {
            $card = $this->service->forCockpit(
                self::USER,
                $this->cockpit(['office' => ['seasonNb' => self::SEASON, 'raceNb' => 11]]),
                stale: true,
            );
            $logged = (string) file_get_contents($log);
        } finally {
            ini_set('error_log', (string) $prior);
            unlink($log);
        }

        self::assertSame([[
            'key' => 'stale',
            'answer' => 'New race weekend',
            'figure' => null,
            'reason' => "Re-sync for this weekend's advice",
            'anchor' => null,
            'tone' => 'amber',
            'action' => null,
            'step' => 'season',
        ]], $card['lines']);
        self::assertSame(self::SEASON, $card['season']);
        self::assertSame($before, $this->budgets->latest(self::USER));
        self::assertSame('stay', $this->pins->find(self::USER, self::SEASON));
        self::assertStringNotContainsString('[Engineer]', $logged);
    }

    public function testFreshDataStillAdvises(): void
    {
        $this->seedPromotionFixture();

        $card = $this->service->forCockpit(self::USER, $this->cockpit(), stale: false);

        self::assertSame(
            ['objective', 'cash', 'parts', 'upgrades', 'training', 'testing', 'ctr', 'risk', 'boost'],
            array_column($card['lines'], 'key'),
        );
    }

    public function testPlanIsSavedForTheSeason(): void
    {
        $this->seedPromotionFixture();

        $this->service->forCockpit(self::USER, $this->cockpit());
        $saved = $this->budgets->find(self::USER, self::SEASON);

        self::assertNotNull($saved);
        self::assertSame(2, $saved['plan']['v']);
        self::assertSame(10, $saved['race']);
        self::assertSame(10, $saved['plan']['race']);
    }

    public function testBeforeTheFirstRaceTheOutlookUsesLastSeasonsFinalRace(): void
    {
        $this->seedPromotionFixture(self::SEASON - 1);

        $this->service->forCockpit(self::USER, $this->cockpit());

        self::assertNotNull($this->budgets->find(self::USER, self::SEASON));

        $this->service->forCockpit(99, $this->cockpit());

        self::assertNull($this->budgets->find(99, self::SEASON));
    }

    public function testSeasonStartComesFromTheLedger(): void
    {
        $this->seedPromotionFixture();
        $this->service->pin(self::USER, self::SEASON, 'stay');
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
        // A worn engine keeps the wear ceiling under the energy cap, so the line shows the safety CTR.
        $lines = $this->service->forCockpit(99, $this->cockpit([
            'menu' => ['cash' => 1_000_000],
            'wear' => ['track_name' => 'Testring', 'laps' => 50, 'parts' => [
                'Engine' => ['level' => 1, 'start' => 80, 'est' => 0.0, 'end' => 0.0, 'track_base' => 8.0],
                'Brakes' => ['level' => 1, 'start' => 10, 'est' => 0.0, 'end' => 0.0, 'track_base' => 3.0],
            ]],
        ]))['lines'];
        $byKey = array_column($lines, null, 'key');

        self::assertSame('Costs unknown — sync once after a race', $byKey['cash']['answer']);
        self::assertSame('CTR 17', $byKey['ctr']['answer']);
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

        self::assertSame(self::AMATEUR_ENERGY_REASON, array_column($lines, null, 'key')['ctr']['reason']);
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

        self::assertSame(self::AMATEUR_ENERGY_REASON, array_column($lines, null, 'key')['ctr']['reason']);
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
        self::assertSame(2, $plan['v']);
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

    public function testAPartEndingInTheRedIsNeverOfferedASwapAtAnyCtr(): void
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
        self::assertSame('Nothing to swap', $seen[0]['answer']);
        foreach ($seen as $line) {
            self::assertSame($seen[0], $line);
        }
    }

    public function testAStayPlanKeepsAPartThatOnlyEndsTheRaceInTheRed(): void
    {
        $this->seedPromotionFixture();
        $this->seedWearTracks();
        $this->service->pin(self::USER, self::SEASON, 'stay');
        // Engine 85 ends a race at 93 even at CTR 0: in the red, but short of 100%, so it stays on the car.
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

        $plan = $this->budgets->find(self::USER, self::SEASON)['plan'] ?? null;
        self::assertNotNull($plan);
        self::assertSame([], $plan['rows'][0]['replaced']);
        self::assertSame('Nothing to swap', $byKey['parts']['answer']);
    }

    public function testACalendarGapAtTheNextRaceGivesNoPlan(): void
    {
        $this->seedPromotionFixture();

        $lines = $this->service->forCockpit(self::USER, $this->cockpit(['season_calendar' => [
            ['race' => 9, 'track_name' => 'Testring'],
            ['race' => 11, 'track_name' => 'Testring'],
            ['race' => 12, 'track_name' => 'Testring'],
        ]]))['lines'];

        self::assertSame(self::AMATEUR_ENERGY_REASON, array_column($lines, null, 'key')['ctr']['reason']);
        self::assertNull($this->budgets->find(self::USER, self::SEASON));
    }

    public function testTheTyreLineShowsOnlyWhileUnsigned(): void
    {
        $this->seedPromotionFixture();
        $this->seedTyreFixture();
        $office = static fn (array $extra): array => ['seasonNb' => self::SEASON, 'raceNb' => 10] + $extra;
        $cases = [
            'unsigned, Pro' => [['office' => $office(['tyreSupplierId' => 0])], true],
            'unsigned, Master' => [['office' => $office(['tyreSupplierId' => 0]), 'division' => 'Master'], true],
            'office without tyreSupplierId' => [['office' => $office([])], false],
            'non-numeric tyreSupplierId' => [['office' => $office(['tyreSupplierId' => 'x'])], false],
            'already signed' => [['office' => $office(['tyreSupplierId' => 4])], false],
            'Amateur' => [['office' => $office(['tyreSupplierId' => 0]), 'division' => 'Amateur'], false],
            'no feed' => [['office' => $office(['tyreSupplierId' => 0]), 'tyre_suppliers' => []], false],
        ];

        foreach ($cases as $case => [$overrides, $shown]) {
            $card = $this->service->forCockpit(self::USER, $this->tyreCockpit($overrides));

            self::assertSame($shown, in_array('tyres', array_column($card['lines'], 'key'), true), $case);
            self::assertArrayHasKey('tyres', $card, $case);
            self::assertSame($shown, $card['tyres'] !== null, $case);
        }
    }

    public function testTheTyreLineSitsRightAfterTheObjectiveAndNamesTheSupplierAndItsPrice(): void
    {
        $this->seedPromotionFixture();
        $this->seedTyreFixture();

        $card = $this->service->forCockpit(self::USER, $this->tyreCockpit());

        self::assertSame(['objective', 'tyres'], array_slice(array_column($card['lines'], 'key'), 0, 2));
        $line = $card['lines'][1];
        $tyres = $card['tyres'];
        self::assertNotNull($tyres);
        self::assertSame('Sign ' . $tyres['pick'], $line['answer']);
        self::assertSame($tyres['figure'], $line['figure']);
        self::assertStringEndsWith(' a race', $tyres['figure']);
        self::assertSame('/engineer#engineer-tyres', $line['anchor']);
    }

    public function testThePanelCarriesPreformattedMoney(): void
    {
        $this->seedPromotionFixture();
        $this->seedTyreFixture();

        $tyres = $this->service->forCockpit(self::USER, $this->tyreCockpit())['tyres'];

        self::assertNotNull($tyres);
        self::assertSame(\App\Engineer\Money::short($tyres['cost']) . ' a race', $tyres['figure']);
        self::assertCount(4, $tyres['rows']);
        foreach ($tyres['rows'] as $row) {
            self::assertSame(\App\Engineer\Money::short($row['cost']), $row['per_race']);
            self::assertSame(\App\Engineer\Money::short($row['season_cost']), $row['season']);
        }
    }

    public function testTheTyreAdviceReadsTheStoredCorpusAndTheCalendarsTracks(): void
    {
        $this->seedPromotionFixture();
        $this->seedTyreFixture();

        $wet = $this->service->forCockpit(self::USER, $this->tyreCockpit())['tyres'];
        $this->db->exec('DELETE FROM race_telemetry');
        $dry = $this->service->forCockpit(self::USER, $this->tyreCockpit())['tyres'];

        self::assertNotNull($wet);
        self::assertNotNull($dry);
        self::assertNotSame(
            array_column($wet['rows'], 'total', 'name'),
            array_column($dry['rows'], 'total', 'name'),
        );
    }

    public function testTheCalendarTracksWearLabelChangesTheAdvice(): void
    {
        $this->seedPromotionFixture();
        $this->seedTyreFixture();
        $this->db->exec("INSERT INTO tracks (name, laps, distance, tyre_wear) VALUES ('Midway', 50, 250.0, 'Medium')");
        $calendar = [
            ['race' => 10, 'track_name' => 'Tyreton'],
            ['race' => 11, 'track_name' => 'Wetburg'],
            ['race' => 12, 'track_name' => 'Midway'],
        ];
        $rowsFor = function () use ($calendar): array {
            $tyres = $this->service->forCockpit(
                self::USER,
                $this->tyreCockpit(['season_calendar' => $calendar]),
            )['tyres'];
            self::assertNotNull($tyres);

            return array_column($tyres['rows'], 'total', 'name');
        };

        $before = $rowsFor();
        $this->db->exec("UPDATE tracks SET tyre_wear = 'Very High' WHERE name = 'Midway'");

        self::assertNotSame($before, $rowsFor());
    }

    public function testTheWetEdgeComesFromTheDriversTalentAgainstTheDivisionBaseline(): void
    {
        $this->seedPromotionFixture();
        $this->seedTyreFixture();

        $below = $this->service->forCockpit(self::USER, $this->tyreCockpit())['tyres'];
        $at = $this->service->forCockpit(
            self::USER,
            $this->tyreCockpit(['driver' => ['concentration' => 100, 'talent' => 193, 'experience' => 80]]),
        )['tyres'];

        self::assertNotNull($below);
        self::assertNotNull($at);
        self::assertFalse($below['wet_edge']);
        self::assertSame(90, $below['talent']);
        self::assertSame(193, $below['baseline']);
        self::assertTrue($at['wet_edge']);
    }

    public function testStaleDataReturnsNoTyreAdvice(): void
    {
        $this->seedPromotionFixture();
        $this->seedTyreFixture();

        $card = $this->service->forCockpit(self::USER, $this->tyreCockpit(), true);

        self::assertArrayHasKey('tyres', $card);
        self::assertNull($card['tyres']);
    }

    public function testAFailingCorpusReadIsLoggedAndOnlyTheTyreLineGoes(): void
    {
        $this->seedPromotionFixture();
        $this->seedTyreFixture();
        $failing = new class ($this->db) extends RaceTelemetryRepository {
            public function trackConditions(): array
            {
                throw new \PDOException('database is locked');
            }
        };
        $service = $this->engineer(telemetry: $failing);
        $log = tempnam(sys_get_temp_dir(), 'engineer-log');
        self::assertIsString($log);
        $prior = ini_set('error_log', $log);

        try {
            $card = $service->forCockpit(self::USER, $this->tyreCockpit());
        } finally {
            ini_set('error_log', $prior === false ? '' : $prior);
        }
        $logged = (string) file_get_contents($log);
        unlink($log);

        self::assertNull($card['tyres']);
        self::assertNotContains('tyres', array_column($card['lines'], 'key'));
        self::assertContains('cash', array_column($card['lines'], 'key'));
        self::assertStringContainsString('[Engineer] PDOException', $logged);
        self::assertStringNotContainsString('locked', $logged);
    }

    /**
     * @param int $canSign 1 while GPRO's contract window is open
     * @return array<string, mixed>
     */
    private function tyreFeed(int $canSign = 1): array
    {
        $suppliers = [];
        $rows = [
            ['Badyear', 4_800_000, 30, 1.0, 1.0, 5, 12],
            ['Dunnolop', 3_800_000, 28, 0.9, 0.7, 6, 10],
            ['Avonn', 2_900_000, 26, 0.6, 1.2, 3, 8],
            ['Contimental', 2_100_000, 33, 0.4, 0.5, 4, 14],
        ];
        foreach ($rows as $i => [$name, $cost, $peak, $dry, $rain, $durability, $warmup]) {
            $suppliers[] = [
                'id' => $i + 1, 'name' => $name, 'cost' => $cost, 'peakTemperature' => $peak,
                'dryPerformance' => $dry, 'rainPerformance' => $rain, 'durability' => $durability,
                'warmupDistance' => $warmup,
            ];
        }

        return ['canSignContract' => $canSign, 'suppliers' => $suppliers];
    }

    /** @param array<string, mixed> $overrides */
    private function tyreCockpit(array $overrides = []): array
    {
        return $this->cockpit($overrides + [
            'office' => ['seasonNb' => self::SEASON, 'raceNb' => 10, 'tyreSupplierId' => 0],
            'division' => 'Pro',
            'tyre_suppliers' => $this->tyreFeed(),
            'season_calendar' => [
                ['race' => 10, 'track_name' => 'Tyreton'],
                ['race' => 11, 'track_name' => 'Wetburg (Nowhere)'],
            ],
        ]);
    }

    private function seedTyreFixture(): void
    {
        foreach (['Tyreton' => 'High', 'Wetburg' => 'Low'] as $name => $wear) {
            $this->db->exec(
                "INSERT OR IGNORE INTO tracks (name, laps, distance) VALUES ('$name', 50, 250.0)"
            );
            $this->db->exec("UPDATE tracks SET tyre_wear = '$wear' WHERE name = '$name'");
        }
        $telemetry = new RaceTelemetryRepository($this->db);
        foreach ([['Tyreton', 0, 30.0, 1], ['Wetburg (Nowhere)', 1, 12.0, 2]] as [$track, $wet, $temp, $race]) {
            self::assertTrue($telemetry->insertIfNew([
                'season' => 99, 'race' => $race, 'level' => 'Pro', 'q1_time_ms' => 100_000 + $race,
                'track_name' => $track, 'was_wet' => $wet, 'avg_temp' => $temp, 'dnf' => 0, 'has_td' => 0,
            ]));
        }
    }

    public function testASignedContractPricesTheSeasonAtItsOwnCostBeforeRaceOne(): void
    {
        $this->seedPromotionFixture(self::SEASON - 1);
        $signed = fn (array $feed): array => $this->cockpit([
            'office' => ['seasonNb' => self::SEASON, 'raceNb' => 10, 'tyreSupplierId' => 4],
            'division' => 'Pro',
            'tyre_suppliers' => $feed,
        ]);

        $this->service->forCockpit(self::USER, $signed([]));
        $lastSeasonsPrice = $this->budgets->find(self::USER, self::SEASON)['plan']['rows'] ?? [];
        $this->service->forCockpit(self::USER, $signed($this->tyreFeed()));
        $contractPrice = $this->budgets->find(self::USER, self::SEASON)['plan']['rows'] ?? [];

        self::assertNotSame([], $lastSeasonsPrice);
        self::assertLessThan($lastSeasonsPrice[0]['balance'], $contractPrice[0]['balance']);
    }

    public function testBeforeRaceOneTheTyreMoneyCheckReadsLastSeasonsTyreLine(): void
    {
        $this->seedPromotionFixture(self::SEASON - 1);
        $cockpit = fn (array $feed): array => $this->cockpit([
            'office' => ['seasonNb' => self::SEASON, 'raceNb' => 1, 'tyreSupplierId' => 0],
            'division' => 'Pro',
            'tyre_suppliers' => $feed,
            'season_calendar' => [
                ['race' => 1, 'track_name' => 'Testring'],
                ['race' => 2, 'track_name' => 'Testring'],
            ],
        ]);
        $this->service->forCockpit(self::USER, $cockpit([]));
        $plan = $this->budgets->find(self::USER, self::SEASON)['plan'] ?? null;
        self::assertNotNull($plan);
        // The most a race's tyres can cost over today's line with every row still on target.
        $last = array_key_last($plan['rows']);
        $room = PHP_INT_MAX;
        foreach ($plan['rows'] as $i => $row) {
            $target = $i === $last ? $plan['target'] : $plan['cushion'];
            $room = min($room, intdiv($row['balance'] - $target, $i + 1));
        }
        self::assertGreaterThan(0, $room);
        // Avonn costs last season's 20k tyre line plus that room: it only fits if that line is read.
        $feed = $this->tyreFeed();
        $feed['suppliers'][2]['cost'] = 20_000 + $room;

        $card = $this->service->forCockpit(self::USER, $cockpit($feed));

        self::assertSame([], $this->ledger->forSeason(self::USER, self::SEASON));
        $tyres = $card['tyres'];
        self::assertNotNull($tyres);
        self::assertTrue($tyres['finances_known']);
        self::assertSame(
            ['Avonn' => 'pick', 'Badyear' => 'out_price', 'Dunnolop' => 'out_price', 'Contimental' => 'out_dry'],
            array_column($tyres['rows'], 'verdict', 'name'),
        );
        self::assertContains('tyres', array_column($card['lines'], 'key'));
    }

    public function testOnceTheContractIsOnTheLedgerTheSignedPriceChangesNothing(): void
    {
        $this->seedPromotionFixture();
        $this->movement('Tyre supplier', -2_100_000 + 20_000, 9);
        $signed = fn (array $feed): array => $this->cockpit([
            'office' => ['seasonNb' => self::SEASON, 'raceNb' => 10, 'tyreSupplierId' => 4],
            'division' => 'Pro',
            'tyre_suppliers' => $feed,
        ]);

        $this->service->forCockpit(self::USER, $signed([]));
        $withoutFeed = $this->budgets->find(self::USER, self::SEASON)['plan'] ?? null;
        $this->service->forCockpit(self::USER, $signed($this->tyreFeed()));
        $withFeed = $this->budgets->find(self::USER, self::SEASON)['plan'] ?? null;

        self::assertNotNull($withoutFeed);
        self::assertSame($withoutFeed, $withFeed);
    }

    public function testTheUnsignedPickIsPaidBeforeUpgradesTrainingAndTesting(): void
    {
        $this->seedPromotionFixture();
        $this->seedWearTracks();
        $unsigned = fn (array $feed): array => [
            'office' => ['seasonNb' => self::SEASON, 'raceNb' => 10, 'tyreSupplierId' => 0],
            'division' => 'Pro',
            'tyre_suppliers' => $feed,
        ] + $this->upgradeCockpit(30);

        $this->service->forCockpit(self::USER, $unsigned([]));
        $before = $this->budgets->find(self::USER, self::SEASON)['plan'] ?? [];
        $tyres = $this->service->forCockpit(self::USER, $unsigned($this->cheapTyreFeed()))['tyres'];
        $after = $this->budgets->find(self::USER, self::SEASON)['plan'] ?? [];

        self::assertNotNull($tyres);
        self::assertTrue($tyres['finances_known']);
        $extra = $tyres['cost'] - 20_000;
        self::assertGreaterThan(0, $extra);
        self::assertSame($before['envelope'] - $extra * count($before['rows']), $after['envelope']);
        foreach ($before['rows'] as $i => $row) {
            self::assertSame($row['balance'] - $extra * ($i + 1), $after['rows'][$i]['balance']);
        }
    }

    public function testAnUnaffordablePickLeavesNoEnvelopeForUpgrades(): void
    {
        $this->seedPromotionFixture();
        $this->seedWearTracks();
        $unsigned = fn (array $feed): array => [
            'office' => ['seasonNb' => self::SEASON, 'raceNb' => 10, 'tyreSupplierId' => 0],
            'division' => 'Pro',
            'tyre_suppliers' => $feed,
        ] + $this->upgradeCockpit(30);

        $this->service->forCockpit(self::USER, $unsigned([]));
        $before = $this->budgets->find(self::USER, self::SEASON)['plan'] ?? [];
        $tyres = $this->service->forCockpit(self::USER, $unsigned($this->tyreFeed()))['tyres'];
        $after = $this->budgets->find(self::USER, self::SEASON)['plan'] ?? [];

        self::assertNotNull($tyres);
        self::assertSame('budget_short', $tyres['reason']);
        self::assertNotSame([], array_filter($before['upgrades'] ?? [], static fn (array $u): bool => $u['affordable']));
        self::assertSame(0, $after['envelope']);
        self::assertSame([], array_filter($after['upgrades'] ?? [], static fn (array $u): bool => $u['affordable']));
    }

    /** @return array<string, mixed> the fixture feed at prices this season's budget can pay */
    private function cheapTyreFeed(): array
    {
        $feed = $this->tyreFeed();
        foreach ($feed['suppliers'] as $i => $supplier) {
            $feed['suppliers'][$i]['cost'] = intdiv($supplier['cost'], 100);
        }

        return $feed;
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

    public function testDoneFlagsReachTheBrief(): void
    {
        $this->seedPromotionFixture();

        $result = $this->service->forCockpit(self::USER, $this->cockpit([
            'office' => $this->office(['doneCarRepair' => '1', 'doneTesting' => 1, 'doneDriTrain' => '1']),
            'car' => $this->pricedCar(200_000),
        ]));
        $byKey = array_column($result['lines'], null, 'key');

        self::assertTrue($result['car_updated']);
        self::assertSame('Car updated', $byKey['parts']['answer']);
        self::assertSame('Testing done this race', $byKey['testing']['answer']);
        self::assertSame('Driver trained this race', $byKey['training']['answer']);
        self::assertArrayNotHasKey('upgrades', $byKey);
        self::assertArrayNotHasKey('upgrades', $this->budgets->find(self::USER, self::SEASON)['plan'] ?? []);
    }

    public function testOnlyOneMeansDone(): void
    {
        $this->seedPromotionFixture();

        foreach (['0', 0, null, 'missing', 'yes', true, '01'] as $flag) {
            $office = $this->office($flag === 'missing' ? [] : ['doneCarRepair' => $flag]);
            $result = $this->service->forCockpit(self::USER, $this->cockpit([
                'office' => $office,
                'car' => $this->pricedCar(200_000),
            ]));
            $parts = array_column($result['lines'], null, 'key')['parts']['answer'];

            self::assertNotSame('Car updated', $parts, var_export($flag, true));
            self::assertFalse($result['car_updated'], var_export($flag, true));
        }

        $result = $this->service->forCockpit(self::USER, $this->cockpit([
            'office' => $this->office(['doneCarRepair' => 1]),
            'car' => $this->pricedCar(200_000),
        ]));
        self::assertTrue($result['car_updated']);
    }

    public function testAStaleCockpitStillReportsTheCarUpdatedKey(): void
    {
        $result = $this->service->forCockpit(self::USER, $this->cockpit(), true);

        self::assertFalse($result['car_updated']);
    }

    public function testALockedCarWithAPastLimitPartAdvisesCtrZero(): void
    {
        $this->seedPromotionFixture();
        $engine = ['level' => 1, 'start' => 95, 'est' => 40.0, 'end' => 135.0, 'track_base' => 8.0];
        $brakes = ['level' => 5, 'start' => 10, 'est' => 15.0, 'end' => 25.0, 'track_base' => 30.0];

        $lines = $this->service->forCockpit(self::USER, $this->cockpit([
            'office' => $this->office(['doneCarRepair' => '1', 'cash' => 20_000_000]),
            'ctr' => 100,
            'car' => $this->pricedCar(200_000),
            'wear' => ['track_name' => 'Testring', 'laps' => 50, 'parts' => ['Engine' => $engine, 'Brakes' => $brakes]],
        ]))['lines'];
        $byKey = array_column($lines, null, 'key');

        self::assertSame('CTR 0', $byKey['ctr']['answer']);
        self::assertSame(
            'Engine reaches 100% this race and the car is already updated: CTR 0 keeps its wear lowest',
            $byKey['ctr']['reason'],
        );
        self::assertSame('Car updated', $byKey['parts']['answer']);
        self::assertSame(
            'Engine reaches 100% this race: expect smoke and lost time, and a retirement if it comes early; '
                . 'no more changes until the next race',
            $byKey['parts']['reason'],
        );
        self::assertSame('amber', $byKey['parts']['tone']);
    }

    public function testALockedHoldCarWithAPartAt100IsAmberAndNamesTheSmokeInTheCtrReason(): void
    {
        $this->seedPromotionFixture();
        $this->service->pin(self::USER, self::SEASON, 'stay');
        $engine = ['level' => 1, 'start' => 95, 'est' => 40.0, 'end' => 135.0, 'track_base' => 8.0];
        $brakes = ['level' => 5, 'start' => 10, 'est' => 15.0, 'end' => 25.0, 'track_base' => 30.0];

        $byKey = array_column($this->service->forCockpit(self::USER, $this->cockpit([
            'office' => $this->office(['doneCarRepair' => '1', 'cash' => 20_000_000]),
            'ctr' => 100,
            'car' => $this->pricedCar(200_000),
            'wear' => ['track_name' => 'Testring', 'laps' => 50, 'parts' => ['Engine' => $engine, 'Brakes' => $brakes]],
        ]))['lines'], null, 'key');

        self::assertSame('amber', $byKey['parts']['tone']);
        self::assertSame(
            'Engine reaches 100% this race: expect smoke and lost time, and a retirement if it comes early; '
                . 'no more changes until the next race',
            $byKey['parts']['reason'],
        );
        self::assertSame(
            'Engine reaches 100% this race and the car is already updated: CTR 0 keeps its wear lowest',
            $byKey['ctr']['reason'],
        );
    }

    public function testALockedStayCarWithAPartInTheRedIsNotFlagged(): void
    {
        $this->seedPromotionFixture();
        $this->service->pin(self::USER, self::SEASON, 'stay');
        // Engine 85 ends a race at 93 even at CTR 0: in the red, but short of 100%, so nothing is stuck.
        $engine = ['level' => 1, 'start' => 85, 'est' => 0.0, 'end' => 0.0, 'track_base' => 8.0];
        $brakes = ['level' => 5, 'start' => 10, 'est' => 15.0, 'end' => 25.0, 'track_base' => 8.0];

        $byKey = array_column($this->service->forCockpit(self::USER, $this->cockpit([
            'office' => $this->office(['doneCarRepair' => '1', 'cash' => 20_000_000]),
            'ctr' => 100,
            'car' => $this->pricedCar(200_000),
            'wear' => ['track_name' => 'Testring', 'laps' => 50, 'parts' => ['Engine' => $engine, 'Brakes' => $brakes]],
        ]))['lines'], null, 'key');

        self::assertStringNotContainsString('already updated', $byKey['ctr']['reason']);
        self::assertSame('ink', $byKey['parts']['tone']);
        self::assertSame('One update per race; the plan reads the car as it is now', $byKey['parts']['reason']);
    }

    public function testALockedCarWithoutAnOutlookStillSaysCarUpdated(): void
    {
        $lines = $this->service->forCockpit(99, $this->cockpit([
            'office' => $this->office(['doneCarRepair' => '1']),
        ]))['lines'];

        self::assertSame('Car updated', array_column($lines, null, 'key')['parts']['answer']);
    }

    public function testSwapSeasonIgnoresTheLock(): void
    {
        $this->seedPromotionFixture();
        $engine = ['level' => 1, 'start' => 95, 'est' => 40.0, 'end' => 135.0, 'track_base' => 8.0];
        $brakes = ['level' => 5, 'start' => 10, 'est' => 15.0, 'end' => 25.0, 'track_base' => 30.0];

        $season = $this->service->swapSeason(self::USER, $this->cockpit([
            'office' => $this->office(['doneCarRepair' => '1']),
            'car' => $this->pricedCar(200_000),
            'wear' => ['track_name' => 'Testring', 'laps' => 50, 'parts' => ['Engine' => $engine, 'Brakes' => $brakes]],
        ]));

        self::assertInstanceOf(SwapSeason::class, $season);
        self::assertSame(['Engine'], array_column($season->plan['rows'][0]['replaced'], 'part'));
    }

    public function testALockedCarOffersNoOptionalSwapAndReservesNothingForOne(): void
    {
        $this->seedPromotionFixture();
        $engine = ['level' => 1, 'start' => 70, 'est' => 0.0, 'end' => 0.0, 'track_base' => 8.0];
        $brakes = ['level' => 5, 'start' => 10, 'est' => 0.0, 'end' => 0.0, 'track_base' => 3.0];
        $cockpit = fn (int $trainingCost): array => $this->cockpit([
            'office' => $this->office(['doneCarRepair' => '1']),
            'car' => $this->pricedCar(200_000),
            'wear' => ['track_name' => 'Testring', 'laps' => 50, 'parts' => ['Engine' => $engine, 'Brakes' => $brakes]],
            'training_picks' => [['name' => 'Concentration', 'score' => 1.0, 'cost' => $trainingCost, 'gains' => []]],
        ]);

        $this->service->forCockpit(self::USER, $cockpit(1));
        $saved = $this->budgets->find(self::USER, self::SEASON)['plan'] ?? [];
        $envelope = $saved['envelope'];
        // The Engine ends the race in the red zone at the plan's CTR: unlocked, it would be offered as a swap.
        $wear = $this->wearService();
        $end = 70 + round($wear->raceWear(8.0, 1, $wear->driverFactor($this->cockpit()['driver']), $saved['ctr']), 1);
        self::assertGreaterThanOrEqual(WearAdvisorService::THRESHOLD_RISKY, $end);
        self::assertLessThan(WearAdvisorService::THRESHOLD_SWAP, $end);
        self::assertSame([], $saved['rows'][0]['replaced']);
        self::assertGreaterThan(200_000, $envelope);

        $lines = $this->service->forCockpit(self::USER, $cockpit($envelope - 1))['lines'];

        self::assertSame('Train Concentration', array_column($lines, null, 'key')['training']['answer']);
    }

    public function testSwapSeasonIsNullWithoutACalendar(): void
    {
        $this->seedPromotionFixture();

        self::assertNull($this->service->swapSeason(self::USER, $this->cockpit(['season_calendar' => []])));
    }

    public function testSwapSeasonNeverSavesAPlan(): void
    {
        $this->seedPromotionFixture();
        $rows = fn (): int => (int) $this->db->query('SELECT COUNT(*) FROM user_season_budget')->fetchColumn();

        $season = $this->service->swapSeason(self::USER, $this->cockpit());

        self::assertInstanceOf(SwapSeason::class, $season);
        self::assertSame('promote', $season->objective);
        self::assertSame(0, $rows());
    }

    public function testTheEngineerNamesTheSwapLevelAndSavesItWithThePlan(): void
    {
        $this->seedPromotionFixture();
        $this->seedWearTracks();
        $this->service->pin(self::USER, self::SEASON, 'stay');
        $parts = [
            'Engine' => ['level' => 1, 'start' => 95, 'est' => 0.0, 'end' => 0.0, 'track_base' => 8.0],
            'Brakes' => ['level' => 1, 'start' => 10, 'est' => 0.0, 'end' => 0.0, 'track_base' => 8.0],
        ];

        $lines = $this->service->forCockpit(self::USER, $this->cockpit($this->swapCockpit($parts)))['lines'];

        $saved = $this->budgets->find(self::USER, self::SEASON);
        self::assertNotNull($saved);
        self::assertSame(
            [['part' => 'Engine', 'level' => 1, 'kind' => 'sidegrade', 'cost' => 100_000, 'net_cost' => 0,
                'reason' => 'stay_cheapest']],
            $saved['plan']['swap_picks'] ?? null,
        );
        self::assertSame('Swap Engine → L1 (same level)', array_column($lines, null, 'key')['parts']['answer']);
    }

    public function testTheUpgradeRankingSkipsAPartThatHasASwapPick(): void
    {
        $this->seedPromotionFixture();
        $this->seedWearTracks();
        $this->service->pin(self::USER, self::SEASON, 'stay');
        $parts = [
            'Engine' => ['level' => 1, 'start' => 95, 'est' => 0.0, 'end' => 0.0, 'track_base' => 8.0],
            'Brakes' => ['level' => 1, 'start' => 10, 'est' => 0.0, 'end' => 0.0, 'track_base' => 8.0],
        ];
        $cockpit = $this->swapCockpit($parts);
        $car = $cockpit['car'];
        $car['engineOptions'][] = ['value' => ['value' => 2, 'cost' => 150_000], 'newLvl' => '2'];
        $car['brakesOptions'][] = ['value' => ['value' => 2, 'cost' => 150_000], 'newLvl' => '2'];
        $cockpit['car'] = $car;
        $ranked = new \ArrayObject();
        $wear = $this->wearService();
        $ranker = new readonly class ($wear, $ranked) extends UpgradeRanker {
            /** @param \ArrayObject<int, list<string>> $ranked */
            public function __construct(CarWearService $wear, private \ArrayObject $ranked)
            {
                parent::__construct(new SeasonBudget($wear), []);
            }

            public function rank(
                array $plan,
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
                $this->ranked->append(array_keys($options));

                return [];
            }
        };

        $this->engineer(ranker: $ranker)->forCockpit(self::USER, $this->cockpit($cockpit));

        $saved = $this->budgets->find(self::USER, self::SEASON);
        self::assertSame(['Engine'], array_column($saved['plan']['swap_picks'] ?? [], 'part'));
        self::assertCount(1, $ranked);
        self::assertSame(['Brakes'], $ranked[0]);
    }

    public function testAFailingSwapPickIsLoggedAndThePartsLineKeepsTodaysWording(): void
    {
        $this->seedPromotionFixture();
        $this->seedWearTracks();
        $this->service->pin(self::USER, self::SEASON, 'stay');
        $parts = [
            'Engine' => ['level' => 1, 'start' => 95, 'est' => 0.0, 'end' => 0.0, 'track_base' => 8.0],
            'Brakes' => ['level' => 1, 'start' => 10, 'est' => 0.0, 'end' => 0.0, 'track_base' => 8.0],
        ];
        $wear = $this->wearService();
        $failing = new readonly class ($wear) extends SwapLevelPicker {
            public function __construct(CarWearService $wear)
            {
                parent::__construct(new SeasonBudget($wear), []);
            }

            public function pick(SwapSeason $s, string $part, int $currentLevel, array $candidates, array $paidPrice): ?array
            {
                throw new \DomainException('secret detail');
            }
        };
        $service = $this->engineer(picker: $failing);
        $log = tempnam(sys_get_temp_dir(), 'engineer-log');
        self::assertIsString($log);
        $prior = ini_set('error_log', $log);

        try {
            $lines = $service->forCockpit(self::USER, $this->cockpit($this->swapCockpit($parts)))['lines'];
        } finally {
            ini_set('error_log', $prior === false ? '' : $prior);
        }
        $logged = (string) file_get_contents($log);
        unlink($log);

        self::assertSame('Swap Engine', array_column($lines, null, 'key')['parts']['answer']);
        self::assertArrayNotHasKey('swap_picks', $this->budgets->find(self::USER, self::SEASON)['plan'] ?? []);
        self::assertStringContainsString('[Engineer] DomainException', $logged);
        self::assertStringNotContainsString('secret', $logged);
    }

    /**
     * A Stay cockpit whose Engine must be replaced before the next race, priced at $100k for a fresh L1.
     *
     * @param array<string, array<string, mixed>> $parts
     * @return array<string, mixed>
     */
    private function swapCockpit(array $parts): array
    {
        return [
            'ctr' => 0,
            'car' => $this->pricedCar(100_000),
            'season_calendar' => [['race' => 10, 'track_name' => 'Gamma'], ['race' => 11, 'track_name' => 'Gamma']],
            'track_name' => 'Gamma',
            'wear' => ['track_name' => 'Gamma', 'laps' => 50, 'parts' => $parts],
            'wear_advice' => $this->adviceAt(0, $parts),
            'track_pha' => ['power' => 5, 'handling' => 3, 'acceleration' => 2],
            'car_pha' => ['power' => 80, 'handling' => 80, 'acceleration' => 80],
            'group_car_levels' => [],
            'training_wear' => [],
        ];
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
    private function stayCtrLine(int $ctr, array $advice): array
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

    /**
     * @return array<string, mixed>
     */
    private function savedPlan(): array
    {
        return $this->budgets->find(self::USER, self::SEASON)['plan'] ?? [];
    }

    private function paceTrack(string $name, float $lapLength, float $avgSpeed): void
    {
        $this->db->prepare(
            "INSERT INTO tracks (name, laps, lap_length, avg_speed, distance, fuel_per_lap, fuel_per_lap_wet, boost_dry,
                boost_wet, overtaking, grip, tyre_wear, pit_time, wear_engine, wear_brakes, wear_electronics)
             VALUES (:name, 50, :lap, :speed, 250.0, 2.0, 3.0, 0.3, 0.4, 'Normal', 'Normal', 'Normal', 20.0, 40, 30, 10)
             ON CONFLICT(name) DO UPDATE SET lap_length = excluded.lap_length, avg_speed = excluded.avg_speed"
        )->execute([':name' => $name, ':lap' => $lapLength, ':speed' => $avgSpeed]);
    }

    public function testOfficeEnergyAndQ2ReachThePlan(): void
    {
        $this->seedPromotionFixture();
        $startWith = function (string $doneQ2): mixed {
            $this->service->forCockpit(self::USER, $this->cockpit([
                'office' => $this->office(['driEnergy' => '50', 'doneQ2' => $doneQ2]),
            ]));

            return $this->savedPlan()['energy_start'] ?? null;
        };

        // An empty corpus leaves the fallback recovery (70 + 0.3 x energy after Q2) and a 4-point qualifying drain.
        self::assertSame(85, $startWith('1'));
        self::assertSame(84, $startWith('0'));
        self::assertSame(35, $this->savedPlan()['energy_cap'] ?? null);
    }

    public function testAStayPinPicksByValueSafetyOrEnergy(): void
    {
        $this->seedPromotionFixture();
        $this->paceTrack('Testring', 5.0, 200.0);
        $this->service->pin(self::USER, self::SEASON, 'stay');

        $this->service->forCockpit(self::USER, $this->cockpit());
        $plan = $this->savedPlan();

        self::assertContains($plan['ctr_kind'] ?? null, ['value', 'safety', 'energy']);
        self::assertTrue($plan['value']['pace_known'] ?? null);
    }

    public function testNullDivisionFallsBack(): void
    {
        $this->seedPromotionFixture();
        $this->paceTrack('Testring', 5.0, 200.0);
        $this->service->pin(self::USER, self::SEASON, 'stay');

        $this->service->forCockpit(self::USER, $this->cockpit([
            'division' => null,
            'office' => $this->office(['driEnergy' => '60', 'doneQ2' => '1']),
        ]));
        $plan = $this->savedPlan();

        self::assertTrue($plan['energy_estimate'] ?? null);
        // Without a division there is no prize per place, so the pace is worth nothing.
        self::assertFalse($plan['value']['pace_known'] ?? null);
        self::assertSame(0, $plan['value']['gain'] ?? null);
    }

    public function testACalendarTrackWithNoAverageSpeedContributesNoGainAndTheNextPacedTrackGivesThePlaces(): void
    {
        $this->seedPromotionFixture();
        $this->paceTrack('Testring', 5.0, 0.0);
        $this->paceTrack('Fastring', 5.0, 200.0);
        $this->service->pin(self::USER, self::SEASON, 'stay');

        $this->service->forCockpit(self::USER, $this->cockpit(['season_calendar' => [
            ['race' => 10, 'track_name' => 'Testring'],
            ['race' => 11, 'track_name' => 'Fastring'],
        ]]));
        $value = $this->savedPlan()['value'] ?? null;

        self::assertNotNull($value);
        self::assertTrue($value['pace_known']);
        // Fastring alone: 5 km at 200 km/h x 0.001 x 50 laps = 4.5 s a point, over Amateur's 0.19 s x 50 laps a
        // place, at $153,846 a place.
        $ctr = $this->savedPlan()['ctr'];
        self::assertGreaterThan(0, $ctr);
        self::assertSame($ctr * 72_874, $value['gain']);
        // The next race (Testring) has no lap time to gain from, so the places a race come from Fastring.
        self::assertGreaterThan(0.0, (float) $value['places']);
        self::assertSame(round($ctr * 4.5 / (0.19 * 50), 1), (float) $value['places']);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function office(array $extra = []): array
    {
        return ['seasonNb' => self::SEASON, 'raceNb' => 10] + $extra;
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

    private function seedPromotionFixture(int $season = self::SEASON): void
    {
        $this->standing(9, 2, 60, $season);
        $this->weekends->save([
            'user_id' => self::USER, 'season' => $season, 'race' => 9, 'source' => 'snapshot',
            'driver_json' => json_encode(['salary' => 100_000], JSON_THROW_ON_ERROR),
            'td_json' => null,
            'staff_json' => json_encode(['salary' => 50_000], JSON_THROW_ON_ERROR),
            'facilities_json' => null, 'car_json' => null,
            'cash' => 5_000_000, 'pts' => 60, 'pos' => 2,
        ]);
        foreach ([8, 9] as $race) {
            $this->movement('Sponsor Acme', 300_000, $race, $season);
            $this->movement('Race ' . $race . ' finish position', 100_000, $race, $season);
            $this->movement('Facilities upkeep', -50_000, $race, $season);
            $this->movement('Tyre supplier', -20_000, $race, $season);
        }
    }

    private function standing(int $race, int $pos, int $pts, int $season = self::SEASON): void
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
            'user_id' => self::USER, 'season' => $season, 'race' => $race,
            'own_pos' => $pos, 'own_pts' => $pts, 'group_label' => 'Amateur - 4',
            'managers_json' => json_encode($managers, JSON_THROW_ON_ERROR),
        ]);
    }

    private function movement(string $description, int $amount, int $race, int $season = self::SEASON): void
    {
        $at = sprintf('2026-10-%02d 12:00:00', $race);
        $this->ledger->save([
            'user_id' => self::USER, 'season' => $season, 'race' => $race, 'source' => 'economy',
            'occurred_at' => $at, 'description' => $description, 'amount' => $amount,
            'balance_after' => null, 'fingerprint' => sha1($description . $race . $amount),
        ]);
    }
}
