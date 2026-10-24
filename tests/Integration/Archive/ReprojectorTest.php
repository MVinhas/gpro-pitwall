<?php

declare(strict_types=1);

namespace App\Tests\Integration\Archive;

use App\Archive\AnalysisReader;
use App\Archive\ArchiveProjector;
use App\Archive\ArchiveSanitizer;
use App\Archive\LedgerProjector;
use App\Archive\RaceSnapshotReader;
use App\Archive\Reprojector;
use App\Archive\StandingsProjector;
use App\Archive\WeekendStateProjector;
use App\Database\DatabaseSeeder;
use App\Repository\ApiArchiveRepository;
use App\Repository\EconomyLedgerRepository;
use App\Repository\ProjectionStateRepository;
use App\Repository\RaceHistoryRepository;
use App\Repository\StandingsRepository;
use App\Repository\TrackRepository;
use App\Repository\WeekendStateRepository;
use App\Security\ApiTokenCrypto;
use App\Service\RaceHistoryService;
use App\Telemetry\RaceHistoryMapper;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Reprojector::class)]
#[CoversClass(ApiArchiveRepository::class)]
final class ReprojectorTest extends TestCase
{
    private const int SEASON = 107;

    private string $dbPath;
    private PDO $db;
    private ApiArchiveRepository $archive;
    private string $logged = '';

