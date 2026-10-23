<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Archive\ArchiveSanitizer;
use App\Cache\Adapter\FilesystemCache;
use App\Database\DatabaseSeeder;
use App\Repository\ApiArchiveRepository;
use App\Security\ApiTokenCrypto;
use App\Tests\Support\CountingGproFetcher;
use App\Service\GproApiClient;
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

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/gpro_archive_' . bin2hex(random_bytes(6));
        $this->cache = new FilesystemCache($this->cacheDir);

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

        $office = $this->archive->get(1, 'office', '', $window);
        $this->assertNotNull($office);
        $this->assertSame('Alice', $office['name']);
        $this->assertArrayNotHasKey('email', $office);
        $this->assertArrayNotHasKey('apiRequestsRemaining', $office);
        $this->assertNotNull($this->archive->get(1, 'DriProfile', 'id=77', $window));
        $this->assertNull($this->archive->get(2, 'office', '', $window), 'archive is per user');
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
        $row = $this->archive->get(1, 'office', '', $client->archiveWindow());
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

    private function captureErrorLog(): string
    {
        $this->logFile = $this->cacheDir . '.log';
        $prior = ini_set('error_log', $this->logFile);
        $this->priorErrorLog = $prior === false ? '' : $prior;

        return $this->logFile;
    }
}
