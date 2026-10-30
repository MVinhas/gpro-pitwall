<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Archive\AnalysisReader;
use App\Archive\ArchiveProjector;
use App\Archive\ArchiveSanitizer;
use App\Archive\LedgerProjector;
use App\Archive\RaceSnapshotReader;
use App\Archive\StandingsProjector;
use App\Archive\WeekendStateProjector;
use App\Cache\Adapter\FilesystemCache;
use App\Calibration\FuelCalibration;
use App\Calibration\WearCalibration;
use App\Controller\CarWearController;
use App\Controller\DebriefController;
use App\Controller\PageController;
use App\Controller\SeasonController;
use App\Controller\StrategyController;
use App\Controller\TestingController;
use App\Database\DatabaseSeeder;
use App\Engineer\CtrCeiling;
use App\Engineer\CtrPlanner;
use App\Engineer\SeasonBudget;
use App\Engineer\SwapLevelPicker;
use App\Engineer\UpgradeRanker;
use App\Http\Request;
use App\Repository\ApiArchiveRepository;
use App\Repository\DivisionMetadataRepository;
use App\Repository\EconomyLedgerRepository;
use App\Repository\PilotRepository;
use App\Repository\ProjectionStateRepository;
use App\Repository\RaceHistoryRepository;
use App\Repository\RaceTelemetryRepository;
use App\Repository\SeasonBudgetRepository;
use App\Repository\SeasonObjectiveRepository;
use App\Repository\StandingsRepository;
use App\Repository\TrackRepository;
use App\Repository\UserRepository;
use App\Repository\WeekendStateRepository;
use App\Security\ApiTokenCrypto;
use App\Security\Authorize;
use App\Security\EmailCrypto;
use App\Service\BaselineAutoFillService;
use App\Service\BillboardService;
use App\Service\BoostFuelService;
use App\Service\CarWearPlannerService;
use App\Service\CarWearService;
use App\Service\DebriefService;
use App\Service\EarlyQualifyingBonusService;
use App\Service\FuelModel;
use App\Service\GproApiClient;
use App\Service\GproApiThrottle;
use App\Service\GproDataMapper;
use App\Service\GproSyncService;
use App\Service\HarvestService;
use App\Service\IdealPilotService;
use App\Service\InsightService;
use App\Service\PartSwapAdvisorService;
use App\Service\PartUpgradeAdvisorService;
use App\Service\PhaMatchService;
use App\Service\PilotCalculatorService;
use App\Service\RaceEngineerService;
use App\Service\RaceHistoryService;
use App\Service\RaceIntelligenceService;
use App\Service\RaceTelemetryService;
use App\Service\RaceWeatherService;
use App\Service\RaceWeekendService;
use App\Service\RecruitmentService;
use App\Service\RiskAdvisorService;
use App\Service\SeasonCalendarService;
use App\Service\SeasonService;
use App\Service\SetupCalculatorService;
use App\Service\SponsorAdvisorService;
use App\Service\StrategyService;
use App\Service\TestingProjectionService;
use App\Service\TestingTargetsService;
use App\Service\TrainingAdvisorService;
use App\Service\TrainingService;
use App\Service\TrainingWearProjectionService;
use App\Service\WearAdvisorService;
use App\Support\RaceWindow;
use App\Support\SyncState;
use App\Sync\EndpointFetcher;
use App\Sync\SyncContextFactory;
use App\Sync\SyncPlanner;
use App\Telemetry\RaceHistoryMapper;
use App\Telemetry\RaceTelemetryMapper;
use App\Tests\Support\CountingGproFetcher;
use App\Tests\Support\FakeSecrets;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * The manual-refresh promise end to end: with a manager's data archived in a
 * previous race window and nothing in the cache, every screen renders from
 * the archive and GPRO is never called. Screens are rendered through their
 * real controllers, wired as bootstrap.php wires them, with a counting
 * fetcher in place of the network and made-up game constants. A warning
 * fails the suite (phpunit.xml), and every controller logs what it catches,
 * so an empty log means no screen hit an exception either.
 */