    protected function setUp(): void
    {
        $dir = dirname(__DIR__, 3) . '/var/test';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $this->dbPath = $dir . '/reprojector-' . uniqid() . '.sqlite';
        $this->db = new PDO('sqlite:' . $this->dbPath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('PRAGMA foreign_keys = ON');

        (new DatabaseSeeder(
            $this->db,
            ['Concentration' => 'concentration'],
            ['Rookie', 'Amateur', 'Pro', 'Master', 'Elite'],
            [],
            new ApiTokenCrypto('reprojector-test-secret'),
        ))->migrate();
        foreach ([1, 2, 3] as $id) {
            $this->db->exec(
                "INSERT INTO users (id, username, email_encrypted, email_hash) VALUES ({$id}, 'u{$id}', 'e{$id}', 'h{$id}')"
            );
        }

        $this->archive = new ApiArchiveRepository($this->db, new ArchiveSanitizer());
    }

    protected function tearDown(): void
    {
        unset($this->db);
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($this->dbPath . $suffix)) {
                unlink($this->dbPath . $suffix);
            }
        }
    }

    private function makeProjector(?ProjectionStateRepository $state = null): ArchiveProjector
    {
        $snapshots = new RaceSnapshotReader($this->archive);
        $analyses = new AnalysisReader($this->archive);

        return new ArchiveProjector(
            $this->db,
            $state ?? new ProjectionStateRepository($this->db),
            new WeekendStateProjector($this->archive, new WeekendStateRepository($this->db), $snapshots),
            new StandingsProjector($this->archive, new StandingsRepository($this->db), $snapshots),
            new LedgerProjector($this->archive, new EconomyLedgerRepository($this->db), $analyses),
            new RaceHistoryService(
                new RaceHistoryRepository($this->db),
                new RaceHistoryMapper(),
                new TrackRepository($this->db),
            ),
            $snapshots,
            $analyses,
        );
    }

    private function seedRace(int $userId, int $race): void
    {
        $this->archive->put($userId, 'scope', 'office', '', "S" . self::SEASON . "R{$race}", self::SEASON, $race, [
            'seasonNb' => (string) self::SEASON, 'raceNb' => (string) $race, 'cash' => 1000, 'pts' => '10', 'pos' => '3',
        ]);
        $this->db->exec("UPDATE user_api_archive SET fetched_at = '2020-01-01 00:00:00'");
    }

    /**
     * PHPUnit swaps error_log per test, so the capture must live inside the call.
     *
     * @return array{projected: int, stuck: int}
     */
    private function projectQuietly(Reprojector $reprojector, int $userId): array
    {
        $log = tempnam(sys_get_temp_dir(), 'reproj');
        $this->assertNotFalse($log);
        $previous = ini_set('error_log', $log);

        try {
            return $reprojector->projectAllPending($userId);
        } finally {
            ini_set('error_log', (string) $previous);
            $this->logged = (string) file_get_contents($log);
            unlink($log);
        }
    }

    private function pendingCount(int $userId): int
    {
        return count($this->makeProjector()->pendingRaces($userId, 100));
    }

    public function testProjectsEveryPendingRaceUntilNoneAreLeft(): void
    {
        $this->seedRace(1, 5);
        $this->seedRace(1, 6);

        $result = $this->projectQuietly(new Reprojector($this->makeProjector(), $this->archive), 1);

        $this->assertSame(['projected' => 2, 'stuck' => 0], $result);
        $this->assertSame(0, $this->pendingCount(1));
        $this->assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM user_weekend_state')->fetchColumn());
    }

    public function testStaleVersionRacesAreProjectedAgain(): void
    {
        $this->seedRace(1, 5);
        $this->seedRace(1, 6);
        $reprojector = new Reprojector($this->makeProjector(), $this->archive);
        $this->projectQuietly($reprojector, 1);
        $this->db->exec("UPDATE user_projection_state SET projected_at = '2030-01-01 00:00:00'");
        $this->assertSame(0, $this->pendingCount(1));

        $this->db->exec('UPDATE user_projection_state SET version = ' . (ArchiveProjector::PROJECTOR_VERSION - 1));
        $this->assertSame(2, $this->pendingCount(1));

        $result = $this->projectQuietly($reprojector, 1);

        $this->assertSame(['projected' => 2, 'stuck' => 0], $result);
        $this->assertSame(0, $this->pendingCount(1));
    }

    public function testNothingPendingReportsZero(): void
    {
        $this->assertSame(
            ['projected' => 0, 'stuck' => 0],
            $this->projectQuietly(new Reprojector($this->makeProjector(), $this->archive), 1),
        );
    }

    public function testARaceWhoseProjectionAlwaysThrowsIsReportedStuckAndTheLoopEnds(): void
    {
        $this->seedRace(1, 5);
        $this->seedRace(1, 6);
        $state = $this->createStub(ProjectionStateRepository::class);
        $state->method('pendingRaces')->willReturn([
            ['season' => self::SEASON, 'race' => 5],
            ['season' => self::SEASON, 'race' => 6],
        ]);
        $state->method('mark')->willThrowException(new \RuntimeException('secret-detail'));

        $result = $this->projectQuietly(new Reprojector($this->makeProjector($state), $this->archive), 1);

        $this->assertSame(['projected' => 0, 'stuck' => 2], $result);
    }

    public function testARaceThatStaysPendingAfterProjectingIsReportedStuckAndTheLoopEnds(): void
    {
        $this->seedRace(1, 5);
        $state = $this->createStub(ProjectionStateRepository::class);
        $state->method('pendingRaces')->willReturn([['season' => self::SEASON, 'race' => 5]]);

        $result = $this->projectQuietly(new Reprojector($this->makeProjector($state), $this->archive), 1);

        $this->assertSame(['projected' => 1, 'stuck' => 1], $result);
    }

    public function testAStuckRaceDoesNotBlockTheOthers(): void
    {
        $this->seedRace(1, 5);
        $this->seedRace(1, 6);
        $real = new ProjectionStateRepository($this->db);
        $state = $this->createStub(ProjectionStateRepository::class);
        $state->method('pendingRaces')->willReturnCallback(
            static fn (int $u, int $v, int $l): array => $real->pendingRaces($u, $v, $l)
        );
        $state->method('mark')->willReturnCallback(
            static function (int $u, int $s, int $r, int $v) use ($real): void {
                if ($r === 5) {
                    throw new \RuntimeException('boom');
                }
                $real->mark($u, $s, $r, $v);
            }
        );

        $result = $this->projectQuietly(new Reprojector($this->makeProjector($state), $this->archive), 1);

        $this->assertSame(['projected' => 1, 'stuck' => 1], $result);
    }

    public function testOnlyTheExceptionClassIsLogged(): void
    {
        $this->seedRace(1, 5);
        $state = $this->createStub(ProjectionStateRepository::class);
        $state->method('pendingRaces')->willReturn([['season' => self::SEASON, 'race' => 5]]);
        $state->method('mark')->willThrowException(new \RuntimeException('secret-detail'));

        $this->projectQuietly(new Reprojector($this->makeProjector($state), $this->archive), 1);

        $logged = $this->logged;
        $this->assertStringContainsString(\RuntimeException::class, $logged);
        $this->assertStringNotContainsString('secret-detail', $logged);
    }

    public function testUserIdsListsOnlyUsersWithArchiveRowsInAscendingOrder(): void
    {
        $this->seedRace(3, 5);
        $this->seedRace(1, 5);
        $this->seedRace(3, 6);

        $this->assertSame([1, 3], $this->archive->userIds());
    }

    #[DataProvider('validUserArgs')]
    public function testParsesTheUserOption(array $args, ?int $expected): void
    {
        $this->assertSame($expected, Reprojector::parseUser($args));
    }

    /** @return iterable<string, array{list<string>, ?int}> */
    public static function validUserArgs(): iterable
    {
        yield 'no flag' => [[], null];
        yield 'user' => [['--user=7'], 7];
    }

    #[DataProvider('invalidUserArgs')]
    public function testRejectsAnythingButAPositiveIntegerUser(string $arg): void
    {
        $this->expectException(InvalidArgumentException::class);

        Reprojector::parseUser([$arg]);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidUserArgs(): iterable
    {
        yield 'zero' => ['--user=0'];
        yield 'negative' => ['--user=-3'];
        yield 'text' => ['--user=abc'];
        yield 'empty' => ['--user='];
        yield 'float' => ['--user=1.5'];
        yield 'trailing junk' => ['--user=5x'];
        yield 'unknown flag' => ['--all'];
        yield 'bare value' => ['7'];
    }

    public function testRejectsTwoArguments(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Reprojector::parseUser(['--user=1', '--user=2']);
    }
}
