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
use App\Database\DatabaseSeeder;
use App\Repository\ApiArchiveRepository;
use App\Repository\EconomyLedgerRepository;
use App\Repository\ProjectionStateRepository;
use App\Repository\RaceHistoryRepository;
use App\Repository\RaceTelemetryRepository;
use App\Repository\StandingsRepository;
use App\Repository\TrackRepository;
use App\Repository\UserRepository;
use App\Repository\WeekendStateRepository;
use App\Security\ApiTokenCrypto;
use App\Service\BaselineAutoFillService;
use App\Service\GproApiClient;
use App\Service\GproApiThrottle;
use App\Service\GproSyncService;
use App\Service\HarvestService;
use App\Service\PilotCalculatorService;
use App\Service\RaceHistoryService;
use App\Service\RaceTelemetryService;
use App\Sync\EndpointFetcher;
use App\Sync\HarvestResult;
use App\Sync\SyncContextFactory;
use App\Sync\SyncPlanner;
use App\Tests\Support\ArrayCache;
use App\Tests\Support\TtlRecordingCache;
use App\Tests\Support\ScriptedGproFetcher;
use App\Telemetry\RaceHistoryMapper;
use App\Telemetry\RaceTelemetryMapper;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HarvestService::class)]
#[CoversClass(HarvestResult::class)]
final class HarvestServiceTest extends TestCase
{
    private const array USER = ['id' => 1, 'api_token' => 'tok'];

    private string $dir;
    private string $dbPath;
    private string $throttleFile;
    private PDO $db;
    private ArrayCache $store;
    private TtlRecordingCache $cache;
    private ScriptedGproFetcher $gpro;
    private ApiArchiveRepository $archive;
    private GproApiClient $client;
    private mixed $priorRaceDays = null;
    private string|false $priorErrorLog = false;
    private string $quietLog = '';
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->priorRaceDays = $_ENV['GPRO_RACE_DAYS'] ?? null;
        $_ENV['GPRO_RACE_DAYS'] = '2,5';

