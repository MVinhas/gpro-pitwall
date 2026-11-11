<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Cache\Adapter\NullCache;
use App\Controller\PageController;
use App\Service\GproApiClient;
use App\Service\GproFetcher;
use App\Service\RaceEngineerService;
use App\Support\RaceSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PageController::class)]
final class PageControllerTest extends TestCase
{
    private const TRACKS = ['Buenos Aires', 'Monte Carlo', 'Monza'];

    public function testDefaultsToTheNextRaceTrackWhenKnown(): void
    {
        $this->assertSame(
            'Monte Carlo',
            PageController::resolveDefaultTrack(self::TRACKS, 'Monte Carlo'),
        );
    }

    public function testFallsBackToFirstTrackWhenNoNextRaceCached(): void
    {
        // Pre-first-sync: no Office data, so trackName is empty.
        $this->assertSame(
            'Buenos Aires',
            PageController::resolveDefaultTrack(self::TRACKS, ''),
        );
    }

    public function testFallsBackToFirstTrackWhenNextRaceIsUnknown(): void
    {
        // Defensive: a trackName that isn't in our config must not leak through.
        $this->assertSame(
            'Buenos Aires',
            PageController::resolveDefaultTrack(self::TRACKS, 'Nowhere Speedway'),
        );
    }

    public function testEmptyTrackListYieldsEmptyString(): void
    {
        $this->assertSame('', PageController::resolveDefaultTrack([], 'Monte Carlo'));
    }

    public function testResolvesNextRaceTrackIdByRaceNumber(): void
    {
        $calendar = ['events' => [
            ['eventType' => 'R', 'idx' => 4, 'trackId' => 41], // Magny Cours
            ['eventType' => 'SD', 'idx' => 5, 'trackId' => 1],
            ['eventType' => 'R', 'idx' => 5, 'trackId' => 63], // Poznan
        ]];

        $this->assertSame(63, PageController::nextRaceTrackId($calendar, 5));
    }

    public function testNextRaceTrackIdReturnsZeroWhenRaceNotInCalendar(): void
    {
        $calendar = ['events' => [
            ['eventType' => 'R', 'idx' => 4, 'trackId' => 41],
        ]];

        $this->assertSame(0, PageController::nextRaceTrackId($calendar, 9));
        $this->assertSame(0, PageController::nextRaceTrackId($calendar, 0));
        $this->assertSame(0, PageController::nextRaceTrackId([], 5));
    }

    public function testSetupIsStaleWhenRaceSetupTrackTrailsOffice(): void
    {
        // Office rolled over to race 63; RaceSetup still on the previous 41.
        $this->assertTrue(PageController::isRaceSetupStale(63, 41));
    }

    public function testSetupIsFreshWhenTrackIdsMatch(): void
    {
        // RaceSetup has rolled over: weather is for the current race.
        $this->assertFalse(PageController::isRaceSetupStale(63, 63));
    }

    public function testSetupNotFlaggedStaleWhenAnIdIsUnknown(): void
    {
        // Unknown ids (RaceSetup or Office absent) — can't tell, so don't nag.
        $this->assertFalse(PageController::isRaceSetupStale(0, 41));
        $this->assertFalse(PageController::isRaceSetupStale(63, 0));
        $this->assertFalse(PageController::isRaceSetupStale(0, 0));
    }

    /**
     * A token with nothing stored (never synced, or just replaced) used to read
     * as "no driver under contract" or "no tyre supplier": every team screen
     * shows the one way to load the data instead.
     */
    public function testTeamScreensLackDataWhileNoOfficeIsStored(): void
    {
        foreach (['Cockpit', 'Race Engineer', 'Race Strategy', 'Testing', 'Car Wear Planner'] as $section) {
            $this->assertTrue(PageController::lacksTeamData($section, []), $section);
            $this->assertFalse(PageController::lacksTeamData($section, ['driId' => '']), $section);
        }
    }

