<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Archive\ArchiveSanitizer;
use App\Cache\Adapter\FilesystemCache;
use App\Controller\ApiWarmupController;
use App\Database\DatabaseSeeder;
use App\Http\Request;
use App\Repository\ApiArchiveRepository;
use App\Repository\UserRepository;
use App\Security\ApiTokenCrypto;
use App\Security\Authorize;
use App\Service\AuthService;
use App\Service\BaselineAutoFillService;
use App\Service\GproApiClient;
use App\Service\GproSyncService;
use App\Service\HarvestService;
use App\Service\PilotCalculatorService;
use App\Service\RaceHistoryService;
use App\Service\RaceTelemetryService;
use App\Sync\Endpoint;
use App\Sync\SyncPlanner;
use App\Tests\Support\ScriptedGproFetcher;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiWarmupController::class)]
final class ApiWarmupControllerTest extends TestCase
{
    private string $cacheDir;
    private FilesystemCache $cache;
    private mixed $priorRaceDays = null;

    /** @var array<string, mixed> */
    private array $priorSession = [];

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/gpro_idle_' . bin2hex(random_bytes(6));
        $this->cache = new FilesystemCache($this->cacheDir);
        $this->priorRaceDays = $_ENV['GPRO_RACE_DAYS'] ?? null;
        $_ENV['GPRO_RACE_DAYS'] = '';
        $this->priorSession = $_SESSION ?? [];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->priorSession;
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

    private function controller(
        UserRepository $users,
        ScriptedGproFetcher $fetcher,
        bool $harvestDue = false,
    ): ApiWarmupController {
        $harvest = $this->createStub(HarvestService::class);
        $harvest->method('isDue')->willReturn($harvestDue);

        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new DatabaseSeeder(
            $db,
            ['Concentration' => 'concentration'],
            ['Rookie'],
            [],
            new ApiTokenCrypto('idle-test-secret'),
        ))->migrate();
        $archive = new ApiArchiveRepository($db, new ArchiveSanitizer());

        $client = new GproApiClient($fetcher, $this->cache, $archive);
        $sync = new GproSyncService(
            $client,
            $users,
            $this->cache,
            new RaceTelemetryService(
                new \App\Repository\RaceTelemetryRepository($db),
                new \App\Telemetry\RaceTelemetryMapper(),
            ),
            new RaceHistoryService(
                new \App\Repository\RaceHistoryRepository($db),
                new \App\Telemetry\RaceHistoryMapper(),
                new \App\Repository\TrackRepository($db),
            ),
            new BaselineAutoFillService(
                new \App\Repository\RaceTelemetryRepository($db),
                new \App\Repository\PilotRepository($db),
                new PilotCalculatorService([], []),
            ),
            new \App\Sync\SyncContextFactory($client, $archive),
            new \App\Sync\EndpointFetcher($client),
            new SyncPlanner(),
        );

