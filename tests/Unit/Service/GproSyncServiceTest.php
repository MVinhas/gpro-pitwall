<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Archive\ArchiveSanitizer;
use App\Cache\Adapter\FilesystemCache;
use App\Repository\ApiArchiveRepository;
use App\Repository\UserRepository;
use App\Service\GproApiClient;
use App\Service\GproSyncService;
use App\Service\RaceTelemetryService;
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

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/gpro_sync_' . bin2hex(random_bytes(6));
        $this->cache = new FilesystemCache($this->cacheDir);
        $this->priorRaceDays = $_ENV['GPRO_RACE_DAYS'] ?? null;
        $_ENV['GPRO_RACE_DAYS'] = '';
    }

    protected function tearDown(): void
    {
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

    private function telemetry(?\PDO $db = null): RaceTelemetryService
    {
        return new RaceTelemetryService(
            new \App\Repository\RaceTelemetryRepository($db ?? $this->sink()),
            new \App\Telemetry\RaceTelemetryMapper(),
        );
    }

    private function autofill(?\PDO $db = null): \App\Service\BaselineAutoFillService
    {
        $db ??= $this->sink();

        return new \App\Service\BaselineAutoFillService(
            new \App\Repository\RaceTelemetryRepository($db),
            new \App\Repository\PilotRepository($db),
            new \App\Service\PilotCalculatorService([], []),
        );
    }

    private function history(?\PDO $db = null): \App\Service\RaceHistoryService
    {
        $db ??= $this->sink();

        return new \App\Service\RaceHistoryService(
            new \App\Repository\RaceHistoryRepository($db),
            new \App\Telemetry\RaceHistoryMapper(),
            new \App\Repository\TrackRepository($db),
        );
    }

    private function service(UserRepository $users, ?GproApiClient $client = null, ?\PDO $db = null, int $margin = 20): GproSyncService
    {
        $db ??= $this->sink();

        return new GproSyncService(
            $client ?? $this->apiClient(),
            $users,
            $this->cache,
            $this->telemetry($db),
            $this->history($db),
            $this->autofill($db),
            new ApiArchiveRepository($db, new ArchiveSanitizer()),
            new SyncPlanner(),
            $margin,
        );
    }

    /**
     * A service wired to a scripted GPRO over a real archive, so tests can
     * count exactly which endpoints a sync spent calls on.
     *
     * @return array{GproSyncService, ScriptedGproFetcher, \PDO}
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

        return [$this->service($users ?? $this->createStub(UserRepository::class), $client, $db), $fetcher, $db];
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

    /** @return array<string, mixed> */
    private function analysisPayload(int $driverId): array
    {
        $laps = [['idx' => 0, 'pos' => 8, 'tyres' => 'Hard', 'weather' => 'Sunny', 'temp' => 44, 'hum' => 35]];
        for ($i = 1; $i <= 5; $i++) {
            $laps[] = ['idx' => $i, 'pos' => 5, 'tyres' => 'Hard', 'weather' => 'Sunny', 'temp' => 44, 'hum' => 35, 'lapTime' => '1:14.100'];
        }

        return [
            'selSeasonNb' => '107',
            'selRaceNb' => '13',
            'group' => 'Amateur - 4',
            'trackName' => 'Hockenheim (Germany)',
            'driver' => ['name' => 'Test Driver', 'id' => $driverId, 'OA' => '140'],
            'laps' => $laps,
        ];
    }

    /**
     * @param array<string, mixed> $office
     * @param array<string, mixed> $analysis
     * @param array<string, mixed> $driverProfile
     * @param array<string, mixed> $td
     * @return array{history: array<string, mixed>, telemetry: array<string, mixed>}
     */
    private function runFullSync(array $office, array $analysis, array $driverProfile, array $td): array
    {
        [$svc, $fetcher, $db] = $this->scripted();
        $fetcher->office = $office + $fetcher->office;
        $fetcher->analysis = $analysis;
        $fetcher->driverProfile = $driverProfile;
        $fetcher->td = $td;

        $this->assertSame('synced', $svc->trySyncForUser(['id' => 7, 'api_token' => 'tok'], false));

        $history = $db->query('SELECT driver_json, td_json FROM user_race_history')->fetch(\PDO::FETCH_ASSOC);
        $telemetry = $db->query('SELECT has_td, td_overall, driver_age FROM race_telemetry')->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($history);
        $this->assertIsArray($telemetry);

        return ['history' => $history, 'telemetry' => $telemetry];
    }

    /** @return array<string, array{string}> */
    public static function noTdIds(): array
    {
        return ['empty' => [''], 'zero' => ['0']];
    }

    #[DataProvider('noTdIds')]
    public function testManagerWithoutTdArchivesNoTdBlockNotThePlaceholder(string $tdId): void
    {
        $rows = $this->runFullSync(
            ['driId' => '1234', 'tdId' => $tdId],
            $this->analysisPayload(1234),
            ['age' => 27],
            ['tdName' => 'Unknown technical director', 'ownTD' => 0, 'overall' => 0],
        );

        $this->assertSame('[]', $rows['history']['td_json']);
        $this->assertSame(0, (int) $rows['telemetry']['has_td']);
        $this->assertNull($rows['telemetry']['td_overall']);
    }

    public function testManagerWithTdArchivesTheTd(): void
    {
        $rows = $this->runFullSync(
            ['driId' => '1234', 'tdId' => '55'],
            $this->analysisPayload(1234),
            ['age' => 27],
            ['tdName' => 'Ada Byron', 'overall' => 50],
        );

        $this->assertSame('Ada Byron', json_decode((string) $rows['history']['td_json'], true)['name']);
        $this->assertSame(1, (int) $rows['telemetry']['has_td']);
        $this->assertSame(50, (int) $rows['telemetry']['td_overall']);
    }

    public function testDriverAgeComesFromTheProfileWhenItIsTheRacingDriver(): void
    {
        $rows = $this->runFullSync(
            ['driId' => '1234', 'tdId' => ''],
            $this->analysisPayload(1234),
            ['age' => 27],
            [],
        );

        $this->assertSame(27, json_decode((string) $rows['history']['driver_json'], true)['age']);
        $this->assertSame(27, (int) $rows['telemetry']['driver_age']);
    }

    public function testDriverAgeIsNotTakenFromADifferentCurrentDriver(): void
    {
        $rows = $this->runFullSync(
            ['driId' => '9999', 'tdId' => ''],
            $this->analysisPayload(1234),
            ['age' => 40],
            [],
        );

        $this->assertNull(json_decode((string) $rows['history']['driver_json'], true)['age']);
        $this->assertNull($rows['telemetry']['driver_age']);
    }

    public function testNoDriverUnderContractNeverMatchesTheRacingDriver(): void
    {
        $analysis = $this->analysisPayload(1234);

        $this->assertTrue(GproSyncService::raceWasRunByCurrentDriver($analysis, ['driId' => ' 1234 ']));
        $this->assertFalse(GproSyncService::raceWasRunByCurrentDriver($analysis, ['driId' => '9999']));
        $this->assertFalse(GproSyncService::raceWasRunByCurrentDriver($analysis, ['driId' => '']));
        $this->assertFalse(GproSyncService::raceWasRunByCurrentDriver($analysis, ['driId' => '0']));
        $this->assertFalse(GproSyncService::raceWasRunByCurrentDriver($analysis, []));
        $this->assertFalse(GproSyncService::raceWasRunByCurrentDriver([], ['driId' => '']));
        $this->assertFalse(GproSyncService::raceWasRunByCurrentDriver(['driver' => ['id' => 0]], ['driId' => '0']));
    }

    private const array USER = ['id' => 7, 'api_token' => 'tok'];

    public function testFirstLoginSpendsTheFullSnapshotAndRecordsTheOutcome(): void
    {
        [$svc, $fetcher] = $this->scripted();

        $outcome = $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertInstanceOf(SyncOutcome::class, $outcome);
        $this->assertSame('synced', $outcome->status);
        $this->assertSame(
            self::sorted([
                'office', 'DriProfile', 'UpdateCar', 'TrackProfile', 'RaceSetup', 'Testing', 'StaffAndFacilities',
                'TDProfile', 'NegOverview', 'MoneyLevels', 'RaceAnalysis', 'Calendar', 'TyreSuppliers', 'Menu',
            ]),
            self::sorted($fetcher->calls),
        );
        $this->assertSame(count($fetcher->calls), $outcome->calls);
        $this->assertContains('OFFICE', $outcome->fetched);
        $this->assertContains('ANALYSIS', $outcome->fetched);
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
        $this->assertContains('ANALYSIS', $outcome->fetched);
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
        $this->assertContains('ANALYSIS', $outcome->fetched);
    }

    public function testTdIsDecidedByTheOfficeTdIdNotByDivision(): void
    {
        [$svc, $fetcher] = $this->scripted();
        $fetcher->office['tdId'] = '';
        $fetcher->office['group'] = 'Elite - 1';

        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertNotContains('TDProfile', $fetcher->calls);
    }

    public function testFailedDriverProfileFallsBackToNoAgeAndStillArchivesTheRace(): void
    {
        [$svc, $fetcher, $db] = $this->scripted();
        $fetcher->failing = ['DriProfile'];

        $outcome = $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertSame('synced', $outcome->status);
        $this->assertNotContains('DRIVER', $outcome->fetched);
        $this->assertSame(1, count(array_keys($fetcher->calls, 'DriProfile', true)), 'a failed planned call is not retried');
        $history = $db->query('SELECT driver_json FROM user_race_history')->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($history);
        $this->assertNull(json_decode((string) $history['driver_json'], true)['age']);
        $telemetry = $db->query('SELECT driver_age FROM race_telemetry')->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($telemetry);
        $this->assertNull($telemetry['driver_age']);
    }

    public function testHistoryAndTelemetryAreWrittenOnlyWhenTheAnalysisWasFetched(): void
    {
        [$svc, $fetcher, $db] = $this->scripted();
        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM user_race_history')->fetchColumn());
        $db->exec('DELETE FROM user_race_history');
        $db->exec('DELETE FROM race_telemetry');

        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM user_race_history')->fetchColumn());
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM race_telemetry')->fetchColumn());
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

        $outcome = $this->service($users)->sync(['id' => 7, 'api_token' => ''], SyncPlanner::MODE_LOGIN);

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

    public function testAnalysisOfAnOlderRaceIsFetchedAgainOnRelogin(): void
    {
        [$svc, $fetcher] = $this->scripted();
        $fetcher->analysis['selRaceNb'] = '11';
        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);
        $fetcher->calls = [];

        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertSame(['office', 'RaceAnalysis'], $fetcher->calls);
    }

    public function testFirstRaceOfASeasonTreatsAnyPreviousSeasonRaceAsTheAnalysisJustRun(): void
    {
        [$svc, $fetcher] = $this->scripted();
        $fetcher->office['raceNb'] = 1;
        $fetcher->analysis['selSeasonNb'] = '106';
        $fetcher->analysis['selRaceNb'] = '16';
        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);
        $fetcher->calls = [];

        $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

        $this->assertSame(['office'], $fetcher->calls);
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
     * Logs in at 21:30 Paris on $day with the analysis scripted by $configure,
     * ages its RaceAnalysis archive row to $fetchedAtUtc, logs in again and
     * returns what the second login spent.
     *
     * @param callable(ScriptedGproFetcher): void $configure
     *
     * @return list<string>
     */
    private function secondLoginSpend(string $day, string $fetchedAtUtc, callable $configure): array
    {
        $calls = [];
        $this->withRaceDays(function () use ($day, $fetchedAtUtc, $configure, &$calls): void {
            [$svc, $fetcher, $db] = $this->scripted(null, $this->atParis($day . ' 21:30'));
            $configure($fetcher);
            $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);
            $db->exec("UPDATE user_api_archive SET fetched_at = '{$fetchedAtUtc}' WHERE endpoint = 'RaceAnalysis'");
            $fetcher->calls = [];
            $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);
            $calls = $fetcher->calls;
        });

        return $calls;
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
        $calls = $this->secondLoginSpend($day, $fetchedAt, static function (ScriptedGproFetcher $f): void {
            $f->analysis['selRaceNb'] = '11';
        });

        $this->assertSame($answered ? ['office'] : ['office', 'RaceAnalysis'], $calls);
    }

    public function testNonSupporterAnswerAfterTheRaceIsNotAskedAgain(): void
    {
        $calls = $this->secondLoginSpend('2026-10-23', '2026-10-23 19:30:00', static function (ScriptedGproFetcher $f): void {
            $f->analysis = ['errorMsg' => 'Supporters only', 'data' => []];
        });

        $this->assertSame(['office'], $calls);
    }

    public function testNonSupporterAnswerBeforeTheRaceEndedIsAskedAgain(): void
    {
        $calls = $this->secondLoginSpend('2026-10-23', '2026-10-23 18:30:00', static function (ScriptedGproFetcher $f): void {
            $f->analysis = ['errorMsg' => 'Supporters only', 'data' => []];
        });

        $this->assertSame(['office', 'RaceAnalysis'], $calls);
    }

    public function testNonSupporterAnalysisIsNeitherAFailureNorRecorded(): void
    {
        $this->withRaceDays(function (): void {
            [$svc, $fetcher, $db] = $this->scripted(null, $this->atParis('2026-10-23 21:30'));
            $fetcher->analysis = ['errorMsg' => 'Supporters only', 'data' => []];

            $outcome = $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

            $this->assertSame('synced', $outcome->status);
            $this->assertNotNull($this->syncRowDoneRaceSetup($db), 'a NonSup answer does not block the sync row');
            $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM user_race_history')->fetchColumn());
            $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM race_telemetry')->fetchColumn());
        });
    }

    public function testThrownAnalysisAfterTheRaceLeavesAMarkerAndStillWritesTheSyncRow(): void
    {
        $this->withRaceDays(function (): void {
            [$svc, $fetcher, $db] = $this->scripted(null, $this->atParis('2026-10-23 21:30'));
            $fetcher->failing = ['RaceAnalysis'];

            $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

            $marker = 'u' . GproApiClient::scopeFor('tok') . ':analysis_attempt:w2026-10-23';
            $this->assertTrue($this->cache->has($marker));
            $this->assertNotNull($this->syncRowDoneRaceSetup($db));

            $fetcher->calls = [];
            $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);
            $this->assertSame(['office'], $fetcher->calls);
        });
    }

    public function testThrownAnalysisBeforeTheRaceEndedLeavesNoMarker(): void
    {
        $this->withRaceDays(function (): void {
            [$svc, $fetcher] = $this->scripted(null, $this->atParis('2026-10-23 20:30'));
            $fetcher->failing = ['RaceAnalysis'];

            $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);

            $marker = 'u' . GproApiClient::scopeFor('tok') . ':analysis_attempt:w2026-10-23';
            $this->assertFalse($this->cache->has($marker));

            $fetcher->calls = [];
            $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);
            $this->assertSame(['office', 'RaceAnalysis'], $fetcher->calls);
        });
    }

    public function testRecordRaceFailureFailsTheSyncLeavesNoSyncRowAndLogsTheClassOnly(): void
    {
        [$svc, $fetcher, $db] = $this->scripted();
        // A non-scalar driId makes the post-analysis step trip a PHP warning.
        $fetcher->office['driId'] = ['not', 'scalar'];
        $log = sys_get_temp_dir() . '/gpro_sync_log_' . bin2hex(random_bytes(4));
        $priorLog = ini_set('error_log', $log);
        set_error_handler(static function (int $no, string $msg): bool {
            if (!str_contains($msg, 'Array to string')) {
                return false;
            }
            throw new \ErrorException($msg, 0, $no);
        });

        try {
            $outcome = $svc->sync(self::USER, SyncPlanner::MODE_LOGIN);
        } finally {
            restore_error_handler();
            ini_set('error_log', (string) $priorLog);
        }
        $lines = (string) file_get_contents($log);
        @unlink($log);

        $this->assertSame('failed', $outcome->status);
        $this->assertStringContainsString('plan:', $lines, 'the failure came after the plan ran');
        $this->assertStringContainsString('[GproSync] user 7 sync failed: ErrorException', $lines);
        $this->assertStringNotContainsString('Array to string', $lines);
        $this->assertNull($this->syncRowDoneRaceSetup($db), 'no sync row when recording the race threw');
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
