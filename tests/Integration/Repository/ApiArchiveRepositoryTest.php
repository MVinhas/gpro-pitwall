<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Archive\ArchiveSanitizer;
use App\Database\DatabaseSeeder;
use App\Repository\ApiArchiveRepository;
use App\Security\ApiTokenCrypto;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiArchiveRepository::class)]
final class ApiArchiveRepositoryTest extends TestCase
{
    private PDO $db;
    private ApiArchiveRepository $repo;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('PRAGMA foreign_keys = ON');

        (new DatabaseSeeder(
            $this->db,
            ['Concentration' => 'concentration'],
            ['Rookie', 'Amateur', 'Pro', 'Master', 'Elite'],
            [],
            new ApiTokenCrypto('archive-test-secret'),
        ))->migrate();

        foreach ([1, 2] as $id) {
            $this->db->exec(
                "INSERT INTO users (id, username, email_encrypted, email_hash) "
                . "VALUES ({$id}, 'user{$id}', 'enc{$id}', 'hash{$id}')"
            );
        }

        $this->repo = new ApiArchiveRepository($this->db, new ArchiveSanitizer());
    }

    private function rowCount(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM user_api_archive')->fetchColumn();
    }

    public function testPutThenGetRoundTrips(): void
    {
        $payload = ['position' => 3, 'laps' => [1, 2, 3], 'name' => 'Ünïcode'];

        $this->repo->put(1, 'sA', 'RaceAnalysis', 'id=5', 'S107R13', 107, 13, $payload);

        $this->assertSame($payload, $this->repo->get(1, 'sA', 'RaceAnalysis', 'id=5', 'S107R13'));
    }

    public function testSameKeyTwiceKeepsOneRowWithNewerPayload(): void
    {
        $this->repo->put(1, 'sA', 'Office', '', 'S107R13', 107, 13, ['v' => 1]);
        $this->repo->put(1, 'sA', 'Office', '', 'S107R13', 107, 13, ['v' => 2]);

        $this->assertSame(1, $this->rowCount());
        $this->assertSame(['v' => 2], $this->repo->get(1, 'sA', 'Office', '', 'S107R13'));
    }

    public function testLatestReturnsNewestAcrossWindows(): void
    {
        $this->repo->put(1, 'sA', 'Office', '', 'S107R12', 107, 12, ['v' => 'old']);
        $this->repo->put(1, 'sA', 'Office', '', 'S107R13', 107, 13, ['v' => 'new']);
        $this->db->exec("UPDATE user_api_archive SET fetched_at = '2026-01-01 10:00:00' WHERE race_window = 'S107R12'");
        $this->db->exec("UPDATE user_api_archive SET fetched_at = '2026-01-02 10:00:00' WHERE race_window = 'S107R13'");

        $this->assertSame(['v' => 'new'], $this->repo->latest(1, 'Office'));
    }

    public function testLatestBreaksSameSecondTiesByNewestRow(): void
    {
        $this->repo->put(1, 'sA', 'Office', '', 'a', null, null, ['v' => 'first']);
        $this->repo->put(1, 'sA', 'Office', '', 'b', null, null, ['v' => 'second']);
        $this->db->exec("UPDATE user_api_archive SET fetched_at = '2026-01-01 10:00:00'");

        $this->assertSame(['v' => 'second'], $this->repo->latest(1, 'Office'));
    }

    public function testLatestFiltersByParamsWhenGiven(): void
    {
        $this->repo->put(1, 'sA', 'Driver', 'id=1', 'w', null, null, ['who' => 1]);
        $this->repo->put(1, 'sA', 'Driver', 'id=2', 'w', null, null, ['who' => 2]);

        $this->assertSame(['who' => 1], $this->repo->latest(1, 'Driver', 'id=1'));
    }

    public function testLatestWithoutParamsIgnoresParameterisedRows(): void
    {
        $this->repo->put(1, 'sA', 'Driver', '', 'w1', null, null, ['who' => 'plain']);
        $this->repo->put(1, 'sA', 'Driver', 'id=2', 'w2', null, null, ['who' => 'param']);
        $this->db->exec("UPDATE user_api_archive SET fetched_at = '2026-01-01 10:00:00' WHERE params = ''");
        $this->db->exec("UPDATE user_api_archive SET fetched_at = '2026-01-02 10:00:00' WHERE params = 'id=2'");

        $this->assertSame(['who' => 'plain'], $this->repo->latest(1, 'Driver'));

        $this->db->exec("DELETE FROM user_api_archive WHERE params = ''");

        $this->assertNull($this->repo->latest(1, 'Driver'));
    }

    public function testEmailIsAbsentAfterGet(): void
    {
        $this->repo->put(1, 'sA', 'Office', '', 'w', null, null, ['name' => 'A', 'email' => 'a@example.com']);

        $stored = $this->repo->get(1, 'sA', 'Office', '', 'w');

        $this->assertNotNull($stored);
        $this->assertArrayNotHasKey('email', $stored);
        $this->assertSame('A', $stored['name']);
    }

    public function testHasIsFalseForAnotherUser(): void
    {
        $this->repo->put(1, 'sA', 'Office', '', 'w', null, null, ['v' => 1]);

        $this->assertTrue($this->repo->has(1, 'sA', 'Office', '', 'w'));
        $this->assertFalse($this->repo->has(2, 'sA', 'Office', '', 'w'));
        $this->assertNull($this->repo->get(2, 'sA', 'Office', '', 'w'));
        $this->assertNull($this->repo->latest(2, 'Office'));
    }

    public function testParamsForReturnsDistinctParamsForThatUserAndEndpoint(): void
    {
        $this->repo->put(1, 'sA', 'Driver', 'id=1', 'w1', null, null, ['a' => 1]);
        $this->repo->put(1, 'sA', 'Driver', 'id=1', 'w2', null, null, ['a' => 2]);
        $this->repo->put(1, 'sA', 'Driver', 'id=2', 'w1', null, null, ['a' => 3]);
        $this->repo->put(1, 'sA', 'Office', 'x', 'w1', null, null, ['a' => 4]);
        $this->repo->put(2, 'sA', 'Driver', 'id=9', 'w1', null, null, ['a' => 5]);

        $params = $this->repo->paramsFor(1, 'Driver');
        sort($params);

        $this->assertSame(['id=1', 'id=2'], $params);
    }

    public function testCorruptBlobReturnsNull(): void
    {
        $this->repo->put(1, 'sA', 'Office', '', 'w', null, null, ['v' => 1]);

        $stmt = $this->db->prepare('UPDATE user_api_archive SET payload_gz = :b');
        $stmt->bindValue(':b', 'not gzip at all', PDO::PARAM_LOB);
        $stmt->execute();

        $this->assertNull($this->repo->get(1, 'sA', 'Office', '', 'w'));
        $this->assertNull($this->repo->latest(1, 'Office'));

        $stmt->bindValue(':b', gzencode('{broken json'), PDO::PARAM_LOB);
        $stmt->execute();

        $this->assertNull($this->repo->get(1, 'sA', 'Office', '', 'w'));
    }

    public function testReadThroughIsKeyedByTokenScopeButHistoryIsNot(): void
    {
        $this->repo->put(1, 'sA', 'office', '', 'w', 99, 4, ['v' => 'A']);
        $this->repo->put(1, 'sB', 'office', '', 'w', 99, 4, ['v' => 'B']);
        $this->db->exec("UPDATE user_api_archive SET fetched_at = '2026-01-01 10:00:00' WHERE token_scope = 'sA'");
        $this->db->exec("UPDATE user_api_archive SET fetched_at = '2026-01-02 10:00:00' WHERE token_scope = 'sB'");

        $this->assertSame(2, $this->rowCount(), 'one row per token scope');
        $this->assertSame(['v' => 'A'], $this->repo->get(1, 'sA', 'office', '', 'w'));
        $this->assertSame(['v' => 'B'], $this->repo->get(1, 'sB', 'office', '', 'w'));
        $this->assertFalse($this->repo->has(1, 'sC', 'office', '', 'w'));
        $this->assertNull($this->repo->getRow(1, 'sC', 'office', '', 'w'));
        $this->assertSame(['v' => 'B'], $this->repo->latest(1, 'office'), 'history spans token scopes');
        $this->assertSame([''], $this->repo->paramsFor(1, 'office'));
    }

    public function testGetRowCarriesFetchedAtAndStamp(): void
    {
        $this->repo->put(1, 'sA', 'RaceAnalysis', '', 'w', 99, 3, ['v' => 1]);
        $this->db->exec("UPDATE user_api_archive SET fetched_at = '2026-01-02 10:00:00'");

        $this->assertSame(
            ['payload' => ['v' => 1], 'fetched_at' => '2026-01-02 10:00:00', 'season' => 99, 'race' => 3],
            $this->repo->getRow(1, 'sA', 'RaceAnalysis', '', 'w'),
        );

        $this->repo->put(1, 'sA', 'Calendar', '', 'w', null, null, ['v' => 2]);
        $row = $this->repo->getRow(1, 'sA', 'Calendar', '', 'w');
        $this->assertNotNull($row);
        $this->assertNull($row['season']);
        $this->assertNull($row['race']);

        $stmt = $this->db->prepare("UPDATE user_api_archive SET payload_gz = :b WHERE endpoint = 'Calendar'");
        $stmt->bindValue(':b', 'not gzip at all', PDO::PARAM_LOB);
        $stmt->execute();
        $this->assertNull($this->repo->getRow(1, 'sA', 'Calendar', '', 'w'), 'corrupt row reads as absent');
    }

    public function testEndpointsForRaceListsDistinctEndpointsStampedWithThatRace(): void
    {
        $this->repo->put(1, 'sA', 'UpdateCar', '', 'w1', 107, 5, ['a' => 1]);
        $this->repo->put(1, 'sA', 'DriProfile', 'id=1', 'w1', 107, 5, ['a' => 1]);
        $this->repo->put(1, 'sA', 'DriProfile', 'id=2', 'w1', 107, 5, ['a' => 1]);
        $this->repo->put(1, 'sB', 'UpdateCar', '', 'w1', 107, 5, ['a' => 1]);
        $this->repo->put(1, 'sA', 'RaceSetup', '', 'w0', 107, 4, ['a' => 1]);
        $this->repo->put(2, 'sA', 'Testing', '', 'w1', 107, 5, ['a' => 1]);

        $found = $this->repo->endpointsForRace(1, 107, 5);
        sort($found);

        $this->assertSame(['DriProfile', 'UpdateCar'], $found);
        $this->assertSame([], $this->repo->endpointsForRace(1, 107, 9));
    }

    public function testEndpointsForSeasonSpansRacesOfThatSeasonOnly(): void
    {
        $this->repo->put(1, 'sA', 'Calendar', '', 'w1', 107, 5, ['a' => 1]);
        $this->repo->put(1, 'sA', 'Menu', '', 'w0', 107, 4, ['a' => 1]);
        $this->repo->put(1, 'sA', 'TyreSuppliers', '', 'w9', 106, 12, ['a' => 1]);
        $this->repo->put(2, 'sA', 'MoneyLevels', '', 'w1', 107, 5, ['a' => 1]);

        $found = $this->repo->endpointsForSeason(1, 107);
        sort($found);

        $this->assertSame(['Calendar', 'Menu'], $found);
    }
}
