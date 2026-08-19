<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Database\DatabaseSeeder;
use App\Repository\RaceTelemetryRepository;
use App\Security\ApiTokenCrypto;
use App\Service\RaceIntelligenceService;
use App\Service\RaceTelemetryService;
use App\Telemetry\RaceTelemetryMapper;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RaceIntelligenceService::class)]
#[CoversClass(RaceTelemetryService::class)]
final class RaceIntelligenceServiceTest extends TestCase
{
    private RaceTelemetryRepository $repo;
    private RaceTelemetryService $ingest;
    private RaceIntelligenceService $intel;

    protected function setUp(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        (new DatabaseSeeder(
            $db,
            ['Concentration' => 'concentration'],
            ['Rookie', 'Amateur', 'Pro', 'Master', 'Elite'],
            [],
            new ApiTokenCrypto('intel-test-secret'),
        ))->migrate();

        $this->repo = new RaceTelemetryRepository($db);
        $this->ingest = new RaceTelemetryService($this->repo, new RaceTelemetryMapper());
        $this->intel = new RaceIntelligenceService($this->repo);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function analysis(array $overrides = []): array
    {
        return array_merge([
            'selSeasonNb' => '99',
            'selRaceNb'   => '1',
            'group'       => 'Rookie - 31',
            'trackId'     => '58',
            'q1Time'      => '1:59.502s',
            'q2Time'      => '2:02.455s',
            'driver' => [
                'id' => 30357, 'OA' => '80', 'con' => '90', 'tal' => '150',
                'agr' => '20', 'exp' => '30', 'tei' => '60', 'sta' => '40',
                'cha' => '80', 'mot' => '50', 'rep' => '10', 'wei' => '62',
            ],
            'setupsUsed' => [
                ['session' => 'Race', 'setTyres' => 'Medium'],
            ],
            'laps' => [
                ['idx' => 0, 'pos' => 8, 'weather' => 'Sunny', 'temp' => 20, 'hum' => 10],
                ['idx' => 1, 'pos' => 5, 'weather' => 'Sunny', 'temp' => 20, 'hum' => 10],
            ],
            'pits' => [],
            'problems' => [],
        ], $overrides);
    }

    public function testIngestStoresARaceAndIsIdempotent(): void
    {
        $this->assertTrue($this->ingest->ingest($this->analysis()));
        $this->assertFalse($this->ingest->ingest($this->analysis()));

        $this->assertSame(1, $this->repo->total());
    }

    public function testIngestIgnoresEmptyAndUnusablePayloads(): void
    {
        $this->assertFalse($this->ingest->ingest([]));
        $this->assertFalse($this->ingest->ingest(['loadingDataState' => 1, 'unlocked' => '0']));

        $this->assertSame(0, $this->repo->total());
    }

    public function testReportOnEmptyCorpusIsSafeToRender(): void
    {
        $report = $this->intel->report();

        $this->assertFalse($report['has_data']);
        $this->assertSame(0, $report['total']);
        $this->assertSame([], $report['levels']);
        $this->assertSame([], $report['attributes']);
    }

    public function testAdvantageFlipsTheSignOfPositionCorrelation(): void
    {
        // Higher talent paired with a better (numerically lower) finish. The
        // raw correlation against final_pos is negative; the report must
        // present that as a POSITIVE advantage.
        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9, 10] as $i => $pos) {
            $this->ingest->ingest($this->analysis([
                'selRaceNb' => (string) ($i + 1),
                'q1Time'    => '1:5' . $i . '.000s',
                'driver'    => [
                    'id' => 30357, 'OA' => '80', 'con' => '90',
                    'tal' => (string) (200 - $pos * 10),
                    'agr' => '20', 'exp' => '30', 'tei' => '60', 'sta' => '40',
                    'cha' => '80', 'mot' => '50', 'rep' => '10', 'wei' => '62',
                ],
                'laps' => [
                    ['idx' => 0, 'pos' => 10, 'weather' => 'Sunny', 'temp' => 20, 'hum' => 10],
                    ['idx' => 1, 'pos' => $pos, 'weather' => 'Sunny', 'temp' => 20, 'hum' => 10],
                ],
            ]));
        }

        $report = $this->intel->report();
        $this->assertTrue($report['has_data']);
        $this->assertArrayHasKey('Rookie', $report['attributes']);

        $talent = null;
        foreach ($report['attributes']['Rookie'] as $row) {
            if ($row['attribute'] === 'driver_tal') {
                $talent = $row;
            }
        }

        $this->assertNotNull($talent);
        $this->assertEqualsWithDelta(1.0, (float) $talent['advantage'], 0.0001);
        $this->assertSame('strong', $talent['strength']);
    }

    public function testLevelsAreOrderedByTheGproLadder(): void
    {
        foreach (['Elite', 'Rookie', 'Master', 'Amateur', 'Pro'] as $i => $level) {
            $this->ingest->ingest($this->analysis([
                'selRaceNb' => (string) ($i + 1),
                'group'     => $level . ' - 1',
                'q1Time'    => '1:4' . $i . '.000s',
            ]));
        }

        $levels = array_column($this->intel->report()['levels'], 'level');

        $this->assertSame(['Rookie', 'Amateur', 'Pro', 'Master', 'Elite'], $levels);
    }

    public function testReportNeverExposesUserIdentifyingKeys(): void
    {
        $this->ingest->ingest($this->analysis());

        $json = json_encode($this->intel->report(), JSON_THROW_ON_ERROR);

        $this->assertDoesNotMatchRegularExpression('/user_id|username|email|api_token/i', $json);
    }
}
