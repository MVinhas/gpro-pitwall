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
use App\Support\RaceWindow;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
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
        $client = new GproApiClient($this->fetcher, $this->cache, $archive ?? $this->archive);
        $client->setToken(self::TOKEN, $userId);

        return $client;
    }

    private static function scope(): string
    {
        return GproApiClient::scopeFor(self::TOKEN);
    }

    private function flushCache(): void
    {
        $this->cache->clear();
    }

    public function testFetchWritesArchive(): void
    {
        $client = $this->client();
        $window = $client->archiveWindow();

        $client->getOfficeData();
        $client->getMyPilotDetails();

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
        $client->getOfficeData();
        $client->getCarData();

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
        $client->getOfficeData();

        $this->assertSame(88, $client->lastKnownRemaining());
        $cached = $this->cache->get('u' . GproApiClient::scopeFor(self::TOKEN) . ':office_data');
        $this->assertIsArray($cached);
        $this->assertSame(88, $cached['apiRequestsRemaining']);
    }

    public function testCacheMissServedFromArchiveWithoutFetch(): void
    {
        $client = $this->client();
        $client->getOfficeData();
        $client->getCarData();
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

    public function testForceBypassesArchiveAndRefreshesIt(): void
    {
        $client = $this->client();
        $client->getOfficeData();
        $this->fetcher->name = 'Bob';

        $client->getOfficeData(true);

        $this->assertCount(2, $this->fetcher->calls);
        $row = $this->archive->get(1, self::scope(), 'office', '', $client->archiveWindow());
        $this->assertSame('Bob', $row['name'] ?? null);
    }

    public function testNoArchiveWithoutUserId(): void
    {
        $client = $this->client(null);
        $client->getOfficeData();
        $this->flushCache();
        $client->getOfficeData();

        $this->assertCount(2, $this->fetcher->calls);
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM user_api_archive')->fetchColumn());
    }

    public function testSharedKeysNotArchived(): void
    {
        $client = $this->client();
        $client->getTyreSuppliers();
        $client->getAllTracksPreview();
        $this->flushCache();
        $client->getTyreSuppliers();

        $this->assertCount(3, $this->fetcher->calls, 'shared keys refetch after a flush');
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM user_api_archive')->fetchColumn());
    }

    public function testArchiveIsPerUser(): void
    {
        $this->client(1)->getOfficeData();
        $this->flushCache();

        $this->client(2)->getOfficeData();

        $this->assertCount(2, $this->fetcher->calls, 'user 2 cannot read user 1 archive');
    }

    public function testIsWarmFromArchiveAfterCacheFlush(): void
    {
        $client = $this->client();
        $client->getOfficeData();
        $client->getCalendar();
        $client->getCarData();
        $client->getRaceSetup();
        $client->getNextRaceProfile();
        $client->getMyPilotDetails();
        $this->assertTrue($client->isWarm());

        $this->flushCache();
        $this->assertTrue($client->isWarm());
        $this->assertSame(6, count($this->fetcher->calls), 'isWarm never fetches');
    }

    public function testIsWarmFalseWhenArchiveIncompleteAndWithoutUser(): void
    {
        $client = $this->client();
        $client->getOfficeData();
        $client->getCalendar();
        $client->getCarData();
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

        $office = $client->getOfficeData();
        $this->flushCache();
        $again = $client->getOfficeData();

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

        $clone->getOfficeData();
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM user_api_archive')->fetchColumn());

        $client->getOfficeData(true);
        $this->flushCache();
        $clone->getOfficeData();
        $this->assertCount(3, $this->fetcher->calls, 'clone must not read the original user archive');
    }

    public function testDisabledWindowNeverReadsArchive(): void
    {
        $_ENV['GPRO_RACE_DAYS'] = '';
        $client = $this->client();
        $this->assertSame('', $client->archiveWindow());

        $client->getOfficeData();
        $this->flushCache();
        $client->getOfficeData();

        $this->assertCount(2, $this->fetcher->calls, 'a row without a window is never current');
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM user_api_archive')->fetchColumn());
    }

    public function testIsWarmFalseWithDisabledWindowEvenWithArchiveRows(): void
    {
        $_ENV['GPRO_RACE_DAYS'] = '';
        $client = $this->client();
        $this->assertSame('', $client->archiveWindow());
        $this->archive->put(1, self::scope(), 'office', '', '', 99, 4, ['driId' => '77']);
        foreach (['Calendar', 'UpdateCar', 'RaceSetup', 'TrackProfile'] as $endpoint) {
            $this->archive->put(1, self::scope(), $endpoint, '', '', 99, 4, ['x' => 1]);
        }
        $this->archive->put(1, self::scope(), 'DriProfile', 'id=77', '', 99, 4, ['x' => 1]);

        $this->assertFalse($client->isWarm());
    }

    public function testArchiveHitIsRecachedForTheRowsRemainingTtl(): void
    {
        $cache = new TtlRecordingCache($this->cache);
        $client = new GproApiClient($this->fetcher, $cache, $this->archive);
        $client->setToken(self::TOKEN, 1);
        $key = 'u' . self::scope() . ':office_data';

        $client->getOfficeData();
        $this->assertSame(259200, $cache->ttls[$key], 'a fresh fetch keeps the full TTL');

        $this->ageArchive(1000);
        $this->flushCache();
        $client->getOfficeData();

        $this->assertCount(1, $this->fetcher->calls);
        $this->assertGreaterThanOrEqual(259200 - 1000 - 5, $cache->ttls[$key]);
        $this->assertLessThanOrEqual(259200 - 1000, $cache->ttls[$key]);
    }

    public function testArchiveRowOlderThanTheTtlIsAMiss(): void
    {
        $cache = new TtlRecordingCache($this->cache);
        $client = new GproApiClient($this->fetcher, $cache, $this->archive);
        $client->setToken(self::TOKEN, 1);
        $key = 'u' . self::scope() . ':office_data';

        $client->getOfficeData();
        $this->ageArchive(259200);
        $this->flushCache();
        $client->getOfficeData();

        $this->assertCount(2, $this->fetcher->calls, 'an expired row is fetched again');
        $this->assertSame(259200, $cache->ttls[$key]);
    }

    public function testTokenSwapMissesThePreviousAccountsArchive(): void
    {
        $client = $this->client();
        $client->getOfficeData();
        $this->flushCache();

        $this->fetcher->name = 'Bob';
        $client->setToken('token-b', 1);
        $office = $client->getOfficeData();

        $this->assertSame('Bob', $office['name']);
        $this->assertCount(2, $this->fetcher->calls, 'the new token fetches its own data');
        $this->assertSame('Bob', $this->archive->latest(1, 'office')['name'] ?? null, 'history spans tokens');
    }

    public function testIsWarmIgnoresThePreviousTokensArchive(): void
    {
        $client = $this->client();
        $client->getOfficeData();
        $client->getCalendar();
        $client->getCarData();
        $client->getRaceSetup();
        $client->getNextRaceProfile();
        $client->getMyPilotDetails();
        $this->flushCache();

        $client->setToken('token-b', 1);

        $this->assertFalse($client->isWarm());
    }

    public function testArchiveWindowOpensAtRaceStart(): void
    {
        $_ENV['GPRO_RACE_DAYS'] = '2,5';
        $client = $this->client();
        $client->getOfficeData();

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
        $client->getOfficeData();
        $client->getRaceAnalysis();

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
        $client->getOfficeData(true);
        $this->fetcher->calls = [];

        $client->getMyPilotDetails(true);

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

        $this->assertSame('Alice', $client->getOfficeData()['name'], 'a cold read fetches instead of serving the sync row');
    }
}