    public function testScreensThatWorkWithoutTeamDataNeverLackIt(): void
    {
        // Training takes typed-in stats, Recruitment reads the shared market.
        foreach (['Training Planner', 'Recruitment Analyzer', 'Division Baseline'] as $section) {
            $this->assertFalse(PageController::lacksTeamData($section, []), $section);
        }
    }

    public function testTrainingLapsRunAtTheTestingTrack(): void
    {
        $this->assertSame('Monza', PageController::testingTrackName(['trackName' => 'Monza']));
    }

    public function testNoTestingTrackWhileTestingIsClosed(): void
    {
        // Closed windows still carry a trackName at times; it must not be
        // used, since no training laps can be run there.
        $this->assertNull(PageController::testingTrackName(['trackName' => 'Monza', 'endOfSeason' => true]));
        $this->assertNull(PageController::testingTrackName(['trackName' => 'Monza', 'showError' => true]));
        $this->assertNull(PageController::testingTrackName(['trackName' => '']));
        $this->assertNull(PageController::testingTrackName([]));
    }

    /**
     * @param array<string, mixed> $testing
     */
    #[DataProvider('lapsLeftCases')]
    public function testTestingLapsLeft(array $testing, int $expected): void
    {
        $this->assertSame($expected, PageController::testingLapsLeft($testing));
    }

    /**
     * @return array<string, array{array<string, mixed>, int}>
     */
    public static function lapsLeftCases(): array
    {
        return [
            'partly used'     => [['lapsLeft' => 37], 37],
            'string zero'     => [['lapsLeft' => '0'], 0],
            'absent'          => [[], 100],
            'non-numeric'     => [['lapsLeft' => 'x'], 100],
            'negative'        => [['lapsLeft' => -5], 0],
            'over the ceiling' => [['lapsLeft' => 140], 100],
        ];
    }

    public function testShortTabAliasesResolveToCanonicalKeys(): void
    {
        $this->assertSame('Race Strategy', PageController::canonicalMainTab('Strategy'));
        $this->assertSame('Training Planner', PageController::canonicalMainTab('Training'));
        $this->assertSame('Recruitment Analyzer', PageController::canonicalMainTab('Recruitment'));
    }

    public function testCarWearLabelResolvesToThePlanner(): void
    {
        // The label pointed at the Cockpit while the tab was retired
        // (1.15.9–1.19.x); the planner brought it back.
        $this->assertSame('Car Wear Planner', PageController::canonicalMainTab('Car Wear'));
    }

    public function testCanonicalMainTabPassesCanonicalNamesThrough(): void
    {
        $this->assertSame('Cockpit', PageController::canonicalMainTab('Cockpit'));
        $this->assertSame('Race Strategy', PageController::canonicalMainTab('Race Strategy'));
        $this->assertSame('Division Baseline', PageController::canonicalMainTab('Division Baseline'));
    }

    public function testCanonicalMainTabPassesUnknownStringsThrough(): void
    {
        $this->assertSame('Nonsense', PageController::canonicalMainTab('Nonsense'));
        $this->assertSame('', PageController::canonicalMainTab(''));
    }

    /**
     * Regression: the Training Planner used to cache the mapped driver in
     * $_SESSION['imported_driver'] and only fetch when that key was empty, so
     * training a stat in GPRO then re-syncing still rendered the pre-training
     * value — the session copy outlived every cache flush. The driver must be
     * read from the API client on each render, never pinned in the session.
     */
    public function testTrainingPlannerDoesNotPinTheDriverInTheSession(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/Controller/PageController.php',
        );

