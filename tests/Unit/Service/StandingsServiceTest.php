<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Archive\ArchiveSanitizer;
use App\Database\DatabaseSeeder;
use App\Engineer\SeasonObjective;
use App\Repository\ApiArchiveRepository;
use App\Security\ApiTokenCrypto;
use App\Service\StandingsService;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StandingsService::class)]
final class StandingsServiceTest extends TestCase
{
    private const int USER = 7;

    private ApiArchiveRepository $archive;

    protected function setUp(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $crypto = new ApiTokenCrypto('standings-service-test');
        (new DatabaseSeeder($db, ['Concentration' => 'concentration'], ['Rookie'], [], $crypto))->migrate();
        $db->exec("INSERT INTO users (id, username, email_encrypted, email_hash) VALUES (7, 'tester', 'x', 'hash')");
        $this->archive = new ApiArchiveRepository($db, new ArchiveSanitizer());
    }

    /** A two-manager table with $run races run. */
    private function standings(int $season, int $run, string $window): void
    {
        $results = static fn (int $pos): array => array_pad(
            array_fill(0, $run, ['pos' => (string) $pos, 'pts' => '0', 'grid' => '-']),
            SeasonObjective::RACES,
            ['pos' => '-', 'pts' => '-', 'grid' => '-'],
        );
        $this->archive->put(self::USER, 'scope', 'Standings', '', $window, $season, $run + 1, [
            'group' => 'Rookie - 1',
            'managers' => [
                ['name' => 'A', 'pos' => '1', 'IDM' => 1, 'pts' => '10', 'results' => $results(1)],
                ['name' => 'B', 'pos' => '2', 'IDM' => 2, 'pts' => '8', 'results' => $results(2)],
            ],
        ]);
    }

    private function office(int $season, string $window): void
    {
        $this->archive->put(self::USER, 'scope', 'office', '', $window, $season, 1, ['seasonNb' => $season]);
    }

    public function testAMidSeasonTableIsNotFlaggedAsFinal(): void
    {
        $this->standings(113, 11, '2026-11-20');
        $this->office(113, '2026-11-20');

        $view = (new StandingsService($this->archive))->build(self::USER, 'scope');

        self::assertTrue($view['available']);
        self::assertSame(113, $view['season']);
        self::assertFalse($view['final_table']);
    }

    public function testAFullSeasonIsTheFinalTable(): void
    {
        // Before the new season's first sync the archive still holds this.
        $this->standings(113, SeasonObjective::RACES, '2026-12-20');

        self::assertTrue((new StandingsService($this->archive))->build(self::USER, 'scope')['final_table']);
    }

    public function testATableOlderThanTheLatestOfficeIsTheFinalTable(): void
    {
        $this->standings(113, 16, '2026-12-15');
        $this->office(114, '2027-01-05');

        $view = (new StandingsService($this->archive))->build(self::USER, 'scope');

        self::assertSame(113, $view['season']);
        self::assertTrue($view['final_table']);
    }

    public function testNoArchivedTableIsUnavailable(): void
    {
        self::assertSame(['available' => false], (new StandingsService($this->archive))->build(self::USER, 'scope'));
    }
}
