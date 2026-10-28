<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Archive\ArchiveSanitizer;
use App\Cache\Adapter\FilesystemCache;
use App\Repository\ApiArchiveRepository;
use App\Repository\UserRepository;
use App\Service\GproApiClient;
use App\Service\GproSyncService;
use App\Sync\Endpoint;
use App\Sync\EndpointFetcher;
use App\Sync\SyncContextFactory;
use App\Sync\SyncOutcome;
use App\Sync\SyncPlanner;
use App\Tests\Support\ScriptedGproFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(GproSyncService::class)]
final class GproSyncServiceTest extends TestCase
{
    private string $cacheDir;
    private FilesystemCache $cache;
    private mixed $priorRaceDays = null;
    private string|false $priorErrorLog = false;
    private string $quietLog = '';

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/gpro_sync_' . bin2hex(random_bytes(6));
        $logDir = dirname(__DIR__, 3) . '/var/test';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0750, true);
        }
        $this->quietLog = $logDir . '/sync-quiet-' . bin2hex(random_bytes(6)) . '.log';
        $this->cache = new FilesystemCache($this->cacheDir);
        $this->priorRaceDays = $_ENV['GPRO_RACE_DAYS'] ?? null;
        $_ENV['GPRO_RACE_DAYS'] = '';
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->priorErrorLog);
        @unlink($this->quietLog);
        if ($this->priorRaceDays === null) {
            unset($_ENV['GPRO_RACE_DAYS']);
        } else {
            $_ENV['GPRO_RACE_DAYS'] = $this->priorRaceDays;
        }
        foreach (glob($this->cacheDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->cacheDir);
    }

    private function apiClient(): GproApiClient
    {
        // Pointed at an unreachable host so any real call fails fast. The
        // coalescing path never calls it; the "ran" path does and is expected
        // to land in the failed branch.
        $fetcher = new \App\Service\GproApiFetcher(['base_url' => 'http://127.0.0.1:9']);
        return new GproApiClient($fetcher, $this->cache);
    }

    /**
     * Both write paths are observational and must not influence sync
     * outcomes. Each service is final (neither is a seam), so wire real ones
     * over a throwaway in-memory schema rather than doubling them.
     */
    private function sink(): \PDO
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        (new \App\Database\DatabaseSeeder(
            $db,
            ['Concentration' => 'concentration'],
            ['Rookie'],
            [],
            new \App\Security\ApiTokenCrypto('sync-test-secret'),
        ))->migrate();

        return $db;
    }

    private function service(UserRepository $users, ?GproApiClient $client = null, ?\PDO $db = null, int $margin = 20): GproSyncService
    {
        // The service logs through error_log(); PHPUnit swaps that setting per
        // test, so it is redirected here, inside the test. Tests that read the
        // log redirect it again themselves.
        $prior = ini_set('error_log', $this->quietLog);
        if ($this->priorErrorLog === false) {
            $this->priorErrorLog = $prior;
        }
        $db ??= $this->sink();
        $client ??= $this->apiClient();

        return new GproSyncService(
            $client,
            $users,
            $this->cache,
            new SyncContextFactory($client, new ApiArchiveRepository($db, new ArchiveSanitizer())),
            new EndpointFetcher($client),
            new SyncPlanner(),
            $margin,
        );
    }

    /**
     * A service wired to a scripted GPRO over a real archive, so tests can
     * count exactly which endpoints a sync spent calls on.
     *
     * @return array{GproSyncService, ScriptedGproFetcher, \PDO, GproApiClient}
     */
    private function scripted(?UserRepository $users = null, ?\DateTimeImmutable $now = null): array
    {
        $db = $this->sink();
        $fetcher = new ScriptedGproFetcher();
        $client = new GproApiClient(
            $fetcher,
            $this->cache,
            new ApiArchiveRepository($db, new ArchiveSanitizer()),
            $now === null ? null : static fn (): \DateTimeImmutable => $now,
        );

        return [$this->service($users ?? $this->createStub(UserRepository::class), $client, $db), $fetcher, $db, $client];
    }

    /**
     * What the idle harvest does with RaceAnalysis: fetch it through the same
     * fetcher, which archives the answer. The harvest opens the gate for its
     * fetches; this drives the fetcher directly, so it opens it here.
     */
    private function harvestAnswersTheAnalysis(GproApiClient $client): void
    {
        $client->withFetching(static fn (): array => (new EndpointFetcher($client))->fetch(Endpoint::ANALYSIS));
    }

    /** Whether the harvest that follows this sync would still ask for the race analysis. */
    private function harvestPlansAnalysis(GproApiClient $client, \PDO $db, ScriptedGproFetcher $fetcher): bool
    {
        $office = $fetcher->office;
        $ctx = (new SyncContextFactory($client, new ApiArchiveRepository($db, new ArchiveSanitizer())))
            ->build(7, $client->tokenScope(), $office, $office, 90, SyncPlanner::MODE_HARVEST);

        return in_array(Endpoint::ANALYSIS, array_column((new SyncPlanner())->plan($ctx)->phase2, 'endpoint'), true);
    }

    /** @return list<string> */
    private static function sorted(array $names): array
    {
        sort($names);

        return array_values($names);
    }

    public function testNoTokenSetsNeedsTokenAndNeverSyncs(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())
            ->method('updateSyncStatus')
            ->with(7, 'needs_token');

        $svc = $this->service($users);
        $this->assertSame('needs_token', $svc->trySyncForUser(['id' => 7, 'api_token' => '']));
    }

    public function testConcurrentSyncIsCoalesced(): void
    {
        // Simulate a sync already in flight by pre-setting the lock.
        $this->cache->set('sync_lock_7', time(), 60);

        $users = $this->createMock(UserRepository::class);
        // The coalesced call must NOT touch status or start work.
        $users->expects($this->never())->method('updateSyncStatus');
        $users->expects($this->never())->method('markSynced');

        $svc = $this->service($users);
        $this->assertSame('in_progress', $svc->trySyncForUser(['id' => 7, 'api_token' => 'tok']));
    }

    public function testDefersWhenBudgetBelowMargin(): void
    {
        // Margin 20 → anything under 21 remaining defers before a single call.
        $this->cache->set(GproApiClient::budgetKeyForToken('tok'), 15, 3600);

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())
            ->method('updateSyncStatus')
            ->with(7, 'deferred_low_budget');
        $users->expects($this->never())->method('markSynced');

        $svc = $this->service($users, margin: 20);
        $this->assertSame('deferred_low_budget', $svc->trySyncForUser(['id' => 7, 'api_token' => 'tok']));

        // Deferring must not leave a lock behind.
        $this->assertFalse($this->cache->has('sync_lock_7'));
    }

    public function testProceedsWhenBudgetAboveMargin(): void
    {
        // 50 remaining is above the 21 floor, so the sync proceeds (and fails
        // against the unreachable host, which is fine — it still ran).
        $this->cache->set(GproApiClient::budgetKeyForToken('tok'), 50, 3600);

        $users = $this->createStub(UserRepository::class);
        $statuses = [];
        $users->method('updateSyncStatus')
            ->willReturnCallback(function (int $id, string $status) use (&$statuses): void {
                $statuses[] = $status;
            });

        $svc = $this->service($users, margin: 20);
        $result = $svc->trySyncForUser(['id' => 7, 'api_token' => 'tok']);

        $this->assertContains('running', $statuses, 'sufficient budget must start the sync');
        $this->assertNotContains('deferred_low_budget', $statuses);
        // Unreachable host → the sync body runs but ends in failure.
        $this->assertSame('failed', $result);
    }

    public function testFirstEverSyncProceedsWhenBudgetUnknown(): void
    {
        // No API_LIMIT_KEY in cache → null → allow (it's how we learn the budget).
        $users = $this->createStub(UserRepository::class);
        $statuses = [];
        $users->method('updateSyncStatus')
            ->willReturnCallback(function (int $id, string $status) use (&$statuses): void {
                $statuses[] = $status;
            });

        $svc = $this->service($users, margin: 20);
        $svc->trySyncForUser(['id' => 7, 'api_token' => 'tok']);

        $this->assertContains('running', $statuses);
    }

    public function testLockIsReleasedAfterRun(): void
    {
        $users = $this->createStub(UserRepository::class);

        $svc = $this->service($users);
        $svc->trySyncForUser(['id' => 7, 'api_token' => 'tok']);

        // Whether the sync succeeded or failed, the lock must be gone so the
        // next attempt isn't blocked.
        $this->assertFalse(
            $this->cache->has('sync_lock_7'),
            'lock must be released in the finally block',
        );
    }

    public function testRunSetsRunningThenFailsOnUnreachableApi(): void
    {
        $users = $this->createStub(UserRepository::class);
        $statuses = [];
        $users->method('updateSyncStatus')
            ->willReturnCallback(function (int $id, string $status) use (&$statuses): void {
                $statuses[] = $status;
            });

        $svc = $this->service($users);
        $svc->trySyncForUser(['id' => 7, 'api_token' => 'tok']);

        $this->assertSame('running', $statuses[0] ?? null, 'first status must be running');
        $this->assertContains('failed', $statuses, 'unreachable API must end in failed');
    }

    private const array USER = ['id' => 7, 'api_token' => 'tok'];

    public function testTheSyncNeverFetchesTheAnalysisNorWritesHistoryOrTelemetry(): void
    {
        [$svc, $fetcher, $db] = $this->scripted();

        $outcome = $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertSame('synced', $outcome->status);
        $this->assertNotContains('RaceAnalysis', $fetcher->calls, 'RaceAnalysis is the idle harvest\'s');
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM user_race_history')->fetchColumn());
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM race_telemetry')->fetchColumn());
    }

    public function testFirstLoginSpendsTheFullSnapshotAndRecordsTheOutcome(): void
    {
        [$svc, $fetcher] = $this->scripted();

        $outcome = $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertInstanceOf(SyncOutcome::class, $outcome);
        $this->assertSame('synced', $outcome->status);
        $this->assertSame(
            self::sorted([
                'office', 'DriProfile', 'UpdateCar', 'TrackProfile', 'RaceSetup', 'Testing', 'StaffAndFacilities',
                'TDProfile', 'NegOverview', 'MoneyLevels', 'Calendar', 'TyreSuppliers', 'Menu', 'Tracks',
            ]),
            self::sorted($fetcher->calls),
        );
        $this->assertSame(count($fetcher->calls), $outcome->calls);
        $this->assertContains('OFFICE', $outcome->fetched);
        $this->assertNotContains('ANALYSIS', $outcome->fetched);
    }

    public function testUnchangedReloginCostsExactlyTheOfficeCall(): void
    {
        [$svc, $fetcher] = $this->scripted();
        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);
        $fetcher->calls = [];

        $outcome = $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertSame(['office'], $fetcher->calls);
        $this->assertSame('synced', $outcome->status);
        $this->assertSame(['OFFICE'], $outcome->fetched);
        $this->assertSame(1, $outcome->calls);
    }

    public function testWarmSharedTyreFeedDoesNotStopANewUserFetchingAndArchivingItOnce(): void
    {
        [$svc, $fetcher, $db] = $this->scripted();
        $this->cache->set('tyre_suppliers', ['suppliers' => []], 3600);

        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertContains('TyreSuppliers', $fetcher->calls, 'a warm shared feed is not this user\'s record');
        $row = $db->query("SELECT user_id, season FROM user_api_archive WHERE endpoint = 'TyreSuppliers'")
            ->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['user_id' => 7, 'season' => 107], $row);

        $fetcher->calls = [];
        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);
        $this->assertSame(['office'], $fetcher->calls, 'asked once per season, then archived');
    }

    public function testRowsArchivedUnderAnotherTokenAreNotTreatedAsCurrent(): void
    {
        [$svc, $fetcher] = $this->scripted();
        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);
        $fetcher->calls = [];

        $swapped = $svc->sync(['id' => 7, 'api_token' => 'another-account'], SyncPlanner::MODE_LOGIN);

        $this->assertSame('synced', $swapped->status);
        $this->assertSame(
            self::sorted([
                'office', 'DriProfile', 'UpdateCar', 'TrackProfile', 'RaceSetup', 'Testing', 'StaffAndFacilities',
                'TDProfile', 'NegOverview', 'MoneyLevels', 'Calendar', 'TyreSuppliers', 'Menu', 'Tracks',
            ]),
            self::sorted($fetcher->calls),
            'the previous account\'s rows must not suppress the new account\'s first snapshot',
        );
    }

    public function testDoneRaceSetupFlipFetchesOnlyTheSetup(): void
    {
        [$svc, $fetcher] = $this->scripted();
        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);
        $fetcher->calls = [];
        $fetcher->office['doneRaceSetup'] = true;

        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertSame(['office', 'RaceSetup'], $fetcher->calls);
    }

    public function testManualSyncRefreshesCarAndSetupEvenWhenNothingChanged(): void
    {
        [$svc, $fetcher] = $this->scripted();
        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);
        $fetcher->calls = [];

        $svc->sync(self::USER, SyncPlanner::MODE_MANUAL);

        $this->assertSame(['office', 'UpdateCar', 'RaceSetup'], $fetcher->calls);
    }

    public function testTrySyncForUserMapsForceToManualAndNotForceToLogin(): void
    {
        [$svc, $fetcher] = $this->scripted();
        $this->assertSame('synced', $svc->trySyncForUser(self::USER, false));
        $fetcher->calls = [];

        $this->assertSame('synced', $svc->trySyncForUser(self::USER, true));
        $this->assertSame(['office', 'UpdateCar', 'RaceSetup'], $fetcher->calls);

        $fetcher->calls = [];
        $this->assertSame('synced', $svc->trySyncForUser(self::USER, false));
        $this->assertSame(['office'], $fetcher->calls);
    }

    public function testAFailingPlannedEndpointDoesNotFailTheSync(): void
    {
        [$svc, $fetcher] = $this->scripted();
        $fetcher->failing = ['Testing'];

        $outcome = $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertSame('synced', $outcome->status);
        $this->assertNotContains('TESTING', $outcome->fetched);
        $this->assertContains('SETUP', $outcome->fetched);
    }

    public function testFailedEndpointLogsClassOnlyAndNeverTheMessage(): void
    {
        [$svc, $fetcher] = $this->scripted();
        $fetcher->failing = ['Testing'];
        $log = sys_get_temp_dir() . '/gpro_sync_log_' . bin2hex(random_bytes(4));
        $prior = ini_set('error_log', $log);

        try {
            $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);
        } finally {
            ini_set('error_log', (string) $prior);
        }
        $lines = (string) file_get_contents($log);
        @unlink($log);

        $this->assertStringContainsString('[GproSync] user 7: TESTING failed: RuntimeException', $lines);
        $this->assertStringNotContainsString('scripted failure', $lines);
        $this->assertStringNotContainsString('tok', str_replace('[GproSync]', '', $lines));
        $this->assertMatchesRegularExpression('/\[GproSync\] user 7 mode login plan: \d+ calls \(.+\) in \d+ ms/', $lines);
    }

    public function testOfficeFailureFailsTheSyncWithoutFurtherCalls(): void
    {
        $users = $this->createMock(UserRepository::class);
        $statuses = [];
        $users->method('updateSyncStatus')
            ->willReturnCallback(function (int $id, string $status) use (&$statuses): void {
                $statuses[] = $status;
            });
        $users->expects($this->never())->method('markSynced');
        [$svc, $fetcher] = $this->scripted($users);
        $fetcher->failing = ['office'];

        $outcome = $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertSame('failed', $outcome->status);
        $this->assertSame(['office'], $fetcher->calls);
        $this->assertSame(['running', 'failed'], $statuses);
        $this->assertFalse($this->cache->has('sync_lock_7'));
    }

    public function testFreshOfficeBudgetBelowTheFloorDefersAfterTheOfficeCall(): void
    {
        $users = $this->createMock(UserRepository::class);
        $statuses = [];
        $users->method('updateSyncStatus')
            ->willReturnCallback(function (int $id, string $status) use (&$statuses): void {
                $statuses[] = $status;
            });
        $users->expects($this->never())->method('markSynced');
        [$svc, $fetcher] = $this->scripted($users);
        $fetcher->remaining = 15;

        $outcome = $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertSame('deferred_low_budget', $outcome->status);
        $this->assertSame(['office'], $fetcher->calls);
        $this->assertSame('deferred_low_budget', end($statuses));
        $this->assertFalse($this->cache->has('sync_lock_7'));
    }

    public function testLoginFloorLimitsTheSpendToTheBudgetAboveFiftyAndReportsItHonestly(): void
    {
        $users = $this->createMock(UserRepository::class);
        $statuses = [];
        $users->method('updateSyncStatus')
            ->willReturnCallback(function (int $id, string $status) use (&$statuses): void {
                $statuses[] = $status;
            });
        $users->expects($this->never())->method('markSynced');
        [$svc, $fetcher] = $this->scripted($users);
        $fetcher->remaining = 55;

        $outcome = $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertSame('deferred_low_budget', $outcome->status);
        $this->assertSame('deferred_low_budget', end($statuses));
        $this->assertCount(1 + 5, $fetcher->calls, 'office + (55 - 50) planned calls');
        $this->assertContains('OFFICE', $outcome->fetched);
        $this->assertSame(6, $outcome->calls);
    }

    public function testTrimmedLoginLeavesLastSyncedUntouchedSoTheNextLoginIsNotSkipped(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())->method('markSynced');
        [$svc, $fetcher] = $this->scripted($users);
        $fetcher->remaining = 55;
        $this->assertSame('deferred_low_budget', $svc->sync(self::USER, SyncPlanner::MODE_LOGIN)->status);

        $fetcher->remaining = 90;
        $fetcher->calls = [];
        $outcome = $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertSame('synced', $outcome->status, 'the next login really syncs and stamps last_synced_at');
        $this->assertNotSame(['office'], $fetcher->calls, 'the endpoints the trim cut are healed');
    }

    public function testManagerWithNoDriverStillSyncsAndNeverAsksForADriverProfile(): void
    {
        [$svc, $fetcher] = $this->scripted();
        $fetcher->office['driId'] = '';

        $outcome = $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertSame('synced', $outcome->status);
        $this->assertNotContains('DriProfile', $fetcher->calls);
        $this->assertContains('CAR', $outcome->fetched);
    }

    public function testTdIsDecidedByTheOfficeTdIdNotByDivision(): void
    {
        [$svc, $fetcher] = $this->scripted();
        $fetcher->office['tdId'] = '';
        $fetcher->office['group'] = 'Elite - 1';

        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertNotContains('TDProfile', $fetcher->calls);
    }

    public function testFailedDriverProfileIsNotRetriedAndDoesNotFailTheSync(): void
    {
        [$svc, $fetcher] = $this->scripted();
        $fetcher->failing = ['DriProfile'];

        $outcome = $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertSame('synced', $outcome->status);
        $this->assertNotContains('DRIVER', $outcome->fetched);
        $this->assertSame(1, count(array_keys($fetcher->calls, 'DriProfile', true)), 'a failed planned call is not retried');
    }

    public function testEndOfSeasonSkipsTheTrackProfileSilently(): void
    {
        [$svc, $fetcher] = $this->scripted();
        $fetcher->office['endOfSeason'] = true;
        $fetcher->failing = ['TrackProfile'];

        $outcome = $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertSame('synced', $outcome->status);
        $this->assertNotContains('TrackProfile', $fetcher->calls);
    }

    public function testNeedsTokenOutcomeCarriesNoCalls(): void
    {
        $users = $this->createStub(UserRepository::class);

        $svc = $this->service($users);
        $outcome = $svc->sync(['id' => 7, 'api_token' => ''], SyncPlanner::MODE_LOGIN);

        $this->assertSame('needs_token', $outcome->status);
        $this->assertSame([], $outcome->fetched);
        $this->assertSame(0, $outcome->calls);
    }

    public function testKnownLowBudgetDefersBeforeAnyCall(): void
    {
        [$svc, $fetcher] = $this->scripted();
        $this->cache->set(GproApiClient::budgetKeyForToken('tok'), 15, 3600);

        $outcome = $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertSame('deferred_low_budget', $outcome->status);
        $this->assertSame([], $fetcher->calls);
    }

    public function testAnalysisOfAnOlderRaceIsPlannedAgainAfterRelogin(): void
    {
        [$svc, $fetcher, $db, $client] = $this->scripted();
        $fetcher->analysis['selRaceNb'] = '11';
        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);
        $this->harvestAnswersTheAnalysis($client);
        $fetcher->calls = [];

        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertSame(['office'], $fetcher->calls);
        $this->assertTrue($this->harvestPlansAnalysis($client, $db, $fetcher));
    }

    public function testFirstRaceOfASeasonTreatsAnyPreviousSeasonRaceAsTheAnalysisJustRun(): void
    {
        [$svc, $fetcher, $db, $client] = $this->scripted();
        $fetcher->office['raceNb'] = 1;
        $fetcher->analysis['selSeasonNb'] = '106';
        $fetcher->analysis['selRaceNb'] = '16';
        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);
        $this->harvestAnswersTheAnalysis($client);
        $fetcher->calls = [];

        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertSame(['office'], $fetcher->calls);
        $this->assertFalse($this->harvestPlansAnalysis($client, $db, $fetcher));
    }

    private function pageOfficeRow(\PDO $db, array $office): void
    {
        $repo = new ApiArchiveRepository($db, new ArchiveSanitizer());
        $repo->put(7, GproApiClient::scopeFor('tok'), 'office', '', '', 107, 14, $office);
    }

    public function testPageLevelOfficeFetchBetweenSyncsDoesNotSwallowADoneFlagFlip(): void
    {
        [$svc, $fetcher, $db] = $this->scripted();
        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        // A page render re-fetched the office after the flip and archived it.
        $fetcher->office['doneRaceSetup'] = true;
        $this->pageOfficeRow($db, $fetcher->office);
        $fetcher->calls = [];

        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertSame(['office', 'RaceSetup'], $fetcher->calls);
    }

    public function testFirstSyncAfterDeployFallsBackToThePlainOfficeRow(): void
    {
        [$svc, $fetcher, $db] = $this->scripted();
        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);
        $db->exec("DELETE FROM user_api_archive WHERE endpoint = 'office' AND params = 'sync'");
        $fetcher->office['doneRaceSetup'] = true;
        $fetcher->calls = [];

        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertSame(['office', 'RaceSetup'], $fetcher->calls);
    }

    public function testDeferredSyncDoesNotRecordAnOfficeItNeverPlannedAgainst(): void
    {
        [$svc, $fetcher, $db] = $this->scripted();
        $fetcher->remaining = 15;

        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertSame(
            0,
            (int) $db->query("SELECT COUNT(*) FROM user_api_archive WHERE params = 'sync'")->fetchColumn(),
        );
    }

    private function atParis(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('Europe/Paris'));
    }

    private function withRaceDays(callable $test): void
    {
        $prior = $_ENV['GPRO_RACE_DAYS'] ?? null;
        $_ENV['GPRO_RACE_DAYS'] = '2,5';
        try {
            $test();
        } finally {
            $_ENV['GPRO_RACE_DAYS'] = $prior ?? '';
        }
    }

    /**
     * Logs in at 21:30 Paris on $day, lets the harvest answer the analysis
     * scripted by $configure, ages its RaceAnalysis archive row to
     * $fetchedAtUtc, logs in again and returns whether the next harvest would
     * still ask for the analysis. The second login itself spends only the office.
     *
     * @param callable(ScriptedGproFetcher): void $configure
     */
    private function analysisAskedAgain(string $day, string $fetchedAtUtc, callable $configure): bool
    {
        $asked = false;
        $this->withRaceDays(function () use ($day, $fetchedAtUtc, $configure, &$asked): void {
            [$svc, $fetcher, $db, $client] = $this->scripted(null, $this->atParis($day . ' 21:30'));
            $configure($fetcher);
            $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);
            $this->harvestAnswersTheAnalysis($client);
            $db->exec("UPDATE user_api_archive SET fetched_at = '{$fetchedAtUtc}' WHERE endpoint = 'RaceAnalysis'");
            $fetcher->calls = [];
            $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);
            $this->assertSame(['office'], $fetcher->calls);
            $asked = $this->harvestPlansAnalysis($client, $db, $fetcher);
        });

        return $asked;
    }

    /** @return array<string, array{string, string, bool}> */
    public static function postRaceInstants(): array
    {
        return [
            'CEST 20:59 Paris' => ['2026-10-23', '2026-10-23 18:59:00', false],
            'CEST 21:00 Paris' => ['2026-10-23', '2026-10-23 19:00:00', true],
            'CET 20:59 Paris' => ['2026-10-27', '2026-10-27 19:59:00', false],
            'CET 21:00 Paris' => ['2026-10-27', '2026-10-27 20:00:00', true],
        ];
    }

    #[DataProvider('postRaceInstants')]
    public function testAnalysisFetchedFromTwentyOneParisCountsAsAnswered(string $day, string $fetchedAt, bool $answered): void
    {
        $asked = $this->analysisAskedAgain($day, $fetchedAt, static function (ScriptedGproFetcher $f): void {
            $f->analysis['selRaceNb'] = '11';
        });

        $this->assertSame(!$answered, $asked);
    }

    public function testNonSupporterAnswerAfterTheRaceIsNotAskedAgain(): void
    {
        $asked = $this->analysisAskedAgain('2026-10-23', '2026-10-23 19:30:00', static function (ScriptedGproFetcher $f): void {
            $f->analysis = ['errorMsg' => 'Supporters only', 'data' => []];
        });

        $this->assertFalse($asked);
    }

    public function testNonSupporterAnswerBeforeTheRaceEndedIsAskedAgain(): void
    {
        $asked = $this->analysisAskedAgain('2026-10-23', '2026-10-23 18:30:00', static function (ScriptedGproFetcher $f): void {
            $f->analysis = ['errorMsg' => 'Supporters only', 'data' => []];
        });

        $this->assertTrue($asked);
    }

    public function testAFailureAfterThePlanFailsTheSyncAndLogsTheClassOnly(): void
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('markSynced')->willThrowException(new \RuntimeException('secret-detail'));
        [$svc] = $this->scripted($users);
        $log = sys_get_temp_dir() . '/gpro_sync_log_' . bin2hex(random_bytes(4));
        $priorLog = ini_set('error_log', $log);

        try {
            $outcome = $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);
        } finally {
            ini_set('error_log', (string) $priorLog);
        }
        $lines = (string) file_get_contents($log);
        @unlink($log);

        $this->assertSame('failed', $outcome->status);
        $this->assertStringContainsString('plan:', $lines, 'the failure came after the plan ran');
        $this->assertStringContainsString('[GproSync] user 7 sync failed: RuntimeException', $lines);
        $this->assertStringNotContainsString('secret-detail', $lines);
    }

    private function syncRowDoneRaceSetup(\PDO $db): ?bool
    {
        $stmt = $db->prepare(
            "SELECT payload_gz FROM user_api_archive WHERE endpoint = 'office' AND params = 'sync' "
            . 'ORDER BY fetched_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute();
        $blob = $stmt->fetchColumn();
        if (!is_string($blob)) {
            return null;
        }

        return (bool) (json_decode((string) gzdecode($blob), true)['doneRaceSetup'] ?? false);
    }

    public function testCompletePlanRecordsTheSyncOffice(): void
    {
        [$svc, $fetcher, $db] = $this->scripted();
        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);
        $fetcher->office['doneRaceSetup'] = true;

        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertTrue($this->syncRowDoneRaceSetup($db));
    }

    public function testFailedPlannedFetchLeavesTheSyncOfficeAndTheNextSyncPlansItAgain(): void
    {
        [$svc, $fetcher, $db] = $this->scripted();
        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);
        $fetcher->office['doneRaceSetup'] = true;
        $fetcher->failing = ['RaceSetup'];

        $failed = $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertSame('synced', $failed->status);
        $this->assertFalse($this->syncRowDoneRaceSetup($db), 'previous sync row is left alone');

        $fetcher->failing = [];
        $fetcher->calls = [];
        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertSame(['office', 'RaceSetup'], $fetcher->calls);
        $this->assertTrue($this->syncRowDoneRaceSetup($db));
    }

    public function testNewRaceWithAFailingEndpointThatHasNoRowThisRaceAdvancesTheSyncRowAndHealsOnlyThatEndpoint(): void
    {
        [$svc, $fetcher, $db] = $this->scripted();
        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);
        $fetcher->office['raceNb'] = 15;
        $fetcher->analysis['selRaceNb'] = '14';
        $fetcher->failing = ['Testing'];

        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertSame(15, $this->syncRowRace($db), 'the sync row moved to the new race');

        $fetcher->failing = [];
        $fetcher->calls = [];
        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertSame(['office', 'Testing'], $fetcher->calls, 'only the endpoint with no row this race');
    }

    public function testDoneFlagFlipWhoseFetchFailsWhileTheEndpointAlreadyHasARowThisRaceHoldsTheSyncRow(): void
    {
        [$svc, $fetcher, $db] = $this->scripted();
        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);
        $fetcher->office['doneRaceSetup'] = true;
        $fetcher->failing = ['RaceSetup'];
        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);
        $fetcher->failing = [];
        $fetcher->calls = [];

        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertSame(['office', 'RaceSetup'], $fetcher->calls);
    }

    public function testTrimmedEndpointWithNoRowThisRaceDoesNotHoldTheSyncRow(): void
    {
        [$svc, $fetcher, $db] = $this->scripted();
        $fetcher->remaining = 55;

        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertFalse($this->syncRowDoneRaceSetup($db), 'the sync row advanced; healing owns the cut endpoints');
    }

    public function testTrimmedEndpointThatAlreadyHasARowThisRaceHoldsTheSyncRow(): void
    {
        [$svc, $fetcher, $db] = $this->scripted();
        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);
        $fetcher->office['doneRaceSetup'] = true;
        $fetcher->remaining = 50;

        $outcome = $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertSame('deferred_low_budget', $outcome->status);
        $this->assertFalse($this->syncRowDoneRaceSetup($db), 'RaceSetup has a row this race, healing cannot see the flip');
        $fetcher->remaining = 90;
        $fetcher->calls = [];
        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);
        $this->assertSame(['office', 'RaceSetup'], $fetcher->calls);
    }

    public function testOfficeMidRaceUpdateDoesNotWriteTheSyncRow(): void
    {
        [$svc, $fetcher, $db] = $this->scripted();
        $fetcher->office['updateInProgress'] = true;

        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertNull($this->syncRowDoneRaceSetup($db));
        $fetcher->office['updateInProgress'] = false;
        $fetcher->calls = [];
        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);
        $this->assertNotNull($this->syncRowDoneRaceSetup($db), 'written once GPRO has finished updating');
    }

    public function testASyncFetchesThroughTheGate(): void
    {
        [$svc, $fetcher, , $client] = $this->scripted();

        $outcome = $svc->sync(self::USER, SyncPlanner::MODE_MANUAL);

        $this->assertSame('synced', $outcome->status);
        $this->assertContains('office', $fetcher->calls, 'the service opened the gate for its plan');
        $this->assertContains('UpdateCar', $fetcher->calls);
        $fetcher->calls = [];

        $this->assertSame([], $client->getStandings(), 'a miss after the sync is not a fetch');
        $this->assertSame([], $fetcher->calls, 'the gate is closed again once the sync is over');
    }

    public function testAnEmptyOfficeNeverRecordsASync(): void
    {
        $users = $this->createMock(UserRepository::class);
        $statuses = [];
        $users->method('updateSyncStatus')
            ->willReturnCallback(function (int $id, string $status) use (&$statuses): void {
                $statuses[] = $status;
            });
        $users->expects($this->never())->method('markSynced');
        $db = $this->sink();
        $empty = new class implements \App\Service\GproFetcher {
            /** @var list<string> */
            public array $calls = [];

            public function setToken(string $token): void
            {
            }

            public function fetchJson(string $endpoint): array
            {
                $this->calls[] = $endpoint;

                return [];
            }

            public function fetchMarketFile(string $market): array
            {
                return [];
            }
        };
        $client = new GproApiClient($empty, $this->cache, new ApiArchiveRepository($db, new ArchiveSanitizer()));
        $svc = $this->service($users, $client, $db);

        $outcome = $svc->sync(self::USER, SyncPlanner::MODE_MANUAL);

        $this->assertSame('failed', $outcome->status);
        $this->assertCount(1, $empty->calls, 'nothing is planned against an empty office');
        $this->assertSame(['running', 'failed'], $statuses);
        $this->assertNull($this->syncRowRace($db), 'no office is recorded for the next sync to diff against');
        $this->assertFalse($this->cache->has('sync_lock_7'));
        $this->assertFalse($client->hasAnalysisAttempt());
        $this->assertFalse($client->hasHarvestFailed(Endpoint::ANALYSIS, ''));
    }

    public function testTheSharedTrackListIsFetchedOncePerSeasonAndServedFromTheCallersArchive(): void
    {
        [$svc, $fetcher, $db, $client] = $this->scripted();
        $fetcher->responses['Tracks'] = ['tracks' => [['id' => 1, 'name' => 'Monza']]];

        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertContains('Tracks', $fetcher->calls);
        $row = $db->query("SELECT user_id, season FROM user_api_archive WHERE endpoint = 'Tracks'")
            ->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['user_id' => 7, 'season' => 107], $row, 'filed under the caller for the season');

        $fetcher->calls = [];
        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);
        $this->assertSame(['office'], $fetcher->calls, 'asked once per season, then archived');

        $this->cache->delete('all_tracks_preview');
        $this->assertSame('Monza', $client->getAllTracksPreview()['tracks'][0]['name'] ?? null, 'a rotated cache reads the archived row');
        $this->assertSame(['office'], $fetcher->calls);
    }

    public function testASyncFetchesTheProfileOfEachOngoingNegotiationNotYetStored(): void
    {
        [$svc, $fetcher, $db, $client] = $this->scripted();
        $fetcher->responses['NegOverview'] = ['ongNegs' => [['sponsorId' => 5], ['sponsorId' => 6], ['sponsorId' => 0]]];
        (new ApiArchiveRepository($db, new ArchiveSanitizer()))
            ->put(7, GproApiClient::scopeFor('tok'), 'NegotiateSponsor', 'id=6', '', 107, 14, ['name' => 'Stored']);

        $outcome = $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertSame('synced', $outcome->status);
        $this->assertSame(['NegotiateSponsor?id=5'], array_values(array_filter(
            $fetcher->requests,
            static fn (string $r): bool => str_starts_with($r, 'NegotiateSponsor'),
        )));
        $this->assertSame(count($fetcher->calls), $outcome->calls, 'the profile call is counted');
        $fetcher->calls = [];
        $fetcher->requests = [];

        $this->assertSame('NegotiateSponsor', $client->getSponsorProfile(5)['endpoint'] ?? null, 'served without a call');
        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);
        $this->assertSame(['office'], $fetcher->calls, 'stored profiles are not asked for again');
    }

    public function testThePlanLogLineCountsEveryCallTheOutcomeDoes(): void
    {
        [$svc, $fetcher] = $this->scripted();
        $fetcher->responses['NegOverview'] = ['ongNegs' => [['sponsorId' => 5]]];
        $log = sys_get_temp_dir() . '/gpro_sync_log_' . bin2hex(random_bytes(4));
        $prior = ini_set('error_log', $log);

        try {
            $outcome = $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);
        } finally {
            ini_set('error_log', (string) $prior);
        }
        $lines = (string) file_get_contents($log);
        @unlink($log);

        $this->assertContains('NegotiateSponsor', $fetcher->calls);
        $this->assertSame(count($fetcher->calls), $outcome->calls);
        $this->assertStringContainsString('mode login plan: ' . $outcome->calls . ' calls (', $lines);
    }

    public function testSponsorProfilesSpendOnlyWhatTheFloorLeaves(): void
    {
        [$svc, $fetcher] = $this->scripted();
        $fetcher->responses['NegOverview'] = ['ongNegs' => [['sponsorId' => 5], ['sponsorId' => 6]]];
        // A first login plans 13 calls after the office; the login floor is 50.
        $fetcher->remaining = 63;

        $this->assertSame('synced', $svc->sync(self::USER, SyncPlanner::MODE_LOGIN)->status);
        $this->assertNotContains('NegotiateSponsor', $fetcher->calls, '63 - 13 leaves nothing above the floor');

        $fetcher->calls = [];
        $fetcher->remaining = 21;
        $this->assertSame('deferred_low_budget', $svc->sync(self::USER, SyncPlanner::MODE_MANUAL)->status);
        $this->assertSame(['office', 'UpdateCar'], $fetcher->calls, 'the manual floor trims the plan; no profile is paid');

        $fetcher->calls = [];
        $fetcher->remaining = 23;
        $svc->sync(self::USER, SyncPlanner::MODE_MANUAL);
        $this->assertSame(['office', 'UpdateCar', 'RaceSetup', 'NegotiateSponsor'], $fetcher->calls, '23 - 2 - 20 leaves one call');
    }

    private function syncRowRace(\PDO $db): ?int
    {
        $stmt = $db->prepare(
            "SELECT payload_gz FROM user_api_archive WHERE endpoint = 'office' AND params = 'sync' "
            . 'ORDER BY fetched_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute();
        $blob = $stmt->fetchColumn();

        return is_string($blob) ? (int) (json_decode((string) gzdecode($blob), true)['raceNb'] ?? 0) : null;
    }
}