        $this->dir = dirname(__DIR__, 3) . '/var/test';
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0750, true);
        }
        $id = uniqid('', true);
        $this->quietLog = $this->dir . '/harvest-quiet-' . $id . '.log';
        $this->priorErrorLog = ini_get('error_log');
        $this->dbPath = $this->dir . '/harvest-' . $id . '.sqlite';
        $this->throttleFile = $this->dir . '/harvest-throttle-' . $id . '.json';

        $this->db = new PDO('sqlite:' . $this->dbPath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('PRAGMA foreign_keys = ON');
        (new DatabaseSeeder(
            $this->db,
            ['Concentration' => 'concentration'],
            ['Rookie', 'Amateur', 'Pro', 'Master', 'Elite'],
            [],
            new ApiTokenCrypto('harvest-test-secret'),
        ))->migrate();
        $this->db->exec("INSERT INTO users (id, username, email_encrypted, email_hash) VALUES (1, 'u1', 'e1', 'h1')");

        $this->store = new ArrayCache();
        $this->cache = new TtlRecordingCache($this->store);
        $this->gpro = new ScriptedGproFetcher();
        $this->archive = new ApiArchiveRepository($this->db, new ArchiveSanitizer());
        $this->now = new \DateTimeImmutable('2026-10-23 12:00:00', new \DateTimeZone('Europe/Paris'));
        $this->client = new GproApiClient($this->gpro, $this->cache, $this->archive, fn (): \DateTimeImmutable => $this->now);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->priorErrorLog);
        if (is_file($this->quietLog)) {
            unlink($this->quietLog);
        }
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
        if (is_file($this->throttleFile)) {
            unlink($this->throttleFile);
        }
    }

    private function throttle(float $burst = 100.0, float $rate = 100.0): GproApiThrottle
    {
        return new GproApiThrottle($this->throttleFile, $rate, $burst, 0);
    }

    private function projector(): ArchiveProjector
    {
        $snapshots = new RaceSnapshotReader($this->archive);
        $analyses = new AnalysisReader($this->archive);

        return new ArchiveProjector(
            $this->db,
            new ProjectionStateRepository($this->db),
            new WeekendStateProjector($this->archive, new WeekendStateRepository($this->db), $snapshots),
            new StandingsProjector($this->archive, new StandingsRepository($this->db), $snapshots),
            new LedgerProjector($this->archive, new EconomyLedgerRepository($this->db), $analyses),
            $this->history(),
            $snapshots,
            $analyses,
        );
    }

    private function history(): RaceHistoryService
    {
        return new RaceHistoryService(
            new RaceHistoryRepository($this->db),
            new RaceHistoryMapper(),
            new TrackRepository($this->db),
        );
    }

    private function telemetry(): RaceTelemetryService
    {
        return new RaceTelemetryService(new RaceTelemetryRepository($this->db), new RaceTelemetryMapper());
    }

    private function autofill(): BaselineAutoFillService
    {
        return new BaselineAutoFillService(
            new RaceTelemetryRepository($this->db),
            new \App\Repository\PilotRepository($this->db),
            new PilotCalculatorService([], []),
        );
    }

    /** The services log through error_log(); keep the test run quiet unless a test captures it. */
    private function silenceLog(): void
    {
        ini_set('error_log', $this->quietLog);
    }

    private function harvest(?GproApiThrottle $throttle = null): HarvestService
    {
        $this->silenceLog();

        return new HarvestService(
            $this->client,
            $this->cache,
            $this->archive,
            new SyncContextFactory($this->client, $this->archive),
            new EndpointFetcher($this->client),
            new SyncPlanner(),
            $throttle ?? $this->throttle(),
            $this->projector(),
            new RaceSnapshotReader($this->archive),
            $this->telemetry(),
            $this->autofill(),
            new RaceHistoryRepository($this->db),
        );
    }

    private function sync(): void
    {
        $this->silenceLog();
        $sync = new GproSyncService(
            $this->client,
            $this->createStub(UserRepository::class),
            $this->cache,
            new SyncContextFactory($this->client, $this->archive),
            new EndpointFetcher($this->client),
            new SyncPlanner(),
        );
        $this->assertSame('synced', $sync->sync(self::USER, SyncPlanner::MODE_LOGIN)->status);
    }

    /**
     * The user played race 13, logged in, then logged in again at race 14: the
     * page-critical sync has run both times, the harvest has not.
     *
     * @param array<string, mixed> $office
     */
    private function userAtANewRace(array $office = []): void
    {
        $this->gpro->office = $office + $this->gpro->office;
        $this->gpro->office['raceNb'] = 13;
        $this->now = new \DateTimeImmutable('2026-10-20 12:00:00', new \DateTimeZone('Europe/Paris'));
        $this->sync();
        $this->gpro->office['raceNb'] = 14;
        $this->now = new \DateTimeImmutable('2026-10-23 12:00:00', new \DateTimeZone('Europe/Paris'));
        $this->sync();
        $this->gpro->calls = [];
        $this->gpro->requests = [];
    }

    /** Time passes: nothing archived or projected so far is "just now" any more. */
    private function settle(): void
    {
        $this->db->exec("UPDATE user_api_archive SET fetched_at = '2020-01-01 00:00:00'");
        $this->db->exec("UPDATE user_projection_state SET projected_at = '2020-06-01 00:00:00'");
    }

    private function rows(string $table): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    private function scopeKey(string $key): string
    {
        return 'u' . GproApiClient::scopeFor('tok') . ':' . $key;
    }

    public function testASliceFetchesTheRaceAnalysisStandingsAndEconomyThenProjectsWhatArrived(): void
    {
        $this->userAtANewRace();

        $result = $this->harvest()->runSlice(self::USER);

        $this->assertInstanceOf(HarvestResult::class, $result);
        $this->assertSame(
            ['RaceAnalysis', 'Standings', 'EconomyHistory?Page=1&show=table'],
            $this->gpro->requests,
        );
        $this->assertSame(3, $result->calls);
        $this->assertSame('more', $result->status);
        $this->assertSame(1, $this->rows('race_telemetry'));
        $this->assertGreaterThanOrEqual(1, $result->projected);
        $this->assertSame(1, $this->rows('user_race_history'));
        $this->assertSame(1, (int) $this->db->query('SELECT has_td FROM race_telemetry')->fetchColumn());
        $this->assertSame(27, (int) $this->db->query('SELECT driver_age FROM race_telemetry')->fetchColumn());
    }

    public function testTheNextSlicesFinishThePhaseTwoWorkAndThenThereIsNothingLeft(): void
    {
        $this->userAtANewRace();
        $harvest = $this->harvest();
        $harvest->runSlice(self::USER);
        $this->settle();

        $second = $harvest->runSlice(self::USER);

        $this->assertSame(['SFHistory?season=106', 'CCPHistory?season=106'], array_slice($this->gpro->requests, 3));
        $this->assertSame(2, $second->calls);
        $this->settle();

        $this->gpro->requests = [];
        $third = $harvest->runSlice(self::USER);

        $this->assertSame('done', $third->status);
        $this->assertSame(0, $third->calls);
        $this->assertSame(0, $third->projected);
        $this->assertSame([], $this->gpro->requests);
        $this->assertFalse($harvest->isDue(self::USER));
    }

    public function testAManagerWithoutATdFeedsTelemetryNoTd(): void
    {
        $this->userAtANewRace(['tdId' => '']);

        $this->harvest()->runSlice(self::USER);

        $this->assertSame(0, (int) $this->db->query('SELECT has_td FROM race_telemetry')->fetchColumn());
    }

    public function testADifferentCurrentDriverGivesTelemetryNoDriverAge(): void
    {
        $this->userAtANewRace(['driId' => '999']);

        $this->harvest()->runSlice(self::USER);

        $this->assertNull($this->db->query('SELECT driver_age FROM race_telemetry')->fetchColumn() ?: null);
    }

    public function testASliceNeverMakesMoreThanThreeCalls(): void
    {
        $this->userAtANewRace();

        $result = $this->harvest()->runSlice(self::USER);

        $this->assertSame(3, $result->calls);
        $this->assertCount(3, $this->gpro->requests);
    }

    public function testAnEmptyThrottleBucketMeansBusyAndNoCalls(): void
    {
        $this->userAtANewRace();

        $result = $this->harvest($this->throttle(burst: 0.5, rate: 0.001))->runSlice(self::USER);

        $this->assertSame('busy', $result->status);
        $this->assertSame(0, $result->calls);
        $this->assertSame([], $this->gpro->requests);
    }

    public function testABucketThatRunsDryMidSliceStopsCallingAndAsksToComeBack(): void
    {
        $this->userAtANewRace();
        $throttle = $this->throttle(burst: 1.0, rate: 0.0001);
        $this->gpro->onRequest = static fn (): float => $throttle->reserve(microtime(true));

        $result = $this->harvest($throttle)->runSlice(self::USER);

        $this->assertSame('more', $result->status);
        $this->assertSame(1, $result->calls);
        $this->assertSame(['RaceAnalysis'], $this->gpro->requests);
        $this->assertSame(1, $this->rows('race_telemetry'));
    }

    public function testASyncInFlightMeansLockedAndNoWorkAtAll(): void
    {
        $this->userAtANewRace();
        $this->cache->set('sync_lock_1', time(), 60);

        $result = $this->harvest()->runSlice(self::USER);

        $this->assertSame('locked', $result->status);
        $this->assertSame(0, $result->calls);
        $this->assertSame(0, $result->projected);
        $this->assertSame([], $this->gpro->requests);
    }

    public function testAnotherHarvestInFlightMeansLockedAndTheLockIsReleasedAfterwards(): void
    {
        $this->userAtANewRace();
        $this->cache->set('harvest_lock_1', time(), 60);
        $harvest = $this->harvest();

        $this->assertSame('locked', $harvest->runSlice(self::USER)->status);
        $this->assertSame([], $this->gpro->requests);

        $this->cache->delete('harvest_lock_1');
        $harvest->runSlice(self::USER);
        $this->assertFalse($this->cache->has('harvest_lock_1'));
    }

    public function testWithoutAKnownBudgetASliceOnlyProjects(): void
    {
        $this->userAtANewRace();
        $this->cache->delete($this->scopeKey('api_requests_remaining'));

        $result = $this->harvest()->runSlice(self::USER);

        $this->assertSame(0, $result->calls);
        $this->assertSame([], $this->gpro->requests);
        $this->assertGreaterThanOrEqual(1, $result->projected);
    }

    public function testWithoutACachedOfficeASliceOnlyProjects(): void
    {
        $this->userAtANewRace();
        $this->cache->delete($this->scopeKey('office_data'));

        $result = $this->harvest()->runSlice(self::USER);

        $this->assertSame(0, $result->calls);
        $this->assertSame([], $this->gpro->requests);
        $this->assertGreaterThanOrEqual(1, $result->projected);
    }

    public function testProjectionOnlyWorkProjectsAtMostFiveRacesPerSlice(): void
    {
        $this->cache->set($this->scopeKey('api_requests_remaining'), 90);
        for ($race = 1; $race <= 8; $race++) {
            $this->archive->put(1, GproApiClient::scopeFor('tok'), 'Standings', '', "w{$race}", 107, $race, ['data' => []]);
        }
        $harvest = $this->harvest();

        $first = $harvest->runSlice(self::USER);

        $this->assertSame(0, $first->calls);
        $this->assertSame(5, $first->projected);
        $this->assertSame('more', $first->status);
        $this->assertTrue($harvest->isDue(self::USER));
        $this->settle();

        $second = $harvest->runSlice(self::USER);

        $this->assertSame(3, $second->projected);
        $this->assertSame('done', $second->status);
    }

    /** Everything the harvest would fetch for this race is already archived, except backfill. */
    private function userWithOnlyBackfillLeft(): void
    {
        $races = [];
        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 13] as $race) {
            $races[] = [
                'value' => "107,{$race}", 'season' => 107, 'race' => $race,
                'trackName' => 'Track', 'natCode' => 'xx', 'unlocked' => $race === 3 ? 0 : 1, 'group' => 'R1',
            ];
            $this->gpro->responses['RaceAnalysis?SR=107,' . $race] = array_merge(
                $this->gpro->analysis,
                ['selRaceNb' => (string) $race],
            );
        }
        $this->userAtANewRace();
        $scope = GproApiClient::scopeFor('tok');
        $this->archive->put(1, $scope, 'RaceAnalysis', '', 'w', 107, 13, ['racesToSelect' => $races] + $this->gpro->analysis);
        $this->archive->put(1, $scope, 'Standings', '', 'w', 107, 14, ['data' => []]);
        $this->archive->put(1, $scope, 'EconomyHistory', 'Page=1&show=table', 'w', 107, 14, ['data' => []]);
        $this->archive->put(1, $scope, 'SFHistory', 'season=106', '', 107, 14, ['data' => []]);
        $this->archive->put(1, $scope, 'CCPHistory', 'season=106', '', 107, 14, ['data' => []]);
        $this->settle();
        $this->gpro->requests = [];
    }

    public function testSevenPendingBackfillsAtBudgetNinetyTakeThreeCallsInTheFirstSlice(): void
    {
        $this->userWithOnlyBackfillLeft();

        $result = $this->harvest()->runSlice(self::USER);

        $this->assertSame(3, $result->calls);
        $this->assertSame('more', $result->status);
        $this->assertSame(
            ['RaceAnalysis?SR=107,1', 'RaceAnalysis?SR=107,2', 'RaceAnalysis?SR=107,4'],
            $this->gpro->requests,
        );
    }

    public function testBackfillIsCappedAtFiveCallsPerWindowAcrossSlicesAndSkipsLockedAndKnownRaces(): void
    {
        $this->userWithOnlyBackfillLeft();
        $harvest = $this->harvest();

        $perSlice = [];
        for ($i = 0; $i < 10; $i++) {
            $result = $harvest->runSlice(self::USER);
            $perSlice[] = $result->calls;
            $this->settle();
            if ($result->status === 'done') {
                break;
            }
        }

        $this->assertSame('done', $result->status);
        $this->assertSame([3, 2, 0], $perSlice);
        $this->assertSame(
            ['RaceAnalysis?SR=107,1', 'RaceAnalysis?SR=107,2', 'RaceAnalysis?SR=107,4', 'RaceAnalysis?SR=107,5', 'RaceAnalysis?SR=107,6'],
            $this->gpro->requests,
        );
        $this->assertSame(5, $this->client->backfillCount());
        // No snapshot was archived for any of those races: their TD status is unknown.
        $this->assertSame(0, $this->rows('race_telemetry'));
    }

    public function testBackfillSkipsRacesAlreadyInTheManagersHistory(): void
    {
        $this->userWithOnlyBackfillLeft();
        foreach (['1', '2'] as $race) {
            $this->history()->archive(1, array_merge($this->gpro->analysis, ['selRaceNb' => $race]));
        }

        $this->harvest()->runSlice(self::USER);

        $this->assertSame(
            ['RaceAnalysis?SR=107,4', 'RaceAnalysis?SR=107,5', 'RaceAnalysis?SR=107,6'],
            $this->gpro->requests,
        );
    }

    public function testBackfillTelemetryIsWrittenOnlyWhenThatRacesTdStatusIsKnown(): void
    {
        $this->userWithOnlyBackfillLeft();
        $scope = GproApiClient::scopeFor('tok');
        // Race 1: the office stamped for it says no TD. Race 2: a TD and that race's profile.
        // Race 4: nothing archived says whether there was a TD.
        $this->archive->put(1, $scope, 'office', '', 'w1', 107, 1, ['tdId' => ''] + $this->gpro->office);
        $this->archive->put(1, $scope, 'office', '', 'w2', 107, 2, $this->gpro->office);
        $this->archive->put(1, $scope, 'TDProfile', '', 'w2', 107, 2, $this->gpro->td);

        $result = $this->harvest()->runSlice(self::USER);

        $this->assertSame(
            ['RaceAnalysis?SR=107,1', 'RaceAnalysis?SR=107,2', 'RaceAnalysis?SR=107,4'],
            $this->gpro->requests,
        );
        $this->assertSame(3, $result->calls);
        $this->assertSame(2, $this->rows('race_telemetry'));
        $this->assertSame(
            [0, 1],
            array_map('intval', $this->db->query('SELECT has_td FROM race_telemetry ORDER BY has_td')->fetchAll(PDO::FETCH_COLUMN)),
        );
        $this->assertSame(
            3,
            (int) $this->db->query('SELECT COUNT(*) FROM user_race_history WHERE race IN (1, 2, 4)')->fetchColumn(),
            'history still projects the race whose TD status is unknown',
        );
    }

    public function testAnOfficeNamingATdWithoutThatRacesProfileWritesNoTelemetry(): void
    {
        $this->userAtANewRace();
        $this->db->exec("DELETE FROM user_api_archive WHERE endpoint = 'TDProfile'");

        $this->harvest()->runSlice(self::USER);

        $this->assertSame(0, $this->rows('race_telemetry'));
        $this->assertSame(1, $this->rows('user_race_history'));
    }

    public function testBackfillBudgetOfOneWindowIsSpentForThatUser(): void
    {
        $this->userWithOnlyBackfillLeft();
        $this->client->setToken('tok', 1);
        for ($i = 0; $i < 5; $i++) {
            $this->client->addBackfill();
        }

        $result = $this->harvest()->runSlice(self::USER);

        $this->assertSame(0, $result->calls);
        $this->assertSame('done', $result->status);
    }

    public function testANonSupporterAnswerIsArchivedNotReplannedAndNotAnError(): void
    {
        $this->gpro->responses['SFHistory?season=106'] = ['errorMsg' => 'Supporters only', 'data' => []];
        $this->userAtANewRace();
        $harvest = $this->harvest();

        $statuses = [];
        for ($i = 0; $i < 4; $i++) {
            $statuses[] = $harvest->runSlice(self::USER)->status;
            $this->settle();
        }

        $this->assertSame(1, count(array_keys($this->gpro->allRequests, 'SFHistory?season=106', true)));
        $this->assertSame('done', end($statuses));
        $this->assertNotContains('failed', $statuses);
        $this->assertNotNull($this->archive->latest(1, 'SFHistory', 'season=106'));
        $this->assertSame(['season=106'], $this->archive->paramsFor(1, 'SFHistory'));
    }

    public function testAFailingFetchIsLoggedByClassOnlyAndTheSliceContinues(): void
    {
        $this->userAtANewRace();
        $this->gpro->failing = ['Standings'];
        $log = $this->dir . '/harvest-log-' . uniqid('', true);
        $harvest = $this->harvest();
        ini_set('error_log', $log);

        $result = $harvest->runSlice(self::USER);
        $this->silenceLog();
        $lines = (string) file_get_contents($log);
        @unlink($log);

        $this->assertSame(3, $result->calls);
        $this->assertContains('EconomyHistory?Page=1&show=table', $this->gpro->requests);
        $this->assertStringContainsString('[Harvest] user 1: STANDINGS failed: RuntimeException', $lines);
        $this->assertStringNotContainsString('scripted failure', $lines);
        $this->assertMatchesRegularExpression('/\[Harvest\] user 1: (more|done|busy), 3 calls, \d+ projected, \d+ ms/', $lines);
    }

    public function testAUserWithoutATokenHasNothingToHarvest(): void
    {
        $result = $this->harvest()->runSlice(['id' => 1, 'api_token' => '']);

        $this->assertSame('done', $result->status);
        $this->assertSame(0, $result->calls);
        $this->assertSame([], $this->gpro->requests);
    }

    public function testIsDueWhenPhaseTwoWorkOrPendingProjectionsRemain(): void
    {
        $this->userAtANewRace();
        $harvest = $this->harvest();

        $this->assertTrue($harvest->isDue(self::USER));
        $this->assertSame([], $this->gpro->requests, 'isDue never calls the API');
    }

    public function testIsDueSafelyReadsAStorageErrorAsNothingDue(): void
    {
        $this->userAtANewRace();
        $harvest = $this->harvest();
        $this->assertTrue($harvest->isDueSafely(self::USER));
        $this->db->exec('DROP TABLE user_api_archive');

        $this->assertFalse($harvest->isDueSafely(self::USER));
        $this->assertStringContainsString('[Harvest] isDue failed: PDOException', (string) file_get_contents($this->quietLog));
        $this->assertStringNotContainsString('SQLSTATE', (string) file_get_contents($this->quietLog));
    }

    public function testIsDueIsFalseWithoutABudgetOrAnOfficeToSpendItOn(): void
    {
        $this->userAtANewRace();
        $harvest = $this->harvest();
        // Pending projections exist, so clear them first.
        $harvest->runSlice(self::USER);
        $harvest->runSlice(self::USER);
        $this->settle();
        $this->cache->delete($this->scopeKey('api_requests_remaining'));

        $this->assertFalse($harvest->isDue(self::USER));
    }

    public function testFetchesThatFailAreRememberedForAnHourSoLaterEntriesAreNotStarved(): void
    {
        $this->userAtANewRace();
        $this->gpro->failing = ['RaceAnalysis', 'Standings', 'EconomyHistory'];
        $harvest = $this->harvest();

        $first = $harvest->runSlice(self::USER);

        $this->assertSame(3, $first->calls);
        $this->assertSame('more', $first->status);
        $this->settle();
        $this->gpro->requests = [];

        $second = $harvest->runSlice(self::USER);

        $this->assertSame(['SFHistory?season=106', 'CCPHistory?season=106'], $this->gpro->requests);
        $this->assertSame(2, $second->calls);
        $this->settle();
        $this->gpro->requests = [];

        $third = $harvest->runSlice(self::USER);

        $this->assertSame('done', $third->status);
        $this->assertSame(0, $third->calls);
        $this->assertFalse($harvest->isDue(self::USER));
        $failedKeys = array_filter(array_keys($this->store->store), static fn (string $k): bool => str_contains($k, 'harvest_failed'));
        $this->assertCount(3, $failedKeys);
        foreach ($failedKeys as $key) {
            $this->assertSame(3600, $this->cache->ttls[$key]);
        }

        // An hour on, the cache has forgotten them: they are planned again.
        foreach ($failedKeys as $key) {
            $this->cache->delete($key);
        }
        $this->assertTrue($harvest->isDue(self::USER));
        $this->gpro->failing = [];

        $fourth = $harvest->runSlice(self::USER);

        // The analysis of a race that is over counts as asked once it was, so only the other two return.
        $this->assertSame(2, $fourth->calls);
        $this->assertSame(['Standings', 'EconomyHistory?Page=1&show=table'], $this->gpro->requests);
    }

    /** A thrown analysis at $parisTime, then the hour-long failure memo expires: is the analysis asked again? */
    private function analysisAskedAgainAfterAThrow(string $parisTime): bool
    {
        $this->userAtANewRace();
        $this->now = new \DateTimeImmutable($parisTime, new \DateTimeZone('Europe/Paris'));
        $this->gpro->failing = ['RaceAnalysis'];
        $harvest = $this->harvest();
        $harvest->runSlice(self::USER);
        $this->assertContains('RaceAnalysis', $this->gpro->requests);

        foreach (array_keys($this->store->store) as $key) {
            if (str_contains($key, 'harvest_failed')) {
                $this->cache->delete($key);
            }
        }
        $this->gpro->failing = [];
        $this->gpro->requests = [];
        $this->settle();
        $harvest->runSlice(self::USER);

        return in_array('RaceAnalysis', $this->gpro->requests, true);
    }

    public function testAThrownAnalysisAfterTheRaceLeavesAMarkerAndIsNotAskedAgain(): void
    {
        $this->assertFalse($this->analysisAskedAgainAfterAThrow('2026-10-23 21:30'));
        $this->assertTrue($this->cache->has($this->scopeKey('analysis_attempt:w2026-10-23')));
    }

    public function testAThrownAnalysisBeforeTheRaceEndedLeavesNoMarkerAndIsAskedAgain(): void
    {
        $this->assertTrue($this->analysisAskedAgainAfterAThrow('2026-10-23 20:30'));
        $this->assertFalse($this->cache->has($this->scopeKey('analysis_attempt:w2026-10-23')));
    }

    public function testANonSupporterAnalysisIsNeitherAFailureNorRecorded(): void
    {
        $this->gpro->responses['RaceAnalysis'] = ['errorMsg' => 'Supporters only', 'data' => []];
        $this->userAtANewRace();

        $result = $this->harvest()->runSlice(self::USER);

        $this->assertContains('RaceAnalysis', $this->gpro->requests);
        $this->assertNotSame('busy', $result->status);
        $this->assertSame(0, $this->rows('race_telemetry'));
        $this->assertSame(0, $this->rows('user_race_history'));
    }

    public function testAFailedBackfillIsNotRetriedStraightAway(): void
    {
        $this->userWithOnlyBackfillLeft();
        $this->gpro->failing = ['RaceAnalysis'];
        $harvest = $this->harvest();

        $first = $harvest->runSlice(self::USER);
        $this->gpro->requests = [];
        $second = $harvest->runSlice(self::USER);

        $this->assertSame(3, $first->calls);
        $this->assertSame(2, $second->calls);
        $this->assertNotContains('RaceAnalysis?SR=107,1', $this->gpro->requests);
        $this->assertSame(['RaceAnalysis?SR=107,5', 'RaceAnalysis?SR=107,6'], $this->gpro->requests);
    }

    public function testACachedOfficeForAnotherRaceThanTheSyncOfficeMeansProjectionOnly(): void
    {
        $this->userAtANewRace();
        $key = $this->scopeKey('office_data');
        $office = $this->store->get($key);
        $this->assertIsArray($office);
        $office['raceNb'] = 15;
        $this->store->set($key, $office);
        $harvest = $this->harvest();

        $result = $harvest->runSlice(self::USER);

        $this->assertSame(0, $result->calls);
        $this->assertSame([], $this->gpro->requests);
        $this->assertGreaterThanOrEqual(1, $result->projected);
        $this->settle();
        $this->assertFalse($harvest->isDue(self::USER));
    }

    public function testTheBackfillAllowanceStartsAgainInTheNextRaceWindow(): void
    {
        $this->userWithOnlyBackfillLeft();
        for ($i = 0; $i < 5; $i++) {
            $this->client->addBackfill();
        }
        $harvest = $this->harvest();
        $this->assertSame(0, $harvest->runSlice(self::USER)->calls);

        $this->now = new \DateTimeImmutable('2026-10-27 12:00:00', new \DateTimeZone('Europe/Paris'));
        $this->settle();

        $this->assertSame(0, $this->client->backfillCount());
        $result = $harvest->runSlice(self::USER);

        $this->assertSame(3, $result->calls);
        $this->assertSame('RaceAnalysis?SR=107,1', $this->gpro->requests[0]);
    }
}