#[CoversNothing]
final class ManualRefreshTest extends TestCase
{
    private const string TOKEN = 'manual-refresh-token';
    private const int USER_ID = 1;
    private const string SECRET = 'manual-refresh-test-secret';

    private string $dir;
    private string $id;
    private string $dbPath;
    private string $cacheDir;
    private string $logFile;
    private string|false $priorErrorLog = false;
    private mixed $priorRaceDays = null;
    /** @var array<string, mixed> */
    private array $priorSession = [];
    private PDO $db;
    private CountingGproFetcher $fetcher;
    private ApiArchiveRepository $archive;

    protected function setUp(): void
    {
        $this->priorRaceDays = $_ENV['GPRO_RACE_DAYS'] ?? null;
        $_ENV['GPRO_RACE_DAYS'] = '2,5';
        $this->priorSession = $_SESSION ?? [];

        $this->dir = dirname(__DIR__, 3) . '/var/test';
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0750, true);
        }
        $this->id = uniqid('', true);
        $this->dbPath = $this->dir . '/manual-refresh-' . $this->id . '.sqlite';
        $this->cacheDir = $this->dir . '/manual-refresh-cache-' . $this->id;
        $this->logFile = $this->dir . '/manual-refresh-' . $this->id . '.log';
        $this->priorErrorLog = ini_get('error_log');
        ini_set('error_log', $this->logFile);

        $this->db = new PDO('sqlite:' . $this->dbPath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec('PRAGMA foreign_keys = ON');
        $config = require dirname(__DIR__, 3) . '/config/game_constants.php';
        (new DatabaseSeeder(
            $this->db,
            $config['stats_schema'],
            $config['divisions'],
            [],
            new ApiTokenCrypto(self::SECRET),
        ))->migrate();
        $this->db->exec(
            "INSERT INTO users (id, username, email_encrypted, email_hash, is_admin) "
            . "VALUES (1, 'alice', 'enc', 'hash', 1)"
        );
        $this->users()->updateApiToken(self::USER_ID, self::TOKEN);
        // data/tracks.csv is gitignored, so CI seeds no tracks: add Monza, then
        // give it the figures the calculators read.
        $this->db->exec("INSERT OR IGNORE INTO tracks (name) VALUES ('Monza')");
        $this->db->exec(
            "UPDATE tracks SET lap_length = 5.8, laps = 53, distance = 307.0, avg_speed = 240.0, corners = 11,
                pit_time = 22.0, base_wings = 100, base_engine = 200, base_brakes = 300, base_gear = 400,
                base_suspension = 500, fuel_consumption = 'High', tyre_wear = 'Medium', wing_split = 0,
                fuel_per_lap = 2.0, fuel_per_lap_wet = 2.2, tyre_wear_factor = 1.0, wear_chassis = 20,
                wear_engine = 30, wear_fwing = 20, wear_rwing = 20, wear_underbody = 20, wear_sidepod = 20,
                wear_cooling = 20, wear_gearbox = 20, wear_brakes = 30, wear_suspension = 20,
                wear_electronics = 10, boost_dry = 0.0, boost_wet = 0.0, overtaking = 'Easy', grip = 'Medium'
             WHERE name = 'Monza'"
        );

        $this->fetcher = new CountingGproFetcher();
        $this->archive = new ApiArchiveRepository($this->db, new ArchiveSanitizer());
        $_SESSION = ['user_id' => self::USER_ID, 'csrf_token' => 'csrf'];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->priorSession;
        ini_set('error_log', (string) $this->priorErrorLog);
        if ($this->priorRaceDays === null) {
            unset($_ENV['GPRO_RACE_DAYS']);
        } else {
            $_ENV['GPRO_RACE_DAYS'] = $this->priorRaceDays;
        }
        unset($this->db);
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($this->dbPath . $suffix)) {
                unlink($this->dbPath . $suffix);
            }
        }
        $this->wipeCache();
        @rmdir($this->cacheDir);
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    /** Screens with nothing to show but "No data yet" until a sync has stored something. */
    private const array TEAM_PATHS = ['/cockpit', '/strategy', '/testing', '/car-wear', '/engineer'];

    /**
     * Every screen a signed-in manager can open that reads team data, by path.
     *
     * @return iterable<string, array{string}>
     */
    public static function screens(): iterable
    {
        yield 'cockpit' => ['/cockpit'];
        yield 'strategy' => ['/strategy'];
        yield 'testing' => ['/testing'];
        yield 'training' => ['/training'];
        yield 'car wear' => ['/car-wear'];
        yield 'engineer' => ['/engineer'];
        yield 'season' => ['/season'];
        yield 'debrief' => ['/debrief'];
    }

    #[DataProvider('screens')]
    public function testEveryScreenRendersFromTheArchiveWithZeroCalls(string $path): void
    {
        $this->synced();
        $this->archivePreviousWindow();

        $html = $this->render($path);

        $this->assertSame([], $this->fetcher->calls, $path . ' spent the API budget');
        $this->assertNoFailure($html);
        $this->assertFalse(str_contains($html, 'No data yet'), 'the archive fills the screen');
    }

    public function testTheScreensShowTheArchivedTeam(): void
    {
        $this->synced();
        $this->archivePreviousWindow();

        $this->assertStringContainsString('id="pha"', $this->render('/cockpit'));
        $this->assertStringContainsString('data-result-state="results"', $this->render('/strategy'));
        $this->assertStringContainsString('id="engineer-title"', $this->render('/engineer'));
        $this->assertStringContainsString('value="211"', $this->render('/training'));
        $this->assertSame([], $this->fetcher->calls);
    }

    public function testAMissingEndpointReadsAsEmptyWithZeroCalls(): void
    {
        $this->synced();
        $this->archivePreviousWindow();
        $client = $this->client();
        $client->setToken(self::TOKEN, self::USER_ID);

        $this->assertSame([], $client->getTesting());
        $this->assertSame([], $client->getTechnicalDirector());

        $testing = $this->render('/testing');
        $this->assertStringContainsString(TestingController::NO_TRACK_MESSAGE, $testing);
        $this->assertNoFailure($testing);
        $strategy = $this->render('/strategy');
        $this->assertStringContainsString('data-result-state="results"', $strategy);
        $this->assertNoFailure($strategy);
        $this->assertSame([], $this->fetcher->calls);
    }

    public function testNothingFetchesAfterACacheWipe(): void
    {
        $this->synced();
        $this->archivePreviousWindow();
        foreach (self::screens() as [$path]) {
            $this->render($path);
        }
        $this->assertSame([], $this->fetcher->calls);

        // A deploy: var/cache/ is cleared, the archive survives.
        $this->wipeCache();

        foreach (self::screens() as [$path]) {
            $this->assertNoFailure($this->render($path));
        }
        $this->assertStringContainsString('id="pha"', $this->render('/cockpit'));
        $this->assertSame([], $this->fetcher->calls);
    }

    /**
     * The menu is fetched once a season, the office on every sync: cash comes
     * from the office, in the header on every screen and in the cockpit's plan.
     */
    public function testCashIsTheOfficesNotTheSeasonOldMenus(): void
    {
        $this->synced();
        $this->archivePreviousWindow(['cash' => 7_654_321], ['usaEngine' => 90]);
        $this->archiveRow('Menu', ['cash' => 1_000, 'group' => 'Amateur - 3', 'IDM' => 7]);

        foreach (['/testing', '/training', '/car-wear'] as $path) {
            $this->assertStringContainsString('$7,654,321', $this->render($path), $path . ' header cash');
        }
        $cockpit = $this->render('/cockpit');
        $this->assertStringContainsString('data-cash="7654321"', $cockpit);
        $this->assertStringNotContainsString('$1,000<', $cockpit);
        $this->assertSame([], $this->fetcher->calls);
    }

    #[DataProvider('screens')]
    public function testANeverSyncedAccountRendersWithZeroCalls(string $path): void
    {
        $html = $this->render($path);

        $this->assertSame([], $this->fetcher->calls, $path . ' spent the API budget');
        $this->assertNoFailure($html);
        $this->assertSame(
            in_array($path, self::TEAM_PATHS, true),
            str_contains($html, 'No data yet'),
            'a team screen says how to load its data',
        );
        $this->assertFalse(str_contains($html, 'No driver under contract'), 'no claim about an unloaded team');
    }

    // ---------------------------------------------------------------- fixture

    private function synced(): void
    {
        $this->db->exec(
            "UPDATE users SET last_synced_at = datetime('now', '-8 days'), sync_status = 'idle' WHERE id = 1"
        );
    }

    /**
     * The payloads the last sync filed, a race window ago.
     *
     * @param array<string, mixed> $office merged into the archived office
     * @param array<string, mixed> $car merged into the archived car
     */
    private function archivePreviousWindow(array $office = [], array $car = []): void
    {
        $rows = [
            ['office', '', $office + [
                'name' => 'Alice', 'driId' => '77', 'tdId' => '', 'seasonNb' => 99, 'raceNb' => 4,
                'trackId' => 11, 'trackName' => 'Monza', 'tyreSupplierId' => 1, 'endOfSeason' => 0,
            ]],
            ['Calendar', '', ['events' => [
                ['eventType' => 'R', 'idx' => 4, 'trackId' => 11, 'trackName' => 'Monza'],
                ['eventType' => 'R', 'idx' => 5, 'trackId' => 4, 'trackName' => 'Monte Carlo'],
            ]]],
            ['UpdateCar', '', $car + [
                'carPower' => 40, 'carHandl' => 40, 'carAccel' => 40,
                'lvlEngine' => 3, 'lvlChassis' => 3, 'wearEngine' => 20, 'wearChassis' => 20,
            ]],
            ['RaceSetup', '', [
                'trackId' => 11, 'trackName' => 'Monza', 'trackPower' => 18, 'trackHandl' => 4, 'trackAccel' => 10,
                'weather' => [
                    'q1Temp' => 21, 'q2Temp' => 22, 'q1WeatherType' => 'Dry', 'q2WeatherType' => 'Dry',
                    'raceQ1TempLow' => 19, 'raceQ1TempHigh' => 24,
                ],
            ]],
            ['TrackProfile', '', [
                'trackName' => 'Monza', 'laps' => 53, 'power' => 18, 'handl' => 4, 'accel' => 10,
                'trackNotFoundNote' => false,
            ]],
            ['DriProfile', 'id=77', [
                'name' => 'Driver', 'concentration' => 211, 'talent' => 150, 'aggressiveness' => 60,
                'experience' => 90, 'techInsight' => 80, 'stamina' => 70, 'charisma' => 50,
                'motivation' => 99, 'weight' => 70, 'age' => 30,
            ]],
        ];
        foreach ($rows as [$endpoint, $params, $payload]) {
            $this->archiveRow($endpoint, $payload, $params);
        }
    }

    /** @param array<string, mixed> $payload */
    private function archiveRow(string $endpoint, array $payload, string $params = ''): void
    {
        $window = RaceWindow::raceStartIdFor(
            new DateTimeImmutable('-8 days'),
            RaceWindow::parseDays('2,5'),
        );
        $scope = GproApiClient::scopeFor(self::TOKEN);
        $this->archive->put(self::USER_ID, $scope, $endpoint, $params, $window, 99, 4, $payload);
    }

    private function wipeCache(): void
    {
        foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
    }

    private function assertNoFailure(string $html): void
    {
        $this->assertFalse(str_contains($html, 'data-deferred='), 'the screen waits behind a skeleton');
        $this->assertDoesNotMatchRegularExpression('/class="[^"]*\\bnotice-error\\b/', $html, 'an error notice');
        $logged = is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
        $this->assertSame('', $logged, 'a screen logged a failure');
    }

    // ----------------------------------------------------------------- wiring

    private function users(): UserRepository
    {
        return new UserRepository($this->db, new EmailCrypto(self::SECRET), new ApiTokenCrypto(self::SECRET));
    }

    private function client(): GproApiClient
    {
        return new GproApiClient($this->fetcher, new FilesystemCache($this->cacheDir), $this->archive);
    }

    /** One request: fresh services over the shared database, cache dir and archive, as a new PHP process has. */
    private function render(string $path): string
    {
        $config = [
            'app' => require dirname(__DIR__, 3) . '/config/game_constants.php',
            'secrets' => FakeSecrets::ALL,
            'settings' => ['is_dev' => false, 'db_file' => ''],
        ];
        $db = $this->db;
        $users = $this->users();
        $cache = new FilesystemCache($this->cacheDir);
        $client = new GproApiClient($this->fetcher, $cache, $this->archive);
        $authorize = new Authorize($users);
        $user = $users->findById(self::USER_ID);
        $this->assertNotNull($user);

        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'));
        $twig->addGlobal('app_version', 'test');
        $twig->addGlobal('canonical_host', 'https://example.test');
        $twig->addGlobal('no_pilot_message', StrategyController::NO_PILOT_MESSAGE);
        $twig->addGlobal('generic_error_message', StrategyController::GENERIC_ERROR_MESSAGE);
        $safeUser = $user;
        unset($safeUser['api_token']);
        $twig->addGlobal('is_logged_in', true);
        $twig->addGlobal('user', $safeUser);
        $twig->addGlobal('sync_state', SyncState::forUser($user));
        $twig->addGlobal('sync_stale', false);
        $twig->addGlobal('can_submit', true);
        $twig->addGlobal('api_limit', '?');
        $twig->addGlobal('api_limit_updated_at', null);
        $twig->addGlobal('csrf_token', 'csrf');
        $billboard = new BillboardService($client, $config['app']['divisions']);
        $twig->addFunction(new TwigFunction('billboard', static fn (): ?array => $billboard->forToken(self::TOKEN)));

        $snapshots = new RaceSnapshotReader($this->archive);
        $analyses = new AnalysisReader($this->archive);
        $tracks = new TrackRepository($db);
        $raceHistory = new RaceHistoryRepository($db);
        $telemetryRepo = new RaceTelemetryRepository($db);
        $historyService = new RaceHistoryService($raceHistory, new RaceHistoryMapper(), $tracks);
        $calculator = new PilotCalculatorService([], []);
        $carWear = new CarWearService($db, FakeSecrets::ALL);
        $fuel = new FuelModel(FakeSecrets::ALL);
        $planner = new SyncPlanner();
        $contexts = new SyncContextFactory($client, $this->archive);
        $endpoints = new EndpointFetcher($client);
        $sync = new GproSyncService($client, $users, $cache, $contexts, $endpoints, $planner);
        $harvest = new HarvestService(
            $client,
            $cache,
            $this->archive,
            $contexts,
            $endpoints,
            $planner,
            new GproApiThrottle($this->dir . '/manual-refresh-throttle-' . $this->id . '.json', 100.0, 100.0, 0),
            new ArchiveProjector(
                $db,
                new ProjectionStateRepository($db),
                new WeekendStateProjector($this->archive, new WeekendStateRepository($db), $snapshots),
                new StandingsProjector($this->archive, new StandingsRepository($db), $snapshots),
                new LedgerProjector($this->archive, new EconomyLedgerRepository($db), $analyses),
                $historyService,
                $snapshots,
                $analyses,
            ),
            $snapshots,
            new RaceTelemetryService($telemetryRepo, new RaceTelemetryMapper()),
            new BaselineAutoFillService($telemetryRepo, new PilotRepository($db), $calculator),
            $raceHistory,
        );
        $idealPilot = new IdealPilotService(new PilotRepository($db), $calculator, $config['app']['stats_schema']);
        $mapper = new GproDataMapper();
        $setup = new SetupCalculatorService($db, FakeSecrets::ALL);
        $weather = new RaceWeatherService();
        $risk = new RiskAdvisorService();
        $pha = new PhaMatchService();
        $wearAdvisor = new WearAdvisorService();
        $seasonCalendar = new SeasonCalendarService();
        $carWearPlanner = new CarWearPlannerService($carWear);
        $seasonBudgets = new SeasonBudgetRepository($db);
        $ctrCeiling = new CtrCeiling($carWear);

        $strategy = new StrategyController(
            new StrategyService($db, FakeSecrets::ALL, $fuel),
            $client,
            $mapper,
            $setup,
            $authorize,
            $weather,
            $risk,
            $pha,
            $carWear,
            $twig,
        );
        $page = new PageController(
            $idealPilot,
            new InsightService($config['app']['divisions']),
            new DivisionMetadataRepository($db),
            new TrainingService($db),
            $users,
            $client,
            $pha,
            $weather,
            new EarlyQualifyingBonusService(RaceWindow::parseDays('2,5')),
            $carWear,
            $wearAdvisor,
            new PartSwapAdvisorService(
                $carWear,
                $pha,
                new PartUpgradeAdvisorService($pha, []),
                new SwapLevelPicker(new SeasonBudget($carWear), []),
            ),
            new TestingProjectionService([], 0.5),
            new SponsorAdvisorService(),
            new TestingTargetsService(),
            $seasonCalendar,
            new TrainingAdvisorService([]),
            new TrainingWearProjectionService($carWear, $wearAdvisor),
            $strategy,
            new TestingController($client, $mapper, $setup, $carWear, []),
            new CarWearController($client, $mapper, $carWear, $carWearPlanner, $seasonCalendar),
            $mapper,
            new RecruitmentService($config['app']['csv_to_schema_map'], [], $idealPilot),
            $sync,
            $harvest,
            new RaceEngineerService(
                new SeasonObjectiveRepository($db),
                new StandingsRepository($db),
                new WeekendStateRepository($db),
                new EconomyLedgerRepository($db),
                $tracks,
                $carWear,
                $carWearPlanner,
                new CtrPlanner($ctrCeiling, new SeasonBudget($carWear)),
                $seasonBudgets,
                $ctrCeiling,
                $risk,
                new BoostFuelService(),
                $fuel,
                new UpgradeRanker(new SeasonBudget($carWear), []),
                new PartSwapAdvisorService(
                    $carWear,
                    $pha,
                    new PartUpgradeAdvisorService($pha, []),
                    new SwapLevelPicker(new SeasonBudget($carWear), []),
                ),
                $telemetryRepo,
                $idealPilot,
            ),
            false,
            $twig,
            $config,
        );

        $request = new Request([], [], [], ['REQUEST_URI' => $path, 'REQUEST_METHOD' => 'GET']);
        ob_start();
        try {
            match ($path) {
                '/season' => (new SeasonController(
                    new SeasonService(
                        $raceHistory,
                        new WeekendStateRepository($db),
                        new StandingsRepository($db),
                        new EconomyLedgerRepository($db),
                        $telemetryRepo,
                        $tracks,
                        new WearCalibration($carWear),
                        new FuelCalibration($fuel),
                        $seasonBudgets,
                    ),
                    $authorize,
                    $twig,
                    $config,
                ))->index($request),
                '/debrief' => (new DebriefController(
                    new DebriefService($raceHistory, $telemetryRepo, new RaceIntelligenceService($telemetryRepo)),
                    new RaceWeekendService(
                        $raceHistory,
                        new WeekendStateRepository($db),
                        new StandingsRepository($db),
                        new EconomyLedgerRepository($db),
                        $tracks,
                        new WearCalibration($carWear),
                        new FuelCalibration($fuel),
                    ),
                    $authorize,
                    $twig,
                    $config,
                ))->index($request),
                default => $page->index($request),
            };
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }
}
