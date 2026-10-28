<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Archive\ArchiveSanitizer;
use App\Cache\Adapter\FilesystemCache;
use App\Database\DatabaseSeeder;
use App\Repository\ApiArchiveRepository;
use App\Security\ApiTokenCrypto;
use App\Tests\Support\CountingGproFetcher;
use App\Tests\Support\TtlRecordingCache;
use App\Service\GproApiClient;
use App\Support\DeferredRender;
use App\Support\RaceWindow;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(GproApiClient::class)]
final class GproApiClientArchiveTest extends TestCase
{
    private const string TOKEN = 'token-a';

    private string $cacheDir;
    private FilesystemCache $cache;
    private PDO $db;
    private ApiArchiveRepository $archive;
    private CountingGproFetcher $fetcher;
    private string $logFile = '';
    private ?string $priorErrorLog = null;
    private mixed $priorRaceDays = null;
    private ?DateTimeImmutable $now = null;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/gpro_archive_' . bin2hex(random_bytes(6));
        $this->cache = new FilesystemCache($this->cacheDir);
        $this->priorRaceDays = $_ENV['GPRO_RACE_DAYS'] ?? null;

        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('PRAGMA foreign_keys = ON');
        (new DatabaseSeeder(
            $this->db,
            ['Concentration' => 'concentration'],
            ['Rookie', 'Amateur', 'Pro', 'Master', 'Elite'],
            [],
            new ApiTokenCrypto('archive-client-test-secret'),
        ))->migrate();
        foreach ([1, 2] as $id) {
            $this->db->exec(
                "INSERT INTO users (id, username, email_encrypted, email_hash) "
                . "VALUES ({$id}, 'user{$id}', 'enc{$id}', 'hash{$id}')"
            );
        }