        self::assertIsString($source);
        $sessionPin = '$_SESSION[' . chr(39) . 'imported_driver' . chr(39) . ']';
        self::assertStringNotContainsString($sessionPin, $source);
    }

    public function testEngineerReceivesTheStaleFlagItIsGiven(): void
    {
        $service = $this->createMock(RaceEngineerService::class);
        $service->expects($this->once())->method('forCockpit')->with(7, [], true)->willReturn([
            'objective' => [], 'lines' => [], 'season' => 1,
        ]);

        $this->assertNotNull(PageController::buildEngineer($service, 7, [], true));
    }

    public function testEngineerStalePrefersThePostSyncFlag(): void
    {
        $this->assertFalse(PageController::engineerStale(['sync_stale' => false], true));
        $this->assertTrue(PageController::engineerStale(['sync_stale' => true], false));
    }

    public function testEngineerStaleFallsBackToTheBootstrapFlag(): void
    {
        $this->assertTrue(PageController::engineerStale([], true));
        $this->assertFalse(PageController::engineerStale([], false));
    }

    public function testEngineerFailureYieldsNullInsteadOfBreakingTheCockpit(): void
    {
        $service = $this->createStub(RaceEngineerService::class);
        $service->method('forCockpit')->willThrowException(new \RuntimeException('boom'));
        $prior = ini_get('error_log');
        $log = sys_get_temp_dir() . '/engineer-' . bin2hex(random_bytes(4)) . '.log';
        ini_set('error_log', $log);

        try {
            $this->assertNull(PageController::buildEngineer($service, 7, []));
            $logged = (string) file_get_contents($log);
        } finally {
            ini_set('error_log', $prior === false ? '' : $prior);
            @unlink($log);
        }

        $this->assertStringContainsString('[Engineer] RuntimeException', $logged);
        $this->assertStringNotContainsString('boom', $logged);
    }

    public function testWhichScreensReadTheDriverMarket(): void
    {
        $office = ['driId' => '77'];

        $this->assertTrue(PageController::readsDriverMarket('Recruitment Analyzer', false, []));
        $this->assertTrue(PageController::readsDriverMarket('Race Engineer', true, $office));
        // No driver under contract: the hiring shortlist still needs the market.
        $this->assertTrue(PageController::readsDriverMarket('Race Engineer', true, ['driId' => '0']));
        $this->assertTrue(PageController::readsDriverMarket('Race Engineer', true, ['driId' => '']));
        // Nothing stored: the engineer builds nothing, so the market is never read.
        $this->assertFalse(PageController::readsDriverMarket('Race Engineer', true, []));
        $this->assertFalse(PageController::readsDriverMarket('Race Engineer', false, $office));
        $this->assertFalse(PageController::readsDriverMarket('Cockpit', true, $office));
    }

    public function testDriverMarketReturnsTheCachedOrFetchedMarket(): void
    {
        $api = self::marketClient(static fn (): array => [
            'Last updated' => '2026-11-10 12:00',
            'drivers' => [['ID' => 1, 'NAME' => 'Ada']],
        ]);

        $this->assertSame(
            ['updated_at' => '2026-11-10 12:00', 'rows' => [['ID' => 1, 'NAME' => 'Ada']]],
            PageController::driverMarket($api),
        );
    }

    public function testDriverMarketFailureYieldsNullAndLogsTheClassOnly(): void
    {
        $api = self::marketClient(static function (): array {
            throw new \RuntimeException('market down at secret-host');
        });
        $prior = ini_get('error_log');
        $log = sys_get_temp_dir() . '/engineer-' . bin2hex(random_bytes(4)) . '.log';
        ini_set('error_log', $log);

        try {
            $this->assertNull(PageController::driverMarket($api));
            $logged = (string) file_get_contents($log);
        } finally {
            ini_set('error_log', $prior === false ? '' : $prior);
            @unlink($log);
        }

        $this->assertStringContainsString('[Engineer] RuntimeException', $logged);
        $this->assertStringNotContainsString('secret-host', $logged);
    }

    /** @param \Closure(): array<string, mixed> $market */
    private static function marketClient(\Closure $market): GproApiClient
    {
        $fetcher = new class ($market) implements GproFetcher {
            /** @param \Closure(): array<string, mixed> $market */
            public function __construct(private readonly \Closure $market)
            {
            }

            public function setToken(string $token): void
            {
            }

            public function fetchJson(string $endpoint): array
            {
                throw new \LogicException('No GPRO call is expected');
            }

            public function fetchMarketFile(string $market): array
            {
                return ($this->market)();
            }
        };

        return new GproApiClient($fetcher, new NullCache());
    }

    public function testTestingLapsOverrideDeclined(): void
    {
        $this->assertSame(
            'Testing declined on the Engineer',
            PageController::testingLapsOverride(true, 'skip', 'Pro', false, []),
        );
    }

    public function testTestingLapsOverrideDeclinedIsAdminOnly(): void
    {
        $this->assertNull(PageController::testingLapsOverride(false, 'skip', 'Pro', false, []));
    }

    public function testTestingLapsOverrideRookieIsAdminOnly(): void
    {
        $this->assertSame('No testing in Rookie', PageController::testingLapsOverride(true, null, 'Rookie', false, []));
        $this->assertNull(PageController::testingLapsOverride(false, null, 'Rookie', false, []));
    }

    public function testTestingLapsOverrideBlockedAfterUpdateForEveryone(): void
    {
        $start = ['Chassis' => 40, 'Brakes' => 92, 'Gearbox' => 95];

        $this->assertSame(
            'Testing blocked: Brakes at 92 %',
            PageController::testingLapsOverride(false, null, 'Pro', true, $start),
        );
    }

    public function testTestingLapsOverrideBlockRoundsWearUp(): void
    {
        $this->assertSame(
            'Testing blocked: Brakes at 91 %',
            PageController::testingLapsOverride(false, null, 'Pro', true, ['Brakes' => 90.4]),
        );
        $this->assertNull(PageController::testingLapsOverride(false, null, 'Pro', true, ['Brakes' => 90]));
    }

    public function testTestingLapsOverrideNotBeforeUpdate(): void
    {
        $this->assertNull(PageController::testingLapsOverride(false, null, 'Pro', false, ['Brakes' => 92]));
    }

    public function testTestingLapsOverrideDeclinedWinsOverTheBlock(): void
    {
        $this->assertSame(
            'Testing declined on the Engineer',
            PageController::testingLapsOverride(true, 'skip', 'Pro', true, ['Brakes' => 92]),
        );
    }

    public function testDeclinedTestingZeroesLapsAndKeepsTheSessionValue(): void
    {
        $session = [];
        RaceSettings::resolve($session, RaceSettings::TRAINING_LAPS, 40, 100);

        $laps = PageController::resolveTestingLaps($session, 15, 'Testing declined on the Engineer');

        $this->assertSame(0, $laps);
        $this->assertSame(40, RaceSettings::remembered($session, RaceSettings::TRAINING_LAPS));
    }

    public function testWithoutAnOverrideTheRequestedLapsAreRememberedAsBefore(): void
    {
        $session = [];

        $this->assertSame(15, PageController::resolveTestingLaps($session, 15, null));
        $this->assertSame(15, RaceSettings::remembered($session, RaceSettings::TRAINING_LAPS));
        $this->assertSame(15, PageController::resolveTestingLaps($session, null, null));
    }

    public function testTheCockpitSmokeLapCountsTrainingWear(): void
    {
        $swap = [['part' => 'Engine', 'level' => 1, 'start' => 85, 'est' => 20.0, 'end' => 105.0]];
        $parts = ['Engine' => [
            'level' => 1, 'start' => 85, 'training' => 10.0, 'race' => 10.0, 'track_base' => 10.0,
            'est' => 20.0, 'end' => 105.0,
        ]];

        $this->assertSame(
            ['Engine' => 'Reaches 100 % on lap 30 of 60: likely to break and retire'],
            PageController::wearSmoke($swap, $parts, 60),
        );
        $this->assertSame([], PageController::wearSmoke($swap, $parts, null));
    }
}
