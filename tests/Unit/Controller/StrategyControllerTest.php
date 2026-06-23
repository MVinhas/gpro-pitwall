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
use App\Service\RiskAdvisorService;
use App\Service\PhaMatchService;
use App\Service\SetupCalculatorService;
use App\Service\StrategyService;
use App\Support\RaceWindow;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Guards the early-exit boundaries in runCalc():
 *  - end of season (authoritative Office.endOfSeason flag) — issue #21
 *  - no tyre supplier selected (tyreSupplierId 0) — issue #22
 *  - the trap between them: a new season's race 1 carries
 *    trackNotFoundNote=true while a race IS scheduled and no supplier is
 *    picked yet. That must surface NO_SUPPLIER, not END_OF_SEASON.
 *
 * Each must return a clear error instead of computing a strategy on a
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

    /** Cache keys the client namespaces by race window — must match here. */
    private const array RACE_WINDOWED = ['next_race_profile', 'car_data', 'race_setup'];

    /** @param array<string, mixed> $value */
    private function seed(string $key, array $value): void
    {
        $cacheKey = 'u' . GproApiClient::scopeFor(self::TOKEN) . ':' . $key;
        if (in_array($key, self::RACE_WINDOWED, true)) {
            $window = RaceWindow::idFor(new DateTimeImmutable('now'), [2, 5], 0, 'Europe/London');
            $cacheKey .= ':w' . $window;
        }
        $this->cache->set($cacheKey, $value, 3600);
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
            new RiskAdvisorService(),
            new PhaMatchService(),
            new Environment(new ArrayLoader([])),
        );
    }

    public function testEndOfSeasonFlagReturnsFriendlyMessage(): void
    {
        // Authoritative signal: Office.endOfSeason = 1, no race scheduled.
        $this->seed('office_data', ['endOfSeason' => 1, 'raceNb' => 0]);
        $this->seed('next_race_profile', ['trackNotFoundNote' => true, 'trackName' => 'Whatever']);

        $result = $this->controller()->runCalc(new Request([], [], [], []));

        $this->assertSame(StrategyController::END_OF_SEASON_MESSAGE, $result['error'] ?? null);
    }

    public function testNoRaceAndTrackNotFoundReturnsEndOfSeason(): void
    {
        // Genuine between-seasons gap: no race scheduled (raceNb 0) and the
        // note set. Still end-of-season.
        $this->seed('office_data', ['endOfSeason' => 0, 'raceNb' => 0]);
        $this->seed('next_race_profile', ['trackNotFoundNote' => true, 'trackName' => 'Whatever']);

        $result = $this->controller()->runCalc(new Request([], [], [], []));

        $this->assertSame(StrategyController::END_OF_SEASON_MESSAGE, $result['error'] ?? null);
    }

    public function testNoSupplierSelectedReturnsChooseSupplierMessage(): void
    {
        $this->seed('next_race_profile', ['trackNotFoundNote' => false, 'trackName' => 'Imola', 'laps' => 50]);
        $this->seed('office_data', ['tyreSupplierId' => 0, 'raceNb' => 5]);

        $result = $this->controller()->runCalc(new Request([], [], [], []));

        $this->assertSame(StrategyController::NO_SUPPLIER_MESSAGE, $result['error'] ?? null);
    }

    public function testIsSupplierlessDivisionMatchesRookieAndAmateurOnly(): void
    {
        $this->assertTrue(StrategyController::isSupplierlessDivision('Rookie - 31'));
        $this->assertTrue(StrategyController::isSupplierlessDivision('amateur - 5'));
        $this->assertFalse(StrategyController::isSupplierlessDivision('Pro - 8'));
        $this->assertFalse(StrategyController::isSupplierlessDivision('Master - 5'));
        $this->assertFalse(StrategyController::isSupplierlessDivision('Elite'));
        $this->assertFalse(StrategyController::isSupplierlessDivision(''));
    }

    public function testGroupStandingRanksOwnValueAgainstGroup(): void
    {
        // Me (IDM 7) at car level 8; one manager above, two below.
        $managers = [
            ['IDM' => 1, 'carLevel' => 9],
            ['IDM' => 7, 'carLevel' => 8],
            ['IDM' => 2, 'carLevel' => 6],
            ['IDM' => 3, 'carLevel' => 5],
        ];
        $r = StrategyController::groupStanding(7, $managers, 'carLevel');
        $this->assertSame(2, $r['rank']);
        $this->assertSame(4, $r['total']);
        $this->assertTrue($r['above']); // 8 > mean 7.0
    }

    public function testGroupStandingBelowAverageAndStringValues(): void
    {
        // driOA arrives as strings from the API. Me (IDM 7) ranked low.
        $managers = [
            ['IDM' => 1, 'driOA' => '190'],
            ['IDM' => 2, 'driOA' => '180'],
            ['IDM' => 3, 'driOA' => '170'],
            ['IDM' => 7, 'driOA' => '120'],
        ];
        $r = StrategyController::groupStanding(7, $managers, 'driOA');
        $this->assertSame(4, $r['rank']);
        $this->assertSame(4, $r['total']);
        $this->assertFalse($r['above']);
    }

    public function testGroupStandingUsesCompetitionRankingForTies(): void
    {
        // Two managers tie above me; I share no rank with them.
        $managers = [
            ['IDM' => 1, 'carLevel' => 9],
            ['IDM' => 2, 'carLevel' => 9],
            ['IDM' => 7, 'carLevel' => 7],
        ];
        $r = StrategyController::groupStanding(7, $managers, 'carLevel');
        $this->assertSame(3, $r['rank']); // two strictly better → rank 3
    }

    public function testGroupStandingReturnsNullsWhenManagerMissing(): void
    {
        $managers = [
            ['IDM' => 1, 'carLevel' => 9],
            ['IDM' => 2, 'carLevel' => 6],
        ];
        $r = StrategyController::groupStanding(99, $managers, 'carLevel');
        $this->assertNull($r['rank']);
        $this->assertNull($r['total']);
        $this->assertNull($r['above']);
    }

    public function testGroupStandingIgnoresZeroOrMissingValues(): void
    {
        // Inactive managers (value 0 / absent) are excluded from the field.
        $managers = [
            ['IDM' => 1, 'carLevel' => 8],
            ['IDM' => 4, 'carLevel' => 0],
            ['IDM' => 5],
            ['IDM' => 7, 'carLevel' => 6],
        ];
        $r = StrategyController::groupStanding(7, $managers, 'carLevel');
        $this->assertSame(2, $r['rank']);
        $this->assertSame(2, $r['total']);
        $this->assertFalse($r['above']); // 6 < mean 7.0
    }

    public function testNewSeasonRace1NoSupplierShowsSupplierNotSeasonMessage(): void
    {
        // The reported bug: new season's race 1. GPRO sends
        // trackNotFoundNote=true while a race IS scheduled (raceNb=1) and the
        // supplier isn't chosen yet. Must surface NO_SUPPLIER, not END_OF_SEASON.
        $this->seed('office_data', [
            'endOfSeason'    => 0,
            'raceNb'         => 1,
            'seasonNb'       => 111,
            'tyreSupplierId' => 0,
            'trackName'      => 'Barcelona',
        ]);
        $this->seed('next_race_profile', [
            'trackNotFoundNote' => true,
            'trackName'         => 'Barcelona',
            'laps'              => 65,
        ]);

        $result = $this->controller()->runCalc(new Request([], [], [], []));

        $this->assertSame(StrategyController::NO_SUPPLIER_MESSAGE, $result['error'] ?? null);
    }
}