        return new ApiWarmupController($sync, new Authorize($users), $harvest);
    }

    private function idle(ApiWarmupController $controller): string
    {
        ob_start();
        try {
            @$controller->idle(new Request([], [], [], []));
        } finally {
            $body = (string) ob_get_clean();
        }

        return $body;
    }

    public function testIdleSyncClearsThePendingFlagAndReportsOnlyStatusAndFetched(): void
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 7, 'api_token' => 'secret-token']);
        $fetcher = new ScriptedGproFetcher();
        $_SESSION = ['user_id' => 7, AuthService::SYNC_PENDING => true];

        $body = $this->idle($this->controller($users, $fetcher));

        $this->assertArrayNotHasKey(AuthService::SYNC_PENDING, $_SESSION);
        $json = json_decode($body, true);
        $this->assertIsArray($json);
        $this->assertSame(['status', 'fetched', 'harvest_due'], array_keys($json));
        $this->assertSame('synced', $json['status']);
        $this->assertContains(Endpoint::OFFICE, $json['fetched']);
        $this->assertStringNotContainsString('secret-token', $body);
        $this->assertNotSame([], $fetcher->calls, 'the sync really ran');
    }

    public function testIdleSyncSaysWhetherTheHarvestIsDueOnceTheSyncIsDone(): void
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 7, 'api_token' => 'tok']);
        $_SESSION = ['user_id' => 7, AuthService::SYNC_PENDING => true];

        $body = $this->idle($this->controller($users, new ScriptedGproFetcher(), true));

        $json = json_decode($body, true);
        $this->assertIsArray($json);
        $this->assertTrue($json['harvest_due']);
    }

    public function testIdleSyncWhenNothingIsOwedStillSaysWhetherTheHarvestIsDue(): void
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 7, 'api_token' => 'tok']);
        $fetcher = new ScriptedGproFetcher();
        $_SESSION = ['user_id' => 7];

        $body = $this->idle($this->controller($users, $fetcher, true));

        $this->assertSame('{"status":"idle","fetched":[],"harvest_due":true}', $body);
        $this->assertSame([], $fetcher->calls);
    }

    public function testIdleSyncWithoutATokenSpendsNothing(): void
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 7, 'api_token' => '']);
        $fetcher = new ScriptedGproFetcher();
        $_SESSION = ['user_id' => 7, AuthService::SYNC_PENDING => true];

        $body = $this->idle($this->controller($users, $fetcher));

        $this->assertSame('{"status":"needs_token","fetched":[],"harvest_due":false}', $body);
        $this->assertSame([], $fetcher->calls);
    }

    public function testIdleSyncRespectsTheRunningSyncLock(): void
    {
        $this->cache->set('sync_lock_7', time(), 60);
        $users = $this->createStub(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 7, 'api_token' => 'tok']);
        $fetcher = new ScriptedGproFetcher();
        $_SESSION = ['user_id' => 7, AuthService::SYNC_PENDING => true];

        $body = $this->idle($this->controller($users, $fetcher));

        $this->assertSame('{"status":"in_progress","fetched":[],"harvest_due":false}', $body);
        $this->assertSame([], $fetcher->calls);
    }

    public function testIdleSyncSavesTheBudgetFigureItProducedAfterReleasingTheSession(): void
    {
        $dir = $this->cacheDir . '_sessions';
        mkdir($dir, 0700, true);
        $prior = [
            ini_set('session.save_path', $dir),
            ini_set('session.use_cookies', '0'),
            ini_set('session.cache_limiter', ''),
        ];
        try {
            session_id('idlebudgettest');
            $this->assertTrue(session_start());
            $_SESSION = [
                'user_id' => 7,
                AuthService::SYNC_PENDING => true,
                AuthService::IDLE_SYNC_OFFERED => true,
                'api_limit' => 100,
                'api_limit_updated_at' => 1,
            ];
            $users = $this->createStub(UserRepository::class);
            $users->method('findById')->willReturn(['id' => 7, 'api_token' => 'tok']);
            $fetcher = new ScriptedGproFetcher();
            $fetcher->remaining = 64;

            $this->idle($this->controller($users, $fetcher));

            $this->assertSame(PHP_SESSION_NONE, session_status(), 'the session is released again');
            session_id('idlebudgettest');
            $this->assertTrue(session_start());
            $this->assertSame(64, $_SESSION['api_limit']);
            $this->assertGreaterThan(1, $_SESSION['api_limit_updated_at']);
            $this->assertArrayNotHasKey(AuthService::SYNC_PENDING, $_SESSION);
            $this->assertArrayNotHasKey(AuthService::IDLE_SYNC_OFFERED, $_SESSION);
            $this->assertSame(7, $_SESSION['user_id']);
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            ini_set('session.save_path', (string) $prior[0]);
            ini_set('session.use_cookies', (string) $prior[1]);
            ini_set('session.cache_limiter', (string) $prior[2]);
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    public function testIdleSyncWhenNothingIsOwedSpendsNothingAndAnswersIdle(): void
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 7, 'api_token' => 'tok']);
        $fetcher = new ScriptedGproFetcher();
        $_SESSION = ['user_id' => 7];

        $body = $this->idle($this->controller($users, $fetcher));

        $this->assertSame('{"status":"idle","fetched":[],"harvest_due":false}', $body);
        $this->assertSame([], $fetcher->calls);
    }

    /** @return array<string, array{string}> */
    public static function foreignSessionFiles(): array
    {
        return [
            'another user' => ['user_id|i:8;api_limit|i:100;api_limit_updated_at|i:1;'],
            'logged out' => ['api_limit|i:100;api_limit_updated_at|i:1;'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('foreignSessionFiles')]
    public function testIdleSyncWritesNoBudgetWhenTheSessionNoLongerBelongsToTheSyncingUser(string $fileNow): void
    {
        $dir = $this->cacheDir . '_sessions';
        mkdir($dir, 0700, true);
        $prior = [
            ini_set('session.save_path', $dir),
            ini_set('session.use_cookies', '0'),
            ini_set('session.cache_limiter', ''),
        ];
        try {
            session_id('idlemismatchtest');
            $this->assertTrue(session_start());
            $_SESSION = [
                'user_id' => 7,
                AuthService::SYNC_PENDING => true,
                'api_limit' => 100,
                'api_limit_updated_at' => 1,
            ];
            $users = $this->createStub(UserRepository::class);
            $users->method('findById')->willReturn(['id' => 7, 'api_token' => 'tok']);
            $users->method('updateSyncStatus')->willReturnCallback(
                static function () use ($dir, $fileNow): void {
                    file_put_contents($dir . '/sess_idlemismatchtest', $fileNow);
                },
            );
            $fetcher = new ScriptedGproFetcher();
            $fetcher->remaining = 64;

            $this->idle($this->controller($users, $fetcher));

            $this->assertSame(PHP_SESSION_NONE, session_status(), 'the reopened session is aborted, not left open');
            $this->assertSame($fileNow, file_get_contents($dir . '/sess_idlemismatchtest'), 'nothing was written');
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_abort();
            }
            ini_set('session.save_path', (string) $prior[0]);
            ini_set('session.use_cookies', (string) $prior[1]);
            ini_set('session.cache_limiter', (string) $prior[2]);
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    public function testManualResyncClearsBothSyncFlags(): void
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 7, 'api_token' => 'tok']);
        $fetcher = new ScriptedGproFetcher();
        $_SESSION = [
            'user_id' => 7,
            AuthService::SYNC_PENDING => true,
            AuthService::IDLE_SYNC_OFFERED => true,
        ];

        ob_start();
        try {
            @$this->controller($users, $fetcher)->warmup(new Request([], [], [], []));
        } finally {
            ob_end_clean();
        }

        $this->assertArrayNotHasKey(AuthService::SYNC_PENDING, $_SESSION);
        $this->assertArrayNotHasKey(AuthService::IDLE_SYNC_OFFERED, $_SESSION);
    }
}
