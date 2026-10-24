<?php

declare(strict_types=1);

namespace App\Tests\Integration\Archive;

use App\Archive\AnalysisReader;
use App\Archive\ArchiveProjector;
use App\Archive\ArchiveSanitizer;
use App\Archive\LedgerProjector;
use App\Archive\RaceSnapshotReader;
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
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArchiveProjector::class)]
#[CoversClass(WeekendStateProjector::class)]
#[CoversClass(StandingsProjector::class)]
#[CoversClass(LedgerProjector::class)]
#[CoversClass(ProjectionStateRepository::class)]
final class ArchiveProjectorTest extends TestCase
{
    private const int USER = 1;
    private const int SEASON = 107;
    private const string SCOPE = 'scope-a';

    private string $dbPath;
    private PDO $db;
    private ApiArchiveRepository $archive;
    private ArchiveProjector $projector;

    protected function setUp(): void
    {
        $dir = dirname(__DIR__, 3) . '/var/test';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $this->dbPath = $dir . '/projector-' . uniqid() . '.sqlite';
        $this->db = new PDO('sqlite:' . $this->dbPath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('PRAGMA foreign_keys = ON');

        (new DatabaseSeeder(
            $this->db,
            ['Concentration' => 'concentration'],
            ['Rookie', 'Amateur', 'Pro', 'Master', 'Elite'],
            [],
            new ApiTokenCrypto('projector-test-secret'),
        ))->migrate();
        $this->db->exec(
            "INSERT INTO users (id, username, email_encrypted, email_hash) VALUES (1, 'u1', 'e1', 'h1')"
        );
        $this->db->exec(
            "INSERT INTO users (id, username, email_encrypted, email_hash) VALUES (2, 'u2', 'e2', 'h2')"
        );

        $this->archive = new ApiArchiveRepository($this->db, new ArchiveSanitizer());
        $this->projector = $this->makeProjector();
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

    private function makeProjector(
        ?StandingsRepository $standings = null,
    ): ArchiveProjector {
        $snapshots = new RaceSnapshotReader($this->archive);
        $analyses = new AnalysisReader($this->archive);

        return new ArchiveProjector(
            $this->db,
            new ProjectionStateRepository($this->db),
            new WeekendStateProjector($this->archive, new WeekendStateRepository($this->db), $snapshots),
            new StandingsProjector(
                $this->archive,
                $standings ?? new StandingsRepository($this->db),
                $snapshots,
            ),
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

    /** @param array<string, mixed> $payload */
    private function put(
        string $endpoint,
        array $payload,
        ?int $race,
        string $params = '',
        int $userId = self::USER,
        ?int $season = self::SEASON,
        string $window = '',
    ): void {
        $this->archive->put(
            $userId,
            self::SCOPE,
            $endpoint,
            $params,
            $window === '' ? "S{$season}R{$race}" : $window,
            $season,
            $race,
            $payload,
        );
    }

    /** @return array<string, mixed> */
    private function office(int $race, array $extra = []): array
    {
        return $extra + [
            'seasonNb' => (string) self::SEASON, 'raceNb' => (string) $race,
            'cash' => 12500000, 'pts' => '88', 'pos' => '4',
            'driId' => '555', 'driName' => 'Ann Driver', 'driOA' => '140',
            'driRacesLeft' => '8', 'driSalary' => '1200000', 'driEnergy' => '95',
            'tdId' => '777', 'tdName' => 'Tom Director', 'tdOA' => '120',
            'tdRacesLeft' => '5', 'tdSalary' => '900000',
        ];
    }

    /** @return array<string, mixed> */
    private function staff(int $facilityBase = 4): array
    {
        return [
            'overall' => 90, 'concentration' => 80, 'experience' => 70, 'technical' => 60,
            'stressHandling' => 50, 'motivation' => 40, 'efficiency' => 30, 'salary' => 2000000,
            'lvlWindtunnel' => $facilityBase, 'lvlPitstop' => $facilityBase + 1,
            'lvlRDWorkshop' => $facilityBase + 2, 'lvlRDDesign' => $facilityBase + 3,
            'lvlEngineering' => $facilityBase + 4, 'lvlLab' => $facilityBase + 5,
            'lvlCommercial' => $facilityBase + 6,
            'availTrainings' => [['value' => '1', 'name' => 'x', 'cost' => '1']],
        ];
    }

    /** @return array<string, mixed> */
    private function car(): array
    {
        return [
            'carPower' => 90, 'carHandl' => 80, 'carAccel' => 70,
            'tPower' => 91, 'tHandl' => 81, 'tAccel' => 71,
            'lvlChassis' => 5, 'usaChassis' => 12, 'lvlEngine' => 6, 'usaEngine' => 13,
            'chassisOptions' => [['value' => 1]],
        ];
    }

    /** @return array<string, mixed> */
    private function driProfile(): array
    {
        return [
            'driName' => 'Ann Driver', 'overall' => 140, 'age' => 27, 'energy' => 95,
            'concentration' => 80, 'talent' => 70, 'aggressiveness' => 60, 'experience' => 50,
            'techInsight' => 40, 'stamina' => 30, 'charisma' => 20, 'motivation' => 10,
            'reputation' => 5, 'weight' => 66, 'races' => 120, 'wins' => 9, 'podiums' => 30,
            'faceSVG' => '<svg/>', 'ownDriver' => 1,
        ];
    }

    /** @return array<string, mixed> */
    private function tdProfile(): array
    {
        return [
            'tdName' => 'Tom Director', 'ownTD' => 1, 'overall' => 120, 'leadership' => 60,
            'mechanics' => 50, 'electronics' => 40, 'experience' => 30, 'aerodynamics' => 20,
            'pitCoord' => 10, 'motivation' => 5, 'age' => 45,
        ];
    }

    /** @return array<string, mixed> */
    private function analysis(int $race, int $driverId = 555): array
    {
        $laps = [['idx' => 0, 'pos' => 8, 'tyres' => 'Hard', 'weather' => 'Sunny', 'temp' => 44, 'hum' => 35]];
        for ($i = 1; $i <= 10; $i++) {
            $laps[] = [
                'idx' => $i, 'pos' => 3, 'tyres' => 'Hard', 'weather' => 'Sunny',
                'temp' => 44, 'hum' => 35, 'lapTime' => '1:14.100',
            ];
        }

        return [
            'selSeasonNb' => (string) self::SEASON, 'selRaceNb' => (string) $race,
            'group' => 'Amateur - 4', 'trackName' => 'Hockenheim (Germany)', 'trackId' => '17',
            'startFuel' => 50, 'finishFuel' => 5, 'finishTyres' => 40,
            'driver' => ['id' => $driverId, 'name' => 'Ann Driver', 'OA' => '140'],
            'laps' => $laps, 'pits' => [], 'problems' => [],
            'setupsUsed' => [
                ['session' => 'Race', 'setFWing' => '339', 'setRWing' => '777', 'setEng' => '741',
                 'setBra' => '377', 'setGear' => '556', 'setSusp' => '250', 'setTyres' => 'Hard'],
            ],
            'transactions' => [
                ['desc' => 'Sponsors', 'amount' => 500000],
                ['desc' => 'Driver salary', 'amount' => -100000],
            ],
        ];
    }

    private function seedSnapshot(int $race): void
    {
        $this->put('office', $this->office($race), $race);
        $this->put('DriProfile', $this->driProfile(), $race, 'id=555');
        $this->put('TDProfile', $this->tdProfile(), $race);
        $this->put('StaffAndFacilities', $this->staff(), $race);
        $this->put('UpdateCar', $this->car(), $race);
    }

    /** @return array<string, mixed>|null */
    private function row(string $sql): ?array
    {
        $stmt = $this->db->query($sql);
        $row = $stmt === false ? false : $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function rows(string $table): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function json(?string $raw): array
    {
        $this->assertNotNull($raw);
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    public function testWeekendStateFromSnapshotPayloads(): void
    {
        $this->seedSnapshot(5);

        $this->projector->projectRace(self::USER, self::SEASON, 5);

        $row = $this->row('SELECT * FROM user_weekend_state');
        $this->assertNotNull($row);
        $this->assertSame('snapshot', $row['source']);
        $this->assertSame(12500000, (int) $row['cash']);
        $this->assertSame(88, (int) $row['pts']);
        $this->assertSame(4, (int) $row['pos']);

        $facilities = $this->json($row['facilities_json']);
        $this->assertCount(7, $facilities);
        $this->assertSame(4, $facilities['windtunnel']);
        $this->assertSame(10, $facilities['commercial']);

        $staff = $this->json($row['staff_json']);
        $this->assertSame(90, $staff['overall']);
        $this->assertSame(50, $staff['stressHandling']);
        $this->assertArrayNotHasKey('availTrainings', $staff);

        $car = $this->json($row['car_json']);
        $this->assertSame(90, $car['power']);
        $this->assertSame(71, $car['tAccel']);
        $this->assertSame(12, $car['parts']['chassis']['wear']);
        $this->assertArrayNotHasKey('chassisOptions', $car);

        $driver = $this->json($row['driver_json']);
        $this->assertSame('Ann Driver', $driver['name']);
        $this->assertSame(27, $driver['profile']['age']);
        $this->assertArrayNotHasKey('faceSVG', $driver['profile']);

        $td = $this->json($row['td_json']);
        $this->assertSame('Tom Director', $td['name']);
        $this->assertSame(120, $td['profile']['overall']);
    }

    public function testWeekendStateFallsBackToSfAndCcpHistory(): void
    {
        $this->put('SFHistory', ['data' => [
            ['race' => 4, 'oa' => 10],
            ['race' => 5, 'oa' => 91, 'exp' => 71, 'mot' => 41, 'tech' => 61, 'stress' => 51, 'conc' => 81,
             'eff' => 31, 'wind' => 3, 'pit' => 4, 'rdWorkshop' => 5, 'design' => 6, 'engWorkshop' => 7,
             'lab' => 8, 'commercial' => 9],
        ]], 12, 'season=' . self::SEASON);
        $this->put('CCPHistory', ['data' => [
            ['race' => 5, 'power' => 50, 'handl' => 51, 'accel' => 52, 'tPower' => 53, 'tHandl' => 54, 'tAccel' => 55],
        ]], 12, 'season=' . self::SEASON);

        $this->projector->projectRace(self::USER, self::SEASON, 5);

        $row = $this->row('SELECT * FROM user_weekend_state');
        $this->assertNotNull($row);
        $this->assertSame('history', $row['source']);
        $this->assertNull($row['cash']);
        $this->assertNull($row['driver_json']);
        $this->assertNull($row['td_json']);
        $facilities = $this->json($row['facilities_json']);
        $this->assertSame(3, $facilities['windtunnel']);
        $this->assertSame(9, $facilities['commercial']);
        $this->assertSame(91, $this->json($row['staff_json'])['overall']);
        $this->assertSame(50, $this->json($row['car_json'])['power']);
    }

    public function testNoSnapshotAndNoHistoryEntryWritesNoWeekendRowButMarksRaceProjected(): void
    {
        $this->put('SFHistory', ['data' => [['race' => 1, 'oa' => 10]]], 12, 'season=' . self::SEASON);

        $this->projector->projectRace(self::USER, self::SEASON, 5);

        $this->assertSame(0, $this->rows('user_weekend_state'));
        $this->assertSame(1, $this->rows('user_projection_state'));
    }

    public function testUnavailablePayloadsAreNeverProjected(): void
    {
        $this->put('SFHistory', ['errorMsg' => 'Supporters only', 'data' => []], 12, 'season=' . self::SEASON);
        $this->put('CCPHistory', ['errorMsg' => 'Supporters only', 'data' => []], 12, 'season=' . self::SEASON);
        $this->put('StaffAndFacilities', ['errorMsg' => 'Supporters only', 'data' => []], 5);

        $this->projector->projectRace(self::USER, self::SEASON, 5);

        $this->assertSame(0, $this->rows('user_weekend_state'));
    }

    public function testManagerWithoutDriverOrTdProjectsNullBlocks(): void
    {
        $this->put('office', $this->office(5, ['driId' => '', 'driName' => '', 'tdId' => '']), 5);
        $this->put('DriProfile', ['driName' => 'Unknown driver', 'isSupporter' => 1], 5, 'id=0');
        $this->put('TDProfile', ['tdName' => 'Unknown technical director', 'ownTD' => 0], 5);
        $this->put('StaffAndFacilities', $this->staff(), 5);

        $this->projector->projectRace(self::USER, self::SEASON, 5);

        $row = $this->row('SELECT * FROM user_weekend_state');
        $this->assertNotNull($row);
        $this->assertNull($row['driver_json']);
        $this->assertNull($row['td_json']);
        $this->assertNotNull($row['staff_json']);
    }

    public function testPhantomTdIsNeverStoredEvenWhenOfficeNamesOne(): void
    {
        $this->put('office', $this->office(5), 5);
        $this->put('TDProfile', ['tdName' => 'Unknown technical director', 'ownTD' => 0], 5);
        $this->put('TDProfile', ['tdName' => 'Tom Director', 'ownTD' => 1], 6);

        $this->projector->projectRace(self::USER, self::SEASON, 5);

        $row = $this->row('SELECT td_json FROM user_weekend_state');
        $this->assertNotNull($row);
        $this->assertNull($row['td_json']);
    }

    public function testStandingsOwnPositionFoundByIdm(): void
    {
        $this->put('office', $this->office(5), 5);
        $this->put('Menu', ['IDM' => 4242, 'group' => 'Amateur - 4'], 5);
        $this->put('Standings', [
            'group' => 'Amateur - 4',
            'managers' => [
                ['name' => 'Other', 'pos' => '1', 'IDM' => 1111, 'pts' => '120', 'manArrow' => 'up',
                 'manBgColor' => '#fff', 'goesRookie' => 0, 'results' => [1, 2, 3]],
                ['name' => 'Me', 'pos' => '7', 'IDM' => 4242, 'pts' => '66', 'manArrow' => 'down',
                 'manBgColor' => '#eee', 'goesRookie' => 1, 'results' => [4, 5]],
            ],
            'dataGraph' => ['huge' => 'chart'],
        ], 5);

        $this->projector->projectRace(self::USER, self::SEASON, 5);

        $row = $this->row('SELECT * FROM user_standings');
        $this->assertNotNull($row);
        $this->assertSame(7, (int) $row['own_pos']);
        $this->assertSame(66, (int) $row['own_pts']);
        $this->assertSame('Amateur - 4', $row['group_label']);
        $managers = json_decode((string) $row['managers_json'], true);
        $this->assertIsArray($managers);
        $this->assertCount(2, $managers);
        $this->assertSame('down', $managers[1]['manArrow']);
        $this->assertSame(1, $managers[1]['goesRookie']);
        $this->assertArrayNotHasKey('results', $managers[1]);
    }

    public function testStandingsWithoutKnownIdmKeepOwnPositionNull(): void
    {
        $this->put('Standings', ['group' => 'Pro - 1', 'managers' => [
            ['name' => 'X', 'pos' => '1', 'IDM' => 1, 'pts' => '10'],
        ]], 5);

        $this->projector->projectRace(self::USER, self::SEASON, 5);

        $row = $this->row('SELECT * FROM user_standings');
        $this->assertNotNull($row);
        $this->assertNull($row['own_pos']);
        $this->assertSame('Pro - 1', $row['group_label']);
    }

    public function testLedgerFromAnalysisAndEconomyHistoryDedupsOnReprojection(): void
    {
        $this->put('RaceAnalysis', $this->analysis(5), 5);
        $this->put('EconomyHistory', ['data' => [
            ['type' => 'Sponsor', 'desc' => 'Sponsor payment', 'datetime' => '2026-10-20 21:00:00',
             'balanceBefore' => 100, 'amount' => 50, 'balanceAfter' => 150],
            ['type' => 'Fee', 'desc' => 'Facility fee', 'datetime' => '2026-10-20 21:00:01',
             'balanceBefore' => 150, 'amount' => -20, 'balanceAfter' => 130],
        ]], 5, 'Page=1&show=table');

        $this->projector->projectRace(self::USER, self::SEASON, 5);
        $first = $this->rows('user_economy_ledger');
        $this->projector->projectRace(self::USER, self::SEASON, 5);

        $this->assertSame(4, $first);
        $this->assertSame(4, $this->rows('user_economy_ledger'));
        $race = $this->row("SELECT * FROM user_economy_ledger WHERE source = 'race' AND amount = -100000");
        $this->assertNotNull($race);
        $this->assertSame(self::SEASON, (int) $race['season']);
        $this->assertSame(5, (int) $race['race']);
        $this->assertSame(
            sha1('race|107|5|1|Driver salary|-100000'),
            $race['fingerprint'],
        );
        $economy = $this->row("SELECT * FROM user_economy_ledger WHERE description = 'Facility fee'");
        $this->assertNotNull($economy);
        $this->assertSame('economy', $economy['source']);
        $this->assertSame(130, (int) $economy['balance_after']);
        $this->assertSame('2026-10-20 21:00:01', $economy['occurred_at']);
        $this->assertSame(sha1('economy|2026-10-20 21:00:01|Facility fee|-20|130'), $economy['fingerprint']);
    }

    /** @return array<string, mixed> */
    private function economyPage(): array
    {
        return ['data' => [
            ['type' => 'header', 'desc' => 'Season 107, Race 5 - Hockenheim (Germany)'],
            ['type' => 'row', 'desc' => 'Sponsor money', 'datetime' => 'Oct 20th 2026, 21:00:01',
             'balanceBefore' => 100, 'amount' => 50, 'balanceAfter' => 150],
            ['type' => 'row', 'desc' => 'Facility costs', 'datetime' => 'Oct 20th 2026, 21:00:01',
             'balanceBefore' => 150, 'amount' => -20, 'balanceAfter' => 130],
            ['type' => 'header', 'desc' => 'Season 107, Race 4 - Monza (Italy)'],
            ['type' => 'row', 'desc' => 'Sponsor money', 'datetime' => 'Oct 13th 2026, 21:00:01',
             'balanceBefore' => 50, 'amount' => 50, 'balanceAfter' => 100],
        ]];
    }

    public function testEconomyRowsAreFiledUnderTheNearestHeaderAbove(): void
    {
        $this->put('EconomyHistory', $this->economyPage(), 6, 'Page=1&show=table');

        $this->projector->projectRace(self::USER, self::SEASON, 6);

        $stmt = $this->db->query(
            'SELECT season, race, description, balance_after FROM user_economy_ledger ORDER BY race, id'
        );
        $this->assertNotFalse($stmt);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(3, $rows);
        $this->assertSame(107, (int) $rows[0]['season']);
        $this->assertSame(4, (int) $rows[0]['race']);
        $this->assertSame(100, (int) $rows[0]['balance_after']);
        $this->assertSame(5, (int) $rows[1]['race']);
        $this->assertSame(5, (int) $rows[2]['race']);
        $this->assertSame(130, (int) $rows[2]['balance_after']);
    }

    public function testHeaderEntriesAreNeverStoredAsLedgerRows(): void
    {
        $this->put('EconomyHistory', $this->economyPage(), 6, 'Page=1&show=table');

        $this->projector->projectRace(self::USER, self::SEASON, 6);

        $this->assertSame(0, $this->rows("user_economy_ledger WHERE description LIKE 'Season%'"));
    }

    public function testARowBeforeAnyHeaderOrUnderAnUnparsableHeaderFallsBackToTheStamp(): void
    {
        $this->put('EconomyHistory', ['data' => [
            ['type' => 'row', 'desc' => 'Early', 'datetime' => 'a', 'amount' => 1],
            ['type' => 'header', 'desc' => 'Pre-season testing'],
            ['type' => 'row', 'desc' => 'Odd', 'datetime' => 'b', 'amount' => 2],
        ]], 6, 'Page=1&show=table');

        $this->projector->projectRace(self::USER, self::SEASON, 6);

        $stmt = $this->db->query('SELECT season, race FROM user_economy_ledger');
        $this->assertNotFalse($stmt);
        $all = $stmt->fetchAll(PDO::FETCH_NUM);
        $this->assertSame([[107, 6], [107, 6]], array_map(static fn (array $r): array => array_map('intval', $r), $all));
    }

    public function testReprojectionMovesAPreviouslyMisStampedRow(): void
    {
        $this->put('EconomyHistory', ['data' => [
            ['type' => 'row', 'desc' => 'Sponsor money', 'datetime' => 'x', 'amount' => 50, 'balanceAfter' => 150],
        ]], 6, 'Page=1&show=table');
        $this->projector->projectRace(self::USER, self::SEASON, 6);
        $this->assertSame(6, (int) $this->db->query('SELECT race FROM user_economy_ledger')->fetchColumn());

        $this->put('EconomyHistory', ['data' => [
            ['type' => 'header', 'desc' => 'Season 107, Race 5 - Hockenheim (Germany)'],
            ['type' => 'row', 'desc' => 'Sponsor money', 'datetime' => 'x', 'amount' => 50, 'balanceAfter' => 150],
        ]], 6, 'Page=1&show=table');
        $this->projector->projectRace(self::USER, self::SEASON, 6);

        $this->assertSame(1, $this->rows('user_economy_ledger'));
        $this->assertSame(5, (int) $this->db->query('SELECT race FROM user_economy_ledger')->fetchColumn());

        $this->put('EconomyHistory', ['data' => [
            ['type' => 'row', 'desc' => 'Sponsor money', 'datetime' => 'x', 'amount' => 50, 'balanceAfter' => 150],
        ]], 6, 'Page=1&show=table');
        $this->projector->projectRace(self::USER, self::SEASON, 6);
        $this->assertSame(5, (int) $this->db->query('SELECT race FROM user_economy_ledger')->fetchColumn());
    }

    public function testEconomyRowWithoutDatetimeIsStoredWithNullOccurredAt(): void
    {
        $this->put('EconomyHistory', ['data' => [
            ['type' => 'row', 'desc' => 'Bonus', 'amount' => 7, 'balanceAfter' => 9],
        ]], 6, 'Page=1&show=table');

        $this->projector->projectRace(self::USER, self::SEASON, 6);

        $row = $this->row('SELECT occurred_at, balance_after, fingerprint FROM user_economy_ledger');
        $this->assertNotNull($row);
        $this->assertNull($row['occurred_at']);
        $this->assertSame(9, (int) $row['balance_after']);
        $this->assertSame(sha1('economy||Bonus|7|9'), $row['fingerprint']);
    }

    public function testLedgerIgnoresMalformedEconomyRows(): void
    {
        $this->put('EconomyHistory', ['data' => [
            'garbage', ['desc' => 'No amount'], ['desc' => 'Ok', 'datetime' => 'x', 'amount' => 1],
        ]], 5, 'Page=1&show=table');

        $this->projector->projectRace(self::USER, self::SEASON, 5);

        $this->assertSame(1, $this->rows('user_economy_ledger'));
    }

    public function testHistoryUsesThatRacesOwnTdStaffAndDriverProfile(): void
    {
        $this->seedSnapshot(5);
        $this->put('RaceAnalysis', $this->analysis(5), 5);

        $this->projector->projectRace(self::USER, self::SEASON, 5);

        $row = $this->row('SELECT * FROM user_race_history');
        $this->assertNotNull($row);
        $this->assertSame(5, (int) $row['race']);
        $this->assertSame(27, $this->json($row['driver_json'])['age']);
        $this->assertSame('Tom Director', $this->json($row['td_json'])['name']);
        $this->assertNotSame([], $this->json($row['staff_json']));
    }

    public function testHistoryReplacesAnEarlierRowWithCorrectedFacilities(): void
    {
        $this->put('RaceAnalysis', $this->analysis(5), 5);
        $this->put('StaffAndFacilities', $this->staff(1), 5);
        $this->projector->projectRace(self::USER, self::SEASON, 5);
        $before = $this->row('SELECT * FROM user_race_history');
        $this->assertNotNull($before);

        $this->put('StaffAndFacilities', $this->staff(8), 5);
        $this->db->exec("UPDATE user_api_archive SET fetched_at = '2999-01-01 00:00:00' WHERE endpoint = 'StaffAndFacilities'");
        $this->projector->projectRace(self::USER, self::SEASON, 5);

        $after = $this->row('SELECT * FROM user_race_history');
        $this->assertNotNull($after);
        $this->assertSame($before['id'], $after['id']);
        $this->assertSame(1, $this->rows('user_race_history'));
        $this->assertNotSame($before['staff_json'], $after['staff_json']);
        $facilities = $this->json($after['staff_json'])['facilities'] ?? [];
        $labs = array_values(array_filter($facilities, static fn (array $f): bool => $f['label'] === 'Lab'));
        $this->assertSame(13, $labs[0]['value']);
    }

    public function testDriverProfileOnlyFeedsHistoryWhenTheCurrentDriverRanThatRace(): void
    {
        $this->put('office', $this->office(5), 5);
        $this->put('DriProfile', $this->driProfile(), 5, 'id=555');
        $this->put('RaceAnalysis', $this->analysis(5, 999), 5);

        $this->projector->projectRace(self::USER, self::SEASON, 5);

        $row = $this->row('SELECT driver_json FROM user_race_history');
        $this->assertNotNull($row);
        $this->assertNull($this->json($row['driver_json'])['age']);
    }

    public function testBackfilledAnalysisProjectsItsOwnRaceWhenNoLatestRowExists(): void
    {
        $this->put('RaceAnalysis', $this->analysis(3), 3, 'SR=107,3');

        $this->projector->projectRace(self::USER, self::SEASON, 3);

        $this->assertSame(1, $this->rows('user_race_history'));
        $this->assertSame(2, $this->rows('user_economy_ledger'));
    }

    public function testProjectionIsIdempotent(): void
    {
        $this->seedSnapshot(5);
        $this->put('RaceAnalysis', $this->analysis(5), 5);
        $this->put('Menu', ['IDM' => 1], 5);
        $this->put('Standings', ['group' => 'G', 'managers' => [['IDM' => 1, 'pos' => '2', 'pts' => '3']]], 5);

        $dump = function (): array {
            $out = [];
            foreach (['user_weekend_state', 'user_standings', 'user_economy_ledger', 'user_race_history'] as $t) {
                $stmt = $this->db->query("SELECT * FROM {$t} ORDER BY 1, 2, 3");
                $this->assertNotFalse($stmt);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    unset($r['projected_at']);
                    $out[$t][] = $r;
                }
            }

            return $out;
        };

        $this->projector->projectRace(self::USER, self::SEASON, 5);
        $first = $dump();
        $this->projector->projectRace(self::USER, self::SEASON, 5);

        $this->assertSame($first, $dump());
        $this->assertSame(1, $this->rows('user_projection_state'));
    }

    public function testProjectionRecordsStateForThatRaceOnly(): void
    {
        $this->seedSnapshot(5);
        $this->projector->projectRace(self::USER, self::SEASON, 5);

        $state = $this->row('SELECT * FROM user_projection_state');
        $this->assertNotNull($state);
        $this->assertSame([1, 107, 5], [(int) $state['user_id'], (int) $state['season'], (int) $state['race']]);
        $this->assertSame(ArchiveProjector::PROJECTOR_VERSION, (int) $state['version']);
    }

    public function testOneProjectionFailingStillWritesTheOthersAndMarksTheRace(): void
    {
        $this->seedSnapshot(5);
        $this->put('Standings', ['group' => 'G', 'managers' => []], 5);
        $broken = $this->createStub(StandingsRepository::class);
        $broken->method('save')->willThrowException(new \RuntimeException('secret-payload-detail'));
        $log = tempnam(sys_get_temp_dir(), 'projlog');
        $this->assertNotFalse($log);
        $previous = ini_set('error_log', $log);

        try {
            $this->makeProjector($broken)->projectRace(self::USER, self::SEASON, 5);
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $logged = (string) file_get_contents($log);
        unlink($log);
        $this->assertSame(1, $this->rows('user_weekend_state'));
        $this->assertSame(1, $this->rows('user_projection_state'));
        $this->assertStringContainsString('[Projector]', $logged);
        $this->assertStringContainsString(\RuntimeException::class, $logged);
        $this->assertStringNotContainsString('secret-payload-detail', $logged);
    }

    public function testDeletingTheUserCascadesProjectionState(): void
    {
        $this->seedSnapshot(5);
        $this->projector->projectRace(self::USER, self::SEASON, 5);

        $this->db->exec('DELETE FROM users WHERE id = 1');

        $this->assertSame(0, $this->rows('user_projection_state'));
        $this->assertSame(0, $this->rows('user_weekend_state'));
    }

    public function testPendingRacesListsUnprojectedRacesOldestFirstAndHonoursTheLimit(): void
    {
        $this->put('office', $this->office(7), 7);
        $this->put('office', $this->office(5), 5);
        $this->put('Calendar', ['x' => 1], null, '', self::USER, null, 'nostamp');

        $this->assertSame(
            [['season' => 107, 'race' => 5], ['season' => 107, 'race' => 7]],
            $this->projector->pendingRaces(self::USER, 10),
        );
        $this->assertSame([['season' => 107, 'race' => 5]], $this->projector->pendingRaces(self::USER, 1));
        $this->assertSame([], $this->projector->pendingRaces(2, 10));
    }

    public function testAProjectedRaceIsNotPendingUntilALaterRowArrives(): void
    {
        $this->seedSnapshot(5);
        $this->projector->projectRace(self::USER, self::SEASON, 5);
        $this->db->exec("UPDATE user_projection_state SET projected_at = '2030-01-01 00:00:00'");
        $this->db->exec("UPDATE user_api_archive SET fetched_at = '2029-01-01 00:00:00'");

        $this->assertSame([], $this->projector->pendingRaces(self::USER, 10));

        $this->db->exec("UPDATE user_api_archive SET fetched_at = '2031-01-01 00:00:00' WHERE endpoint = 'UpdateCar'");

        $this->assertSame([['season' => 107, 'race' => 5]], $this->projector->pendingRaces(self::USER, 10));
    }

    public function testANewlyArchivedBackfillAnalysisMakesItsRacePending(): void
    {
        $this->seedSnapshot(5);
        $this->projector->projectRace(self::USER, self::SEASON, 5);
        $this->db->exec("UPDATE user_projection_state SET projected_at = '2030-01-01 00:00:00'");
        $this->db->exec("UPDATE user_api_archive SET fetched_at = '2029-01-01 00:00:00'");

        $this->put('RaceAnalysis', $this->analysis(5), 5, 'SR=107,5');
        $this->db->exec("UPDATE user_api_archive SET fetched_at = '2031-01-01 00:00:00' WHERE params = 'SR=107,5'");

        $this->assertSame([['season' => 107, 'race' => 5]], $this->projector->pendingRaces(self::USER, 10));
    }

    public function testABackfillParamNamingAnUnstampedRaceMakesThatRacePending(): void
    {
        $this->put('RaceAnalysis', $this->analysis(3), 5, 'SR=107,3');
        $this->db->exec("UPDATE user_api_archive SET race = NULL, season = NULL");

        $this->assertSame([['season' => 107, 'race' => 3]], $this->projector->pendingRaces(self::USER, 10));
    }

    public function testASeasonHistoryRowMakesEveryProjectedRaceOfThatSeasonPending(): void
    {
        $this->seedSnapshot(5);
        $this->projector->projectRace(self::USER, self::SEASON, 5);
        $this->db->exec("UPDATE user_projection_state SET projected_at = '2030-01-01 00:00:00'");
        $this->db->exec("UPDATE user_api_archive SET fetched_at = '2029-01-01 00:00:00'");

        $this->put('SFHistory', ['data' => []], null, 'season=107', self::USER, null, 'w1');
        $this->db->exec("UPDATE user_api_archive SET fetched_at = '2031-01-01 00:00:00' WHERE endpoint = 'SFHistory'");
        $this->put('SFHistory', ['data' => []], null, 'season=106', self::USER, null, 'w2');
        $this->db->exec("UPDATE user_api_archive SET fetched_at = '2032-01-01 00:00:00' WHERE params = 'season=106'");

        $this->assertSame([['season' => 107, 'race' => 5]], $this->projector->pendingRaces(self::USER, 10));
    }

    public function testBumpingTheProjectorVersionMakesProjectedRacesPendingAgain(): void
    {
        $this->seedSnapshot(5);
        $this->projector->projectRace(self::USER, self::SEASON, 5);
        $this->db->exec("UPDATE user_projection_state SET projected_at = '2030-01-01 00:00:00'");
        $this->db->exec("UPDATE user_api_archive SET fetched_at = '2029-01-01 00:00:00'");
        $this->assertSame([], $this->projector->pendingRaces(self::USER, 10));

        $this->db->exec('UPDATE user_projection_state SET version = ' . (ArchiveProjector::PROJECTOR_VERSION - 1));

        $this->assertSame([['season' => 107, 'race' => 5]], $this->projector->pendingRaces(self::USER, 10));
    }

    public function testTwoMovementsSharingTimestampDescriptionAndAmountStayDistinct(): void
    {
        $page = ['data' => [
            ['type' => 'header', 'desc' => 'Season 107, Race 5 - Hockenheim (Germany)'],
            ['type' => 'row', 'desc' => 'Bonus', 'datetime' => 't', 'amount' => 10, 'balanceAfter' => 110],
            ['type' => 'row', 'desc' => 'Bonus', 'datetime' => 't', 'amount' => 10, 'balanceAfter' => 120],
        ]];
        $this->put('EconomyHistory', $page, 6, 'Page=1&show=table');

        $this->projector->projectRace(self::USER, self::SEASON, 6);
        $this->projector->projectRace(self::USER, self::SEASON, 6);

        $this->assertSame(2, $this->rows('user_economy_ledger'));
    }

    public function testOfficeArchivedOnlyAsTheSyncCopyStillFeedsTheWeekend(): void
    {
        $this->put('office', $this->office(5), 5, 'sync');

        $this->projector->projectRace(self::USER, self::SEASON, 5);

        $row = $this->row('SELECT * FROM user_weekend_state');
        $this->assertNotNull($row);
        $this->assertSame(12500000, (int) $row['cash']);
        $this->assertSame('snapshot', $row['source']);
    }

    public function testMalformedBackfillParamsCreateNoCandidateRaces(): void
    {
        foreach (['SR=abc', 'SR=x,y', 'SR=107,', 'SR=,5', 'SR=107,5x'] as $i => $params) {
            $this->put('RaceAnalysis', ['laps' => []], null, $params, self::USER, null, "w{$i}");
        }

        $this->assertSame([], $this->projector->pendingRaces(self::USER, 10));
    }

    public function testHistoryForARaceIgnoresCompetingRowsStampedForTheNextRace(): void
    {
        $this->put('office', $this->office(5), 5);
        $this->put('DriProfile', $this->driProfile(), 5, 'id=555');
        $this->put('TDProfile', $this->tdProfile(), 5);
        $this->put('StaffAndFacilities', $this->staff(1), 5);
        $this->put('RaceAnalysis', $this->analysis(5), 5);
        $this->put('office', $this->office(6), 6);
        $this->put('DriProfile', ['age' => 99] + $this->driProfile(), 6, 'id=555');
        $this->put('TDProfile', ['tdName' => 'Later Director'] + $this->tdProfile(), 6);
        $this->put('StaffAndFacilities', $this->staff(8), 6);
        $this->db->exec("UPDATE user_api_archive SET fetched_at = '2999-01-01 00:00:00' WHERE race = 6");

        $this->projector->projectRace(self::USER, self::SEASON, 5);

        $row = $this->row('SELECT driver_json, td_json, staff_json FROM user_race_history WHERE race = 5');
        $this->assertNotNull($row);
        $this->assertSame(27, $this->json($row['driver_json'])['age']);
        $this->assertSame('Tom Director', $this->json($row['td_json'])['name']);
        $facilities = $this->json($row['staff_json'])['facilities'];
        $lab = array_values(array_filter($facilities, static fn (array $f): bool => $f['label'] === 'Lab'));
        $this->assertSame(6, $lab[0]['value']);
    }

    public function testAnUnavailableMenuFallbackIsNotUsedToFindTheOwnPosition(): void
    {
        $this->put('Menu', ['errorMsg' => 'Supporters only', 'data' => [], 'IDM' => 1], 4);
        $this->put('Standings', ['group' => 'G', 'managers' => [['IDM' => 1, 'pos' => '2', 'pts' => '3']]], 5);

        $this->projector->projectRace(self::USER, self::SEASON, 5);

        $row = $this->row('SELECT own_pos FROM user_standings');
        $this->assertNotNull($row);
        $this->assertNull($row['own_pos']);
    }
}
