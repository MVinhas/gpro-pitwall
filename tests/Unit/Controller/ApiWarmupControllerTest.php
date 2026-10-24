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

    private function controller(UserRepository $users, ScriptedGproFetcher $fetcher): ApiWarmupController
    {
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

        $sync = new GproSyncService(
            new GproApiClient($fetcher, $this->cache, $archive),
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
            $archive,
            new SyncPlanner(),
        );

        return new ApiWarmupController($sync, new Authorize($users));
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
        $this->assertSame(['status', 'fetched'], array_keys($json));
        $this->assertSame('synced', $json['status']);
        $this->assertContains(Endpoint::OFFICE, $json['fetched']);
        $this->assertStringNotContainsString('secret-token', $body);
        $this->assertNotSame([], $fetcher->calls, 'the sync really ran');
    }

    public function testIdleSyncWithoutATokenSpendsNothing(): void
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 7, 'api_token' => '']);
        $fetcher = new ScriptedGproFetcher();
        $_SESSION = ['user_id' => 7, AuthService::SYNC_PENDING => true];

        $body = $this->idle($this->controller($users, $fetcher));

        $this->assertSame('{"status":"needs_token","fetched":[]}', $body);
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

        $this->assertSame('{"status":"in_progress","fetched":[]}', $body);
        $this->assertSame([], $fetcher->calls);
    }
}