        $this->archive = new ApiArchiveRepository($this->db, new ArchiveSanitizer());
        $this->fetcher = new CountingGproFetcher();
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
        if ($this->priorErrorLog !== null) {
            ini_set('error_log', $this->priorErrorLog);
            @unlink($this->logFile);
        }
    }

    private function client(?int $userId = 1, ?ApiArchiveRepository $archive = null): GproApiClient
    {
        $client = new GproApiClient(
            $this->fetcher,
            $this->cache,
            $archive ?? $this->archive,
            fn (): DateTimeImmutable => $this->now ?? new DateTimeImmutable('now'),
        );
        $client->setToken(self::TOKEN, $userId);

        return $client;
    }

    /** Runs the named reads inside a sync run, the only place the client may call GPRO. */
    private function sync(GproApiClient $client, string ...$reads): void
    {
        $client->withFetching(static function () use ($client, $reads): void {
            foreach ($reads as $read) {
                $client->{$read}();
            }
        });
    }

    private static function scope(): string
    {
        return GproApiClient::scopeFor(self::TOKEN);
    }

    private function flushCache(): void
    {
        $this->cache->clear();
    }

    public function testAClosedGateNeverFetches(): void
    {
        $client = $this->client();

        $this->assertSame([], $client->getCarData());
        $this->assertSame([], $client->getSfHistory(106));

        $this->assertSame([], $this->fetcher->calls);
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM user_api_archive')->fetchColumn());
        $this->assertNull($this->cache->get('u' . self::scope() . ':car_data'), 'a refused fetch caches nothing');
    }

    public function testAnOldArchiveRowIsServedWithoutFetching(): void
    {
        $_ENV['GPRO_RACE_DAYS'] = '2,5';
        $client = $this->client();
        $oldWindow = RaceWindow::raceStartIdFor(new DateTimeImmutable('-30 days'), [2, 5]);
        $this->assertNotSame($client->archiveWindow(), $oldWindow);
        $this->archive->put(1, self::scope(), 'UpdateCar', '', $oldWindow, 99, 1, ['kind' => 'old car']);
        $this->ageArchive(30 * 86400);

        $this->assertSame('old car', $client->getCarData()['kind'] ?? null);
        $this->assertSame([], $this->fetcher->calls);
    }

    public function testCachedPayloadsNeverExpire(): void
    {
        $_ENV['GPRO_RACE_DAYS'] = '2,5';
        $cache = new TtlRecordingCache($this->cache);
        $client = new GproApiClient(
            $this->fetcher,
            $cache,
            $this->archive,
            fn (): DateTimeImmutable => $this->now ?? new DateTimeImmutable('now'),
        );
        $client->setToken(self::TOKEN, 1);
        $client->withFetching(static fn (): array => $client->getCarData(true));
        $window = $client->archiveWindow();
        $key = 'u' . self::scope() . ':car_data';
        $this->assertArrayHasKey($key, $cache->ttls);
        $this->assertNull($cache->ttls[$key], 'written without expiry');
        $this->db->exec('DELETE FROM user_api_archive');

        $this->now = new DateTimeImmutable('+30 days');
        $this->assertNotSame($window, $client->archiveWindow(), 'the race windows rolled on');

        $this->assertSame('car', $client->getCarData()['kind'] ?? null, 'served from the cache alone');
        $this->assertCount(1, $this->fetcher->calls);
    }

    public function testWithFetchingOpensOnlyForTheWorkAndClosesAfterAThrow(): void
    {
        $client = $this->client();

        $car = $client->withFetching(static fn (): array => $client->getCarData());
        $this->assertSame('car', $car['kind'] ?? null, 'the work\'s result is handed back');
        $this->assertCount(1, $this->fetcher->calls);

        $client->withFetching(static function () use ($client): void {
            $client->withFetching(static fn (): array => $client->getOfficeData());
            $client->getRaceSetup();
        });
        $this->assertCount(3, $this->fetcher->calls, 'a nested run leaves the outer one open');

        try {
            $client->withFetching(static function (): never {
                throw new \RuntimeException('boom');
            });
            $this->fail('the throw must propagate');
        } catch (\RuntimeException) {
        }

        $this->assertSame([], $client->getTesting());
        $this->assertCount(3, $this->fetcher->calls, 'closed again after the throw');
    }

    public function testAnotherTokensArchiveIsNotServed(): void
    {
        $this->archive->put(1, self::scope(), 'UpdateCar', '', 'any', 99, 4, ['kind' => 'token a car']);
        $client = $this->client();
        $client->setToken('token-b', 1);

        $this->assertSame([], $client->getCarData());
        $this->assertSame([], $this->fetcher->calls);
    }

    public function testAnArchivedTyreFeedIsServedToItsOwnerButNeverSharedWithOthers(): void
    {
        $this->archive->put(1, self::scope(), 'TyreSuppliers', '', 'an-old-window', 98, 17, ['suppliers' => ['old']]);

        $this->assertSame(['suppliers' => ['old']], $this->client(1)->getTyreSuppliers());
        $this->assertNull($this->cache->get('tyre_suppliers'), 'only a real fetch writes the shared feed');

        $this->assertSame([], $this->client(2)->getTyreSuppliers(), 'user 2 never reads user 1\'s row');
        $this->archive->put(2, self::scope(), 'TyreSuppliers', '', 'an-old-window', 98, 17, ['suppliers' => ['own']]);
        $this->assertSame(['suppliers' => ['own']], $this->client(2)->getTyreSuppliers());
        $this->assertSame([], $this->fetcher->calls);
    }

    public function testAScopedCloneTakenInsideTheGateStaysClosed(): void
    {
        $client = $this->client();

        $office = $client->withFetching(static function () use ($client): array {
            return $client->withScopeFor('token-b')->getOfficeData();
        });

        $this->assertSame([], $office);
        $this->assertSame([], $this->fetcher->calls, 'a layout-level clone never fetches');
    }

    public function testAForcedReadWithTheGateClosedWritesNothing(): void
    {
        $client = $this->client();
        $this->sync($client, 'getCarData');
        $key = 'u' . self::scope() . ':car_data';
        $rows = $this->db->query('SELECT fetched_at, payload_gz FROM user_api_archive')->fetchAll(PDO::FETCH_ASSOC);

        $this->assertSame([], $client->getCarData(true));

        $this->assertCount(1, $this->fetcher->calls);
        $this->assertSame('car', $this->cache->get($key)['kind'] ?? null, 'the cached payload is left as it was');
        $this->assertSame(
            $rows,
            $this->db->query('SELECT fetched_at, payload_gz FROM user_api_archive')->fetchAll(PDO::FETCH_ASSOC),
            'and the archive is untouched',
        );
    }

    public function testFetchWritesArchive(): void
    {
        $client = $this->client();
        $window = $client->archiveWindow();

        $this->sync($client, 'getOfficeData', 'getMyPilotDetails');

        $office = $this->archive->get(1, self::scope(), 'office', '', $window);
        $this->assertNotNull($office);
        $this->assertSame('Alice', $office['name']);
        $this->assertArrayNotHasKey('email', $office);
        $this->assertArrayNotHasKey('apiRequestsRemaining', $office);
        $this->assertNotNull($this->archive->get(1, self::scope(), 'DriProfile', 'id=77', $window));
        $this->assertNull($this->archive->get(2, self::scope(), 'office', '', $window), 'archive is per user');
    }

    public function testSeasonAndRaceComeFromOfficeThenFromCachedOffice(): void
    {
        $client = $this->client();
        $this->sync($client, 'getOfficeData', 'getCarData');

        $rows = $this->db->query('SELECT endpoint, season, race FROM user_api_archive ORDER BY endpoint')
            ->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame(
            [
                ['endpoint' => 'UpdateCar', 'season' => 99, 'race' => 4],
                ['endpoint' => 'office', 'season' => 99, 'race' => 4],
            ],
            $rows,
        );
    }

    public function testCacheKeepsApiBudgetOfFreshFetch(): void
    {
        $client = $this->client();
        $this->sync($client, 'getOfficeData');

        $this->assertSame(88, $client->lastKnownRemaining());
        $cached = $this->cache->get('u' . GproApiClient::scopeFor(self::TOKEN) . ':office_data');
        $this->assertIsArray($cached);
        $this->assertSame(88, $cached['apiRequestsRemaining']);
    }

    public function testCacheMissServedFromArchiveWithoutFetch(): void
    {
        $client = $this->client();
        $this->sync($client, 'getOfficeData', 'getCarData');
        $this->assertCount(2, $this->fetcher->calls);

        $this->flushCache();
        $before = $client->lastKnownRemaining();

        $office = $client->getOfficeData();
        $car = $client->getCarData();

        $this->assertCount(2, $this->fetcher->calls, 'no extra fetch after the cache was wiped');
        $this->assertSame('Alice', $office['name']);
        $this->assertSame('car', $car['kind']);
        $this->assertNull($before);
        $this->assertNull($client->lastKnownRemaining(), 'archive hit must not touch the budget counter');
        $this->assertNotNull(
            $this->cache->get('u' . GproApiClient::scopeFor(self::TOKEN) . ':office_data'),
            'archive hit repopulates the cache',
        );
    }

    public function testCachedGroupStaffNeverFetches(): void
    {
        $client = $this->client();

        $this->assertSame([], $client->getCachedGroupStaff());
        $this->assertSame([], $this->fetcher->calls, 'a miss must not spend a call');

        $this->sync($client, 'getGroupStaff');
        $this->assertCount(1, $this->fetcher->calls);

        $this->flushCache();
        $this->ageArchive(7 * 86400);
        $archived = $client->getCachedGroupStaff();

        $this->assertCount(1, $this->fetcher->calls, 'served from the archive, whatever its age');
        $this->assertSame('/gb/backend/api/v2/ViewStaff', $archived['endpoint']);
    }

    public function testForceBypassesArchiveAndRefreshesIt(): void
    {
        $client = $this->client();
        $this->sync($client, 'getOfficeData');
        $this->fetcher->name = 'Bob';

        $client->withFetching(static fn (): array => $client->getOfficeData(true));

        $this->assertCount(2, $this->fetcher->calls);
        $row = $this->archive->get(1, self::scope(), 'office', '', $client->archiveWindow());
        $this->assertSame('Bob', $row['name'] ?? null);
    }

    public function testNoArchiveWithoutUserId(): void
    {
        $client = $this->client(null);
        $this->sync($client, 'getOfficeData');
        $this->flushCache();
        $this->sync($client, 'getOfficeData');

        $this->assertCount(2, $this->fetcher->calls);
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM user_api_archive')->fetchColumn());
    }

    public function testTheSharedTyreFeedAndTrackListAreFiledUnderTheCaller(): void
    {
        $client = $this->client();
        $this->sync($client, 'getTyreSuppliers', 'getAllTracksPreview');
        $this->flushCache();
        $this->sync($client, 'getTyreSuppliers', 'getAllTracksPreview');

        $this->assertCount(2, $this->fetcher->calls, 'after a flush both read the caller\'s archived row');
        $this->assertSame(
            [['endpoint' => 'TyreSuppliers', 'user_id' => 1], ['endpoint' => 'Tracks', 'user_id' => 1]],
            $this->db->query('SELECT endpoint, user_id FROM user_api_archive ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        );
        $this->assertNull($this->cache->get('all_tracks_preview'), 'an archived row is never written to the shared key');
    }

    public function testTyreSuppliersAreArchivedForEachUserWhoFetchesThemEvenWhenTheSharedCacheIsWarm(): void
    {
        $this->sync($this->client(1), 'getOfficeData', 'getTyreSuppliers');
        $second = $this->client(2);
        $this->sync($second, 'getTyreSuppliers');
        $this->assertCount(2, $this->fetcher->calls, 'user 2 read the shared cache');
        $this->assertNull($this->archive->latest(2, 'TyreSuppliers'), 'a cache read archives nothing');

        $second->withFetching(static fn (): array => $second->getTyreSuppliers(true));

        $this->assertNotNull($this->archive->get(2, self::scope(), 'TyreSuppliers', '', $second->archiveWindow()));
        $this->assertSame(
            ['season' => 99, 'race' => 4],
            $this->db->query("SELECT season, race FROM user_api_archive WHERE endpoint = 'TyreSuppliers' AND user_id = 1")
                ->fetch(PDO::FETCH_ASSOC),
            'stamped with the office\'s season',
        );
    }

    public function testStandingsUseTheGrouplessPathAndAreServedFromTheArchive(): void
    {
        $client = $this->client();
        $this->sync($client, 'getOfficeData', 'getStandings', 'getStandings');

        $this->assertSame('/gb/backend/api/v2/Standings', $this->fetcher->calls[1]);
        $this->assertCount(2, $this->fetcher->calls, 'the second read is a cache hit');
        $this->assertNotNull($this->archive->get(1, self::scope(), 'Standings', '', $client->archiveWindow()));
        $this->flushCache();
        $client->getStandings();
        $this->assertCount(2, $this->fetcher->calls, 'a cold cache is served from the archived row');
        $client->withFetching(static fn (): array => $client->getStandings(true));
        $this->assertCount(3, $this->fetcher->calls);
    }

    public function testEconomyHistoryAsksForTheTablePageAndArchivesPerPage(): void
    {
        $client = $this->client();
        $client->withFetching(static function () use ($client): void {
            $client->getOfficeData();
            $client->getEconomyHistory();
            $client->getEconomyHistory(2);
        });

        $this->assertSame('/gb/backend/api/v2/EconomyHistory?Page=1&show=table', $this->fetcher->calls[1]);
        $this->assertSame('/gb/backend/api/v2/EconomyHistory?Page=2&show=table', $this->fetcher->calls[2]);
        $window = $client->archiveWindow();
        $this->assertNotNull($this->archive->get(1, self::scope(), 'EconomyHistory', 'Page=1&show=table', $window));
        $this->assertNotNull($this->archive->get(1, self::scope(), 'EconomyHistory', 'Page=2&show=table', $window));
        $client->withFetching(static fn (): array => $client->getEconomyHistory(2));
        $this->assertCount(3, $this->fetcher->calls, 'each page has its own cache entry');
    }

    /**
     * @return array<string, array{string, string, \Closure(GproApiClient): array<string, mixed>}>
     */
    public static function immutableHistory(): array
    {
        return [
            'SFHistory' => [
                'SFHistory', 'season=106',
                static fn (GproApiClient $c): array => $c->getSfHistory(106),
            ],
            'CCPHistory' => [
                'CCPHistory', 'season=106',
                static fn (GproApiClient $c): array => $c->getCcpHistory(106),
            ],
            'RaceAnalysis' => [
                'RaceAnalysis', 'SR=106,12',
                static fn (GproApiClient $c): array => $c->getRaceAnalysisFor(106, 12),
            ],
        ];
    }

    /** @param \Closure(GproApiClient): array<string, mixed> $call */
    #[DataProvider('immutableHistory')]
    public function testImmutableHistoryHitsItsPathAndIsFiledUnderTheEmptyWindow(
        string $endpoint,
        string $params,
        \Closure $call,
    ): void {
        $_ENV['GPRO_RACE_DAYS'] = '2,5';
        $client = $this->client();

        $client->withFetching(static fn (): array => $call($client));

        $this->assertSame('/gb/backend/api/v2/' . $endpoint . '?' . $params, $this->fetcher->calls[0]);
        $this->assertNotSame('', $client->archiveWindow());
        $this->assertNotNull($this->archive->get(1, self::scope(), $endpoint, $params, ''));
        $this->assertSame(
            1,
            (int) $this->db->query('SELECT COUNT(*) FROM user_api_archive')->fetchColumn(),
            'filed under the empty window only',
        );
    }

    /** @param \Closure(GproApiClient): array<string, mixed> $call */
    #[DataProvider('immutableHistory')]
    public function testImmutableHistoryIsServedFromTheArchiveOfAnyAgeAndAnyWindow(
        string $endpoint,
        string $params,
        \Closure $call,
    ): void {
        $_ENV['GPRO_RACE_DAYS'] = '2,5';
        $client = $this->client();
        $client->withFetching(static fn (): array => $call($client));
        $this->flushCache();
        $this->ageArchive(400 * 86400);
        $_ENV['GPRO_RACE_DAYS'] = '1,3';

        $client->withFetching(static fn (): array => $call($client));
        $call($client);

        $this->assertCount(1, $this->fetcher->calls, 'never refetched once archived');
    }

    /** @param \Closure(GproApiClient): array<string, mixed> $call */
    #[DataProvider('immutableHistory')]
    public function testImmutableHistoryReadsTheArchiveEvenWithRaceWindowingDisabled(
        string $endpoint,
        string $params,
        \Closure $call,
    ): void {
        $_ENV['GPRO_RACE_DAYS'] = '';
        $client = $this->client();
        $client->withFetching(static fn (): array => $call($client));
        $this->flushCache();

        $client->withFetching(static fn (): array => $call($client));

        $this->assertCount(1, $this->fetcher->calls);
    }

    /** @param \Closure(GproApiClient): array<string, mixed> $call */
    #[DataProvider('immutableHistory')]
    public function testImmutableHistoryIsPerUserAndPerTokenScopeAndForceRefetches(
        string $endpoint,
        string $params,
        \Closure $call,
    ): void {
        $client = $this->client();
        $second = $this->client(2);
        $fetching = static fn (GproApiClient $c): array => $c->withFetching(static fn (): array => $call($c));
        $fetching($client);
        $this->flushCache();

        $fetching($second);
        $this->assertCount(2, $this->fetcher->calls, 'user 2 cannot read user 1\'s row');

        $this->flushCache();
        $client->setToken('token-b', 1);
        $fetching($client);
        $this->assertCount(3, $this->fetcher->calls, 'another token scope misses');

        $fetching($client);
        $this->assertCount(3, $this->fetcher->calls);
        $this->flushCache();
        $client->setToken(self::TOKEN, 1);
        $fetching($client);
        $this->assertCount(3, $this->fetcher->calls, 'the original scope\'s row is still there');
    }

    public function testForcedImmutableCallsGoToTheNetworkAndRefreshTheRow(): void
    {
        $client = $this->client();
        $client->withFetching(static function () use ($client): void {
            $client->getSfHistory(106);
            $client->getSfHistory(106, true);
        });

        $this->assertCount(2, $this->fetcher->calls);
    }

    public function testImmutableHistoryWithoutAnArchiveOwnerStillCachesButNeverArchives(): void
    {
        $client = $this->client(null);
        $client->withFetching(static function () use ($client): void {
            $client->getCcpHistory(106);
            $client->getCcpHistory(106);
        });

        $this->assertCount(1, $this->fetcher->calls);
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM user_api_archive')->fetchColumn());
    }

    public function testSeasonHistoryIsStampedWithTheCurrentSeasonNotTheOneItDescribes(): void
    {
        $client = $this->client();
        $client->withFetching(static function () use ($client): void {
            $client->getOfficeData();
            $client->getSfHistory(98);
        });

        $row = $this->db->query("SELECT season, race FROM user_api_archive WHERE endpoint = 'SFHistory'")
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['season' => 99, 'race' => 4], $row);
    }

    public function testHistoricalRaceAnalysisIsStampedWithTheRaceItAnalyses(): void
    {
        $client = $this->client();
        $client->withFetching(static function () use ($client): void {
            $client->getOfficeData();
            $client->getRaceAnalysisFor(98, 3);
        });

        $row = $this->db->query("SELECT season, race FROM user_api_archive WHERE endpoint = 'RaceAnalysis'")
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['season' => 98, 'race' => 3], $row);
    }

    public function testTokenScopeAccessorMatchesTheScopeTheArchiveIsKeyedBy(): void
    {
        $this->assertSame(self::scope(), $this->client()->tokenScope());
    }

    public function testArchiveIsPerUser(): void
    {
        $this->sync($this->client(1), 'getOfficeData');
        $this->flushCache();

        $this->sync($this->client(2), 'getOfficeData');

        $this->assertCount(2, $this->fetcher->calls, 'user 2 cannot read user 1 archive');
    }

    public function testIsWarmFromArchiveAfterCacheFlush(): void
    {
        $client = $this->client();
        $this->sync(
            $client,
            'getOfficeData',
            'getCalendar',
            'getCarData',
            'getRaceSetup',
            'getNextRaceProfile',
            'getMyPilotDetails',
        );
        $this->assertTrue($client->isWarm());

        $this->flushCache();
        $this->assertTrue($client->isWarm());
        $this->assertSame(6, count($this->fetcher->calls), 'isWarm never fetches');
    }

    public function testIsWarmFromArchiveRowsOfAnyAgeAndWindow(): void
    {
        $client = $this->client();
        $this->sync(
            $client,
            'getOfficeData',
            'getCalendar',
            'getCarData',
            'getRaceSetup',
            'getNextRaceProfile',
            'getMyPilotDetails',
        );

        $this->db->exec("UPDATE user_api_archive SET race_window = 'an-old-window'");
        $this->ageArchive(30 * 86400);
        $this->flushCache();

        $this->assertTrue($client->isWarm(), 'a stored row of any age or window is served, never refetched');
        $this->assertCount(6, $this->fetcher->calls);
    }

    public function testDecidingAWarmPendingPageRendersNowAndFetchesNothing(): void
    {
        $client = $this->client();
        $this->sync(
            $client,
            'getOfficeData',
            'getCalendar',
            'getCarData',
            'getRaceSetup',
            'getNextRaceProfile',
            'getMyPilotDetails',
        );
        $this->flushCache();
        $spent = count($this->fetcher->calls);

        $isWarm = static fn (): bool => $client->isWarm();
        $deferred = DeferredRender::shouldDefer(false, true, 'Cockpit', $isWarm);

        $this->assertFalse($deferred, 'a warm login renders now');
        $this->assertTrue(DeferredRender::idleSync(false, true, $deferred, false, $isWarm));
        $this->assertSame($spent, count($this->fetcher->calls), 'deciding costs no GPRO call');
    }

    public function testIsWarmFalseWhenArchiveIncompleteAndWithoutUser(): void
    {
        $client = $this->client();
        $this->sync($client, 'getOfficeData', 'getCalendar', 'getCarData');
        $this->flushCache();
        $this->assertFalse($client->isWarm(), 'race_setup missing from the archive');

        $anonymous = $this->client(null);
        $this->assertFalse($anonymous->isWarm());
    }

    public function testArchiveFailureDoesNotBreakFetch(): void
    {
        $broken = new PDO('sqlite::memory:');
        $broken->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $repo = new ApiArchiveRepository($broken, new ArchiveSanitizer());
        $client = $this->client(1, $repo);
        $logged = $this->captureErrorLog();

        $office = $client->withFetching(static fn (): array => $client->getOfficeData());
        $this->flushCache();
        $again = $client->withFetching(static fn (): array => $client->getOfficeData());

        $this->assertSame('Alice', $office['name']);
        $this->assertSame('Alice', $again['name']);
        $this->assertCount(2, $this->fetcher->calls);
        $this->assertFalse($client->isWarm());
        $log = (string) file_get_contents($logged);
        $this->assertStringContainsString('[ApiArchive] PDOException', $log);
        $this->assertStringNotContainsString('user_api_archive', $log, 'class name only, never the message');
    }

    public function testWithScopeForCloneDoesNotArchive(): void
    {
        $client = $this->client();
        $clone = $client->withScopeFor('token-b');

        $this->sync($clone, 'getOfficeData');
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM user_api_archive')->fetchColumn());

        $client->withFetching(static fn (): array => $client->getOfficeData(true));
        $this->flushCache();
        $this->sync($clone, 'getOfficeData');
        $this->assertCount(3, $this->fetcher->calls, 'clone must not read the original user archive');
    }

    public function testDisabledWindowStillServesTheArchive(): void
    {
        $_ENV['GPRO_RACE_DAYS'] = '';
        $client = $this->client();
        $this->assertSame('', $client->archiveWindow());

        $this->sync($client, 'getOfficeData');
        $this->flushCache();

        $this->assertSame('Alice', $client->getOfficeData()['name'] ?? null);
        $this->assertCount(1, $this->fetcher->calls, 'served from the row filed under the empty window');
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM user_api_archive')->fetchColumn());
    }

    public function testIsWarmFromTheArchiveWithDisabledWindow(): void
    {
        $_ENV['GPRO_RACE_DAYS'] = '';
        $client = $this->client();
        $this->assertSame('', $client->archiveWindow());
        $this->archive->put(1, self::scope(), 'office', '', '', 99, 4, ['driId' => '77']);
        foreach (['Calendar', 'UpdateCar', 'RaceSetup', 'TrackProfile'] as $endpoint) {
            $this->archive->put(1, self::scope(), $endpoint, '', '', 99, 4, ['x' => 1]);
        }
        $this->archive->put(1, self::scope(), 'DriProfile', 'id=77', '', 99, 4, ['x' => 1]);

        $this->assertTrue($client->isWarm());
        $this->assertSame([], $this->fetcher->calls);
    }

    public function testAnArchiveHitOfAnyAgeIsRecachedWithoutExpiry(): void
    {
        $cache = new TtlRecordingCache($this->cache);
        $client = new GproApiClient($this->fetcher, $cache, $this->archive);
        $client->setToken(self::TOKEN, 1);
        $key = 'u' . self::scope() . ':office_data';

        $this->sync($client, 'getOfficeData');
        $this->assertArrayHasKey($key, $cache->ttls);
        $this->assertNull($cache->ttls[$key], 'a fresh fetch is cached without expiry');

        $this->ageArchive(259200 + 1000);
        $this->flushCache();
        $cache->ttls = [];
        $this->assertSame('Alice', $client->getOfficeData()['name'] ?? null);

        $this->assertCount(1, $this->fetcher->calls, 'a row older than the old 3-day TTL is still served');
        $this->assertArrayHasKey($key, $cache->ttls);
        $this->assertNull($cache->ttls[$key], 'and re-cached without expiry');
    }

    public function testTokenSwapMissesThePreviousAccountsArchive(): void
    {
        $client = $this->client();
        $this->sync($client, 'getOfficeData');
        $this->flushCache();

        $this->fetcher->name = 'Bob';
        $client->setToken('token-b', 1);
        $this->assertSame([], $client->getOfficeData(), 'outside a sync the new token has nothing yet');
        $office = $client->withFetching(static fn (): array => $client->getOfficeData());

        $this->assertSame('Bob', $office['name']);
        $this->assertCount(2, $this->fetcher->calls, 'the new token fetches its own data');
        $this->assertSame('Bob', $this->archive->latest(1, 'office')['name'] ?? null, 'history spans tokens');
    }

    public function testIsWarmIgnoresThePreviousTokensArchive(): void
    {
        $client = $this->client();
        $this->sync(
            $client,
            'getOfficeData',
            'getCalendar',
            'getCarData',
            'getRaceSetup',
            'getNextRaceProfile',
            'getMyPilotDetails',
        );
        $this->flushCache();

        $client->setToken('token-b', 1);

        $this->assertFalse($client->isWarm());
    }

    public function testArchiveWindowOpensAtRaceStart(): void
    {
        $_ENV['GPRO_RACE_DAYS'] = '2,5';
        $client = $this->client();
        $this->sync($client, 'getOfficeData');

        $expected = RaceWindow::raceStartIdFor(new DateTimeImmutable('now'), [2, 5]);
        $this->assertSame($expected, $client->archiveWindow());
        $this->assertSame(
            $expected,
            $this->db->query("SELECT race_window FROM user_api_archive WHERE endpoint = 'office'")->fetchColumn(),
        );
    }

    public function testRaceAnalysisIsStampedWithTheRaceItAnalyses(): void
    {
        $client = $this->client();
        $this->sync($client, 'getOfficeData', 'getRaceAnalysis');

        $row = $this->db->query("SELECT season, race FROM user_api_archive WHERE endpoint = 'RaceAnalysis'")
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['season' => 98, 'race' => 3], $row, 'not the office\'s next race (99/4)');
    }

    private function ageArchive(int $seconds): void
    {
        $stmt = $this->db->prepare('UPDATE user_api_archive SET fetched_at = :t');
        $stmt->execute([':t' => gmdate('Y-m-d H:i:s', time() - $seconds)]);
    }

    private function captureErrorLog(): string
    {
        $this->logFile = $this->cacheDir . '.log';
        $prior = ini_set('error_log', $this->logFile);
        $this->priorErrorLog = $prior === false ? '' : $prior;

        return $this->logFile;
    }

    public function testForcedPilotDetailsReadTheOfficeThroughTheCache(): void
    {
        $client = $this->client();
        $client->withFetching(static fn (): array => $client->getOfficeData(true));
        $this->fetcher->calls = [];

        $client->withFetching(static fn (): array => $client->getMyPilotDetails(true));

        $this->assertCount(1, $this->fetcher->calls, 'only the DriProfile is fetched, never the office');
        $this->assertStringContainsString('DriProfile', $this->fetcher->calls[0]);
    }

    public function testSyncOfficeRowIsNeverServedAsThePagesOffice(): void
    {
        $_ENV['GPRO_RACE_DAYS'] = '2,5';
        $client = $this->client();
        $window = $client->archiveWindow();
        $client->archiveSyncOffice(['name' => 'Synced', 'seasonNb' => 99, 'raceNb' => 4]);

        $row = $this->archive->getRow(1, self::scope(), 'office', 'sync', $window);
        $this->assertNotNull($row);
        $this->assertSame(99, $row['season']);
        $this->assertSame(4, $row['race']);
        $this->assertNull($this->archive->get(1, self::scope(), 'office', '', $window));

        $this->assertSame([], $client->getOfficeData(), 'a cold read never serves the sync row');
        $this->assertSame(
            'Alice',
            $client->withFetching(static fn (): array => $client->getOfficeData())['name'] ?? null,
            'a cold read inside a sync fetches instead',
        );
    }
}
