<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Cache\Adapter\FilesystemCache;
use App\Controller\StrategyController;
use App\Http\Request;
use App\Repository\UserRepository;
use App\Security\Authorize;
use App\Service\FuelModel;
use App\Service\GproApiClient;
use App\Service\GproDataMapper;
use App\Service\RaceWeatherService;
use App\Service\RiskAdvisorService;
use App\Service\PhaMatchService;
use App\Service\CarWearService;
use App\Service\SetupCalculatorService;
use App\Service\StrategyService;
use App\Tests\Support\CountingGproFetcher;
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
 * so getNextRaceProfile()/getOfficeData() resolve without any HTTP; a page
 * never fetches, so whatever is not seeded reads as empty.
 */
#[CoversClass(StrategyController::class)]
final class StrategyControllerTest extends TestCase
{
    private const string TOKEN = 'test-token';

    private string $cacheDir;
    private FilesystemCache $cache;
    private CountingGproFetcher $fetcher;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/gpro_ctrl_' . bin2hex(random_bytes(6));
        $this->cache = new FilesystemCache($this->cacheDir);
        $this->fetcher = new CountingGproFetcher();
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

    private function controller(?StrategyService $strategy = null): StrategyController
    {
        $api = new GproApiClient($this->fetcher, $this->cache);
        $api->setToken(self::TOKEN);

        $db = new PDO('sqlite::memory:');

        return new StrategyController(
            $strategy ?? new StrategyService($db, [], new FuelModel([])),
            $api,
            new GproDataMapper(),
            new SetupCalculatorService($db, []),
            new Authorize($this->createStub(UserRepository::class)),
            new RaceWeatherService(),
            new RiskAdvisorService(),
            new PhaMatchService(),
            new CarWearService($db, []),
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

    /**
     * Rookie and Amateur race on the one supplier they are given, so tyre fit
     * says nothing there. An unknown division (the Menu call failed) must not
     * fall through to "has a choice" either.
     */
    public function testTyreSignalsApplyOnlyFromProUpwards(): void
    {
        $this->assertFalse(StrategyController::hasTyreChoice('Rookie - 31'));
        $this->assertFalse(StrategyController::hasTyreChoice('Amateur - 5'));
        $this->assertTrue(StrategyController::hasTyreChoice('Pro - 8'));
        $this->assertTrue(StrategyController::hasTyreChoice('Master - 5'));
        $this->assertTrue(StrategyController::hasTyreChoice('Elite'));
        $this->assertFalse(StrategyController::hasTyreChoice(''));
        $this->assertFalse(StrategyController::hasTyreChoice('  '));
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
        $this->assertTrue($r['above']); // one ahead, two behind
        $this->assertFalse($r['below']);
    }

    /**
     * A handful of very weak entries drags the arithmetic mean down, which
     * made a mid-pack driver read as a push signal. Standing is judged by who
     * is ahead versus behind instead, so the exact middle is neither.
     */
    public function testGroupStandingMidPackIsNeitherAboveNorBelowEvenWhenWeakEntriesDragTheMeanDown(): void
    {
        $managers = [
            ['IDM' => 1, 'driOA' => 300],
            ['IDM' => 2, 'driOA' => 250],
            ['IDM' => 7, 'driOA' => 200],
            ['IDM' => 3, 'driOA' => 20],
            ['IDM' => 4, 'driOA' => 10],
        ];
        $r = StrategyController::groupStanding(7, $managers, 'driOA');
        $this->assertSame(3, $r['rank']);
        $this->assertFalse($r['above']);
        $this->assertFalse($r['below']);
    }

    public function testGroupStandingCountsEqualValuesAsNeitherAheadNorBehind(): void
    {
        $managers = [
            ['IDM' => 1, 'carLevel' => 9],
            ['IDM' => 7, 'carLevel' => 7],
            ['IDM' => 2, 'carLevel' => 7],
            ['IDM' => 3, 'carLevel' => 7],
            ['IDM' => 4, 'carLevel' => 5],
        ];
        $r = StrategyController::groupStanding(7, $managers, 'carLevel');
        $this->assertFalse($r['above']);
        $this->assertFalse($r['below']);
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
        $this->assertTrue($r['below']);
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
        $this->assertNull($r['below']);
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
        $this->assertFalse($r['above']); // the only other entry is ahead
        $this->assertTrue($r['below']);
    }

    public function testPickBestCompoundDryRaceExcludesRainEvenWhenCheapest(): void
    {
        // Rain is the cheapest on paper, but it's the wrong tyre type for a
        // dry race — the verdict must never cross the dry/wet boundary (B8).
        $tyres = [
            'Extra Soft' => ['total_lost' => 120.0],
            'Soft'       => ['total_lost' => 110.5],
            'Medium'     => ['total_lost' => 130.0],
            'Hard'       => ['total_lost' => 150.0],
            'Rain'       => ['total_lost' => 90.0],
        ];
        $this->assertSame('Soft', StrategyController::pickBestCompound($tyres, false));
    }

    public function testPickBestCompoundWetRaceAlwaysPicksRain(): void
    {
        // Only one wet compound exists; total_lost comparisons against dry
        // tyres are meaningless when the race is wet (B8).
        $tyres = [
            'Extra Soft' => ['total_lost' => 80.0],
            'Soft'       => ['total_lost' => 90.0],
            'Medium'     => ['total_lost' => 100.0],
            'Hard'       => ['total_lost' => 110.0],
            'Rain'       => ['total_lost' => 140.0],
        ];
        $this->assertSame('Rain', StrategyController::pickBestCompound($tyres, true));
    }

    public function testPickBestCompoundEmptyOrRainlessWetReturnsNull(): void
    {
        $this->assertNull(StrategyController::pickBestCompound([], false));
        $this->assertNull(StrategyController::pickBestCompound(['Soft' => ['total_lost' => 90.0]], true));
    }

    public function testChosenCompoundDefaultsToTheBest(): void
    {
        $tyres = ['Medium' => ['total_lost' => 100.0], 'Hard' => ['total_lost' => 110.0]];

        $this->assertSame('Medium', StrategyController::pickChosenCompound($tyres, false, null, 'Medium'));
        $this->assertSame('Medium', StrategyController::pickChosenCompound($tyres, false, '', 'Medium'));
    }

    public function testChosenCompoundHonoursADrySetInADryRace(): void
    {
        $tyres = ['Medium' => ['total_lost' => 100.0], 'Hard' => ['total_lost' => 110.0], 'Rain' => []];

        $this->assertSame('Hard', StrategyController::pickChosenCompound($tyres, false, 'Hard', 'Medium'));
    }

    public function testChosenCompoundNeverCrossesTheDryWetBoundary(): void
    {
        $tyres = ['Medium' => ['total_lost' => 100.0], 'Hard' => ['total_lost' => 110.0], 'Rain' => []];

        $this->assertSame('Medium', StrategyController::pickChosenCompound($tyres, false, 'Rain', 'Medium'));
        $this->assertSame('Rain', StrategyController::pickChosenCompound($tyres, true, 'Hard', 'Rain'));
    }

    public function testChosenCompoundIgnoresAnUnknownSet(): void
    {
        $tyres = ['Medium' => ['total_lost' => 100.0]];

        $this->assertSame('Medium', StrategyController::pickChosenCompound($tyres, false, 'Hard', 'Medium'));
        $this->assertSame('Medium', StrategyController::pickChosenCompound($tyres, false, 'medium', 'Medium'));
    }

    /** Everything the calculation reads, as a sync leaves it. */
    private function seedRaceWeekend(bool $withRaceSetup = true): void
    {
        $this->seed('office_data', [
            'endOfSeason'    => 0,
            'raceNb'         => 5,
            'tyreSupplierId' => 1,
            'driId'          => '123',
        ]);
        $this->seed('next_race_profile', [
            'trackNotFoundNote' => false,
            'trackName'         => 'Imola',
            'laps'              => 50,
        ]);
        $this->seed('driver_profile_123', ['concentration' => 100]);
        if ($withRaceSetup) {
            $this->seed('race_setup', ['weather' => ['q1Temp' => 20, 'q2Temp' => 21]]);
        }
    }

    public function testAFailedCalculationReturnsTheGenericMessageNotRawDetails(): void
    {
        // Internal detail (here a path) must never reach the page (A2).
        $strategy = $this->createStub(StrategyService::class);
        $strategy->method('calculateStrategy')
            ->willThrowException(new \RuntimeException('/srv/app/secret/path.php'));
        $this->seedRaceWeekend();
        $prior = ini_get('error_log');
        ini_set('error_log', $this->cacheDir . '.log');

        try {
            $result = $this->controller($strategy)->runCalc(new Request([], [], [], []));
        } finally {
            ini_set('error_log', $prior === false ? '' : $prior);
            @unlink($this->cacheDir . '.log');
        }

        $this->assertSame(StrategyController::GENERIC_ERROR_MESSAGE, $result['error'] ?? null);
        $this->assertSame([], $this->fetcher->calls);
    }

    /**
     * Pages never fetch, so a race setup the last sync did not store reads as
     * []. Without it there is no forecast to plan on: the screen asks for a
     * re-sync instead of computing a strategy at 0 °C (or warning on the
     * missing temperatures).
     */
    public function testAMissingRaceSetupAsksForAReSyncInsteadOfAStrategy(): void
    {
        $this->seedRaceWeekend(withRaceSetup: false);

        $result = $this->controller()->runCalc(new Request([], [], [], []));

        $this->assertSame(['error' => StrategyController::NO_DATA_MESSAGE], $result);
        $this->assertSame([], $this->fetcher->calls);
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

    public function testTdIdComesFromTheOfficeTdId(): void
    {
        $td = StrategyController::buildTd(['experience' => 55, 'pitCoord' => 120], ['tdId' => '476']);

        $this->assertSame(476, $td['id']);
        $this->assertSame(55.0, $td['experience']);
        $this->assertSame(120.0, $td['pitCoordination']);
    }

    public function testTdIdIsZeroWhenTheOfficeHasNoTd(): void
    {
        $this->assertSame(0, StrategyController::buildTd([], ['tdId' => ''])['id']);
        $this->assertSame(0, StrategyController::buildTd([], [])['id']);
    }

    public function testTdIdIgnoresAnIdOnTheTdProfile(): void
    {
        $this->assertSame(0, StrategyController::buildTd(['id' => 99], [])['id']);
    }
}
