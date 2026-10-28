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
use App\Service\GproApiClient;
use App\Service\GproSyncService;
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
        @unlink($this->cacheDir . '.log');
    }

    private function controller(
        UserRepository $users,
        ScriptedGproFetcher $fetcher,
    ): ApiWarmupController {
        // The sync logs through error_log(); keep the run quiet (PHPUnit swaps the setting per test).
        ini_set('error_log', $this->cacheDir . '.log');

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
            new \App\Sync\SyncContextFactory($client, $archive),
            new \App\Sync\EndpointFetcher($client),
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

    public function testTheOldIdleRouteDoesNoWorkEvenWhenASyncIsOwed(): void
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 7, 'api_token' => 'secret-token']);
        $fetcher = new ScriptedGproFetcher();
        $_SESSION = ['user_id' => 7, AuthService::SYNC_PENDING => true, AuthService::HARVEST_OWED => true];

        $body = $this->idle($this->controller($users, $fetcher));

        $this->assertSame('{"status":"idle","fetched":[],"harvest_due":false}', $body);
        $this->assertSame([], $fetcher->calls, 'an old open tab cannot spend GPRO calls');
        $this->assertTrue($_SESSION[AuthService::SYNC_PENDING], 'the answer settles nothing');
        $this->assertTrue($_SESSION[AuthService::HARVEST_OWED], 'the answer settles nothing');
    }

    public function testTheOldIdleRouteAnswersTheSameWithoutAToken(): void
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 7, 'api_token' => '']);
        $fetcher = new ScriptedGproFetcher();
        $_SESSION = ['user_id' => 7];

        $body = $this->idle($this->controller($users, $fetcher));

        $this->assertSame('{"status":"idle","fetched":[],"harvest_due":false}', $body);
        $this->assertSame([], $fetcher->calls);
    }

    private function warmup(ApiWarmupController $controller): string
    {
        ob_start();
        try {
            @$controller->warmup(new Request([], [], [], []));
        } finally {
            $body = (string) ob_get_clean();
        }

        return $body;
    }

    public function testASuccessfulResyncOwesTheHarvest(): void
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 7, 'api_token' => 'tok']);
        $fetcher = new ScriptedGproFetcher();
        $_SESSION = ['user_id' => 7];

        $body = $this->warmup($this->controller($users, $fetcher));

        $this->assertStringContainsString('"status":"synced"', $body);
        $this->assertNotSame([], $fetcher->calls, 'the Re-sync fetched through the service\'s gate');
        $this->assertTrue($_SESSION[AuthService::HARVEST_OWED] ?? null);
    }

    public function testAFailedResyncOwesNoHarvest(): void
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 7, 'api_token' => 'tok']);
        $fetcher = new ScriptedGproFetcher();
        $fetcher->failing = ['office'];
        $_SESSION = ['user_id' => 7];

        $body = $this->warmup($this->controller($users, $fetcher));

        $this->assertStringContainsString('"status":"failed"', $body);
        $this->assertArrayNotHasKey(AuthService::HARVEST_OWED, $_SESSION);
    }

    public function testManualResyncSettlesTheOwedLoginSync(): void
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 7, 'api_token' => 'tok']);
        $fetcher = new ScriptedGproFetcher();
        $_SESSION = ['user_id' => 7, AuthService::SYNC_PENDING => true];

        ob_start();
        try {
            @$this->controller($users, $fetcher)->warmup(new Request([], [], [], []));
        } finally {
            ob_end_clean();
        }

        $this->assertArrayNotHasKey(AuthService::SYNC_PENDING, $_SESSION);
    }
}
