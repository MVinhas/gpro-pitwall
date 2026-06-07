<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Cache\Adapter\FilesystemCache;
use App\Controller\StrategyController;
use App\Http\Request;
use App\Repository\UserRepository;
use App\Security\Authorize;
use App\Service\GproApiClient;
use App\Service\GproApiFetcher;
use App\Service\GproDataMapper;
use App\Service\RaceWeatherService;
use App\Service\SetupCalculatorService;
use App\Service\StrategyService;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Guards the two early-exit boundaries in runCalc():
 *  - end of season (GPRO sets trackNotFoundNote) — issue #21
 *  - no tyre supplier selected (tyreSupplierId 0) — issue #22
 *
 * Both must return a clear error instead of computing a strategy on a
 * phantom race / a falsely-assumed Pipirelli supplier. The cache is seeded
 * so getNextRaceProfile()/getOfficeData() resolve without any HTTP.
 */
#[CoversClass(StrategyController::class)]
final class StrategyControllerTest extends TestCase
{
    private const string TOKEN = 'test-token';

    private string $cacheDir;
    private FilesystemCache $cache;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/gpro_ctrl_' . bin2hex(random_bytes(6));
        $this->cache = new FilesystemCache($this->cacheDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->cacheDir);
    }

    /** @param array<string, mixed> $value */
    private function seed(string $key, array $value): void
    {
        $this->cache->set('u' . GproApiClient::scopeFor(self::TOKEN) . ':' . $key, $value, 3600);
    }

    private function controller(): StrategyController
    {
        $api = new GproApiClient(
            // Unreachable host: cache hits short-circuit before any fetch.
            new GproApiFetcher(['base_url' => 'http://127.0.0.1:9', 'version' => 'test']),
            $this->cache,
        );
        $api->setToken(self::TOKEN);

        $db = new PDO('sqlite::memory:');

        return new StrategyController(
            new StrategyService($db, []),
            $api,
            new GproDataMapper(),
            new SetupCalculatorService($db, []),
            new Authorize($this->createStub(UserRepository::class)),
            new RaceWeatherService(),
            new Environment(new ArrayLoader([])),
        );
    }

    public function testEndOfSeasonReturnsFriendlyMessage(): void
    {
        $this->seed('next_race_profile', ['trackNotFoundNote' => true, 'trackName' => 'Whatever']);

        $result = $this->controller()->runCalc(new Request([], [], [], []));

        $this->assertSame(StrategyController::END_OF_SEASON_MESSAGE, $result['error'] ?? null);
    }

    public function testNoSupplierSelectedReturnsChooseSupplierMessage(): void
    {
        $this->seed('next_race_profile', ['trackNotFoundNote' => false, 'trackName' => 'Imola', 'laps' => 50]);
        $this->seed('office_data', ['tyreSupplierId' => 0]);

        $result = $this->controller()->runCalc(new Request([], [], [], []));

        $this->assertSame(StrategyController::NO_SUPPLIER_MESSAGE, $result['error'] ?? null);
    }
}
