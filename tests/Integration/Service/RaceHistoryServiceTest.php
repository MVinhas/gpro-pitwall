<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Database\DatabaseSeeder;
use App\Repository\RaceHistoryRepository;
use App\Repository\TrackRepository;
use App\Security\ApiTokenCrypto;
use App\Service\RaceHistoryService;
use App\Telemetry\RaceHistoryMapper;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RaceHistoryService::class)]
final class RaceHistoryServiceTest extends TestCase
{
    private PDO $db;
    private RaceHistoryService $service;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        (new DatabaseSeeder(
            $this->db,
            ['Concentration' => 'concentration'],
            ['Rookie', 'Amateur'],
            [],
            new ApiTokenCrypto('history-service-secret'),
        ))->migrate();

        // Pin the one track this needs. An upsert on the name, because the
        // seeder may or may not have loaded data/tracks.csv (it is gitignored,
        // so it is present locally and absent in CI).
        $this->db->exec("
            INSERT INTO tracks (name, lap_length) VALUES ('Hockenheim', 4.574)
            ON CONFLICT(name) DO UPDATE SET lap_length = excluded.lap_length
        ");

        $this->service = new RaceHistoryService(
            new RaceHistoryRepository($this->db),
            new RaceHistoryMapper(),
            new TrackRepository($this->db),
        );
    }

    /** @return array<string, mixed> */
    private function payload(string $trackName = 'Hockenheim (Germany)'): array
    {
        $laps = [['idx' => 0, 'pos' => 8, 'tyres' => 'Hard', 'weather' => 'Sunny', 'temp' => 44, 'hum' => 35]];
        for ($i = 1; $i <= 10; $i++) {
            $laps[] = [
                'idx' => $i, 'pos' => 3, 'tyres' => 'Hard', 'weather' => 'Sunny',
                'temp' => 44, 'hum' => 35, 'lapTime' => '1:14.100',
            ];
        }

        return [
            'selSeasonNb' => '107', 'selRaceNb' => '13',
            'group' => 'Amateur - 4', 'trackName' => $trackName, 'trackId' => '17',
            'startFuel' => 50, 'finishFuel' => 5, 'finishTyres' => 40,
            'driver' => ['id' => 1, 'OA' => '140'],
            'laps' => $laps,
            'pits' => [],
            'problems' => [],
            'setupsUsed' => [
                ['session' => 'Race', 'setFWing' => '339', 'setRWing' => '777', 'setEng' => '741',
                 'setBra' => '377', 'setGear' => '556', 'setSusp' => '250', 'setTyres' => 'Hard'],
            ],
        ];
    }

    public function testArchivesARaceUnderTheCallersUserId(): void
    {
        $this->assertTrue($this->service->archive(7, $this->payload()));

        $stmt = $this->db->query('SELECT user_id, season, race FROM user_race_history');
        $row = $stmt === false ? false : $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertSame(7, (int) $row['user_id']);
        $this->assertSame(107, (int) $row['season']);
    }

    public function testResolvesTheLapLengthDespiteGprosCountrySuffix(): void
    {
        // RaceAnalysis says "Hockenheim (Germany)"; the tracks table says
        // "Hockenheim". Without stripping the suffix every per-km rate is null.
        $this->service->archive(1, $this->payload('Hockenheim (Germany)'));

        $stmt = $this->db->query('SELECT fuel_per_km FROM user_race_history');
        $value = $stmt === false ? null : $stmt->fetchColumn();

        $this->assertNotNull($value);
        $this->assertGreaterThan(0, (float) $value);
    }

    public function testAnUnknownTrackLeavesTheRatesNullRatherThanWrong(): void
    {
        $this->service->archive(1, $this->payload('Nowhere Special (Atlantis)'));

        $stmt = $this->db->query('SELECT fuel_per_km, tyre_per_km FROM user_race_history');
        $row = $stmt === false ? false : $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertNull($row['fuel_per_km']);
        $this->assertNull($row['tyre_per_km']);
    }

    public function testNormaliseStripsOnlyATrailingParenthetical(): void
    {
        $this->assertSame('Hockenheim', RaceHistoryService::normaliseTrackName('Hockenheim (Germany)'));
        $this->assertSame('Brno', RaceHistoryService::normaliseTrackName('Brno (Czech Republic)'));
        $this->assertSame('Monza', RaceHistoryService::normaliseTrackName('Monza'));
        // A name that legitimately contains brackets mid-string keeps them.
        $this->assertSame('A1-Ring (old) layout', RaceHistoryService::normaliseTrackName('A1-Ring (old) layout'));
    }

    public function testAReSyncOfTheSameRaceIsANoOp(): void
    {
        $this->assertTrue($this->service->archive(1, $this->payload()));
        $this->assertFalse($this->service->archive(1, $this->payload()));
    }

    public function testAnUnusablePayloadIsSwallowedRatherThanThrown(): void
    {
        // Archiving is observational: it must never break the sync that carries it.
        $this->assertFalse($this->service->archive(1, []));
        $this->assertFalse($this->service->archive(1, ['selSeasonNb' => '107']));
    }
}
