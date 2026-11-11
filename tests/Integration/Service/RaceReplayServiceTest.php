<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Archive\AnalysisReader;
use App\Archive\ArchiveSanitizer;
use App\Database\DatabaseSeeder;
use App\Repository\ApiArchiveRepository;
use App\Repository\RaceHistoryRepository;
use App\Repository\RaceTelemetryRepository;
use App\Security\ApiTokenCrypto;
use App\Service\RaceReplayService;
use App\Service\RaceTelemetryService;
use App\Telemetry\RaceReplayMapper;
use App\Telemetry\RaceTelemetryMapper;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RaceReplayService::class)]
final class RaceReplayServiceTest extends TestCase
{
    private PDO $db;
    private RaceReplayService $service;

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
            new ApiTokenCrypto('replay-service-test-secret'),
        ))->migrate();
        $this->db->exec("INSERT INTO users (id, username, email_encrypted, email_hash) VALUES (1, 'u1', 'e1', 'h1')");

        $archive = new ApiArchiveRepository($this->db, new ArchiveSanitizer());
        $archive->put(1, 'scope', 'RaceAnalysis', '', '', 107, 13, $this->analysis());
        (new RaceTelemetryService(new RaceTelemetryRepository($this->db), new RaceTelemetryMapper()))
            ->ingest($this->analysis());
        $this->db->exec(
            "INSERT INTO user_race_history (user_id, season, race, group_label, level)
             VALUES (1, 107, 13, 'Amateur - 4', 'Amateur')"
        );

        $this->service = new RaceReplayService(
            new RaceHistoryRepository($this->db),
            new RaceTelemetryRepository($this->db),
            new RaceTelemetryMapper(),
            new RaceReplayMapper(),
            new AnalysisReader($archive),
        );
    }

    /** @return array<string, mixed> */
    private function analysis(): array
    {
        return [
            'selSeasonNb' => '107', 'selRaceNb' => '13', 'group' => 'Amateur - 4',
            'trackName' => 'Hockenheim', 'trackId' => '17',
            'driver' => ['id' => 77, 'OA' => '140', 'con' => '100'],
            'laps' => [
                ['idx' => 0, 'pos' => 3, 'weather' => 'Sunny'],
                ['idx' => 1, 'pos' => 2, 'weather' => 'Sunny'],
                ['idx' => 2, 'pos' => 2, 'weather' => 'Sunny'],
                ['idx' => 3, 'pos' => 2, 'weather' => 'Sunny'],
            ],
        ];
    }

    /**
     * @param array<int, array{0: int, 1: bool, 2: int}> $ours lap => [race time, mistake, mistake ms]
     * @return array<string, mixed>
     */
    private function replay(array $ours = [1 => [90500, true, 1500], 2 => [180500, false, 0], 3 => [270500, false, 0]]): array
    {
        $data = [];
        foreach ($ours as $lap => [$time, $mistake, $ms]) {
            $row = static fn (int $t, int $pos, bool $m = false, int $mms = 0): array => [
                0 => 'M', 1 => $t, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0,
                8 => $m ? 1 : 0, 9 => 0, 10 => $pos, 11 => $mms,
            ];
            $data[] = ['lapNb' => $lap, 'managers' => [
                ['900' => $row($lap * 90000, 1)],
                ['111' => $row($time, 2, $mistake, $ms)],
                ['700' => $row($lap * 90000 + 4000, 3)],
            ]];
        }

        return ['hasAccess' => 1, 'replayData' => $data];
    }

    /** @return array<string, mixed> */
    private function historyRow(): array
    {
        return $this->db->query('SELECT * FROM user_race_history')->fetch(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed> */
    private function telemetryRow(): array
    {
        return $this->db->query('SELECT * FROM race_telemetry')->fetch(PDO::FETCH_ASSOC);
    }

    public function testNamesTheGroupTheRaceWasRunIn(): void
    {
        $this->assertSame('Amateur - 4', $this->service->groupFor(1, 107, 13));
        $this->assertNull($this->service->groupFor(1, 107, 99));
    }

    public function testFilesTheRaceTimeFactsOnBothTheManagersHistoryAndTheSharedCorpus(): void
    {
        $this->assertTrue($this->service->ingest(1, 107, 13, $this->replay()));

        foreach ([$this->historyRow(), $this->telemetryRow()] as $row) {
            $this->assertSame(3, (int) $row['replay_laps']);
            $this->assertSame(1, (int) $row['race_mistakes']);
            $this->assertSame(1500, (int) $row['race_mistake_ms']);
            $this->assertSame(2.0, (float) $row['avg_race_pos']);
            $this->assertSame(3, (int) $row['traffic_ahead_laps']);
        }
    }

    public function testAnAnswerWithoutAccessMarksTheRaceCheckedAndStoresNothing(): void
    {
        $this->assertFalse($this->service->ingest(1, 107, 13, ['hasAccess' => 0, 'replayData' => []]));

        $this->assertSame(0, (int) $this->historyRow()['replay_laps']);
        $this->assertNull($this->historyRow()['race_mistakes']);
        $this->assertSame(0, (int) $this->telemetryRow()['replay_laps']);
    }

    public function testAReplayThatDoesNotMatchTheAnalysisMarksTheRaceChecked(): void
    {
        $other = $this->replay();
        foreach ($other['replayData'] as &$lap) {
            $lap['managers'] = array_reverse($lap['managers']);
            foreach ($lap['managers'] as &$wrapper) {
                foreach ($wrapper as &$row) {
                    $row[10] = 9;
                }
            }
        }
        unset($lap, $wrapper, $row);

        $this->assertFalse($this->service->ingest(1, 107, 13, $other));
        $this->assertSame(0, (int) $this->historyRow()['replay_laps']);
    }

    public function testARaceWithNoStoredAnalysisIsMarkedCheckedRatherThanAskedForForever(): void
    {
        $this->db->exec("INSERT INTO user_race_history (user_id, season, race, group_label, level) VALUES (1, 107, 14, 'Amateur - 4', 'Amateur')");

        $this->assertFalse($this->service->ingest(1, 107, 14, $this->replay()));

        $count = $this->db->query('SELECT COUNT(*) FROM user_race_history WHERE replay_laps = 0')->fetchColumn();
        $this->assertSame(1, (int) $count);
    }

    public function testStoresNoManagerNameOrId(): void
    {
        $this->service->ingest(1, 107, 13, $this->replay());

        $dump = json_encode([$this->historyRow(), $this->telemetryRow()], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('"111"', $dump);
        $this->assertStringNotContainsString('900', $dump);
    }
}
