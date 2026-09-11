<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Database\DatabaseSeeder;
use App\Repository\RaceHistoryRepository;
use App\Repository\RaceTelemetryRepository;
use App\Security\ApiTokenCrypto;
use App\Service\DebriefService;
use App\Service\RaceIntelligenceService;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DebriefService::class)]
final class DebriefServiceTest extends TestCase
{
    private PDO $db;
    private DebriefService $service;
    private RaceTelemetryRepository $corpus;
    private RaceHistoryRepository $history;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        (new DatabaseSeeder(
            $this->db,
            ['Concentration' => 'concentration'],
            ['Rookie', 'Amateur', 'Pro', 'Master', 'Elite'],
            [],
            new ApiTokenCrypto('debrief-test-secret'),
        ))->migrate();

        $this->corpus = new RaceTelemetryRepository($this->db);
        $this->history = new RaceHistoryRepository($this->db);
        $this->service = new DebriefService(
            $this->history,
            $this->corpus,
            new RaceIntelligenceService($this->corpus),
        );
    }

    /** @param array<string, mixed> $overrides */
    private function corpusRow(array $overrides = []): void
    {
        $this->corpus->insertIfNew(array_merge([
            'season' => 107, 'race' => 13, 'level' => 'Amateur',
            'group_label' => 'Amateur - 4', 'track_id' => 17, 'track_name' => 'Hockenheim',
            'final_pos' => 5, 'start_pos' => 8, 'points' => 10,
            'q1_pos' => 7, 'q2_pos' => 6, 'q1_time_ms' => 74839, 'q2_time_ms' => 76612,
            'positions_gained' => 3, 'dnf' => 0, 'laps_completed' => 67,
            'driver_id' => 3000, 'driver_oa' => 140,
            'q1_risk' => 'Push the car a lot', 'q2_risk' => 'Push the car a lot',
            'start_risk' => 'Overtake where possible',
            'overtake_risk' => 17, 'defend_risk' => 28, 'clear_dry_risk' => 35,
            'clear_wet_risk' => 35, 'problem_risk' => 25,
            'race_tyre' => 'Hard', 'tyre_supplier' => 'Pipirelli',
            'was_wet' => 0, 'avg_temp' => 44.0, 'avg_humidity' => 35.0,
            'pit_stops' => 2, 'start_fuel' => 100,
            // NOT NULL with a default, but insertIfNew binds every column
            // explicitly — omit it and the row is silently dropped by the
            // INSERT OR IGNORE.
            'has_td' => 0,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function ownRow(array $overrides = []): void
    {
        $this->history->insertIfNew(array_merge([
            'user_id' => 1, 'season' => 107, 'race' => 13,
            'track_id' => 17, 'track_name' => 'Hockenheim',
            'level' => 'Amateur', 'group_label' => 'Amateur - 4',
            'grid_pos' => 8, 'final_pos' => 3, 'points' => 15,
            'positions_gained' => 5, 'dnf' => 0, 'pit_stops' => 2,
            'race_tyre' => 'Hard', 'tyre_supplier' => 'Pipirelli', 'was_wet' => 0,
        ], $overrides));
    }

    public function testMineScopeReadsTheOwnArchiveAndAllScopeReadsTheCorpus(): void
    {
        $this->ownRow();
        $this->corpusRow(['race' => 1]);
        $this->corpusRow(['race' => 2]);

        $mine = $this->service->birdsEye(1, 'mine', [], 'impressive');
        $all = $this->service->birdsEye(1, 'all', [], 'impressive');

        $this->assertSame('mine', $mine['scope']);
        $this->assertCount(1, $mine['rows']);

        $this->assertSame('all', $all['scope']);
        $this->assertCount(2, $all['rows']);
    }

    public function testEveryoneModeRowsCarryNoManagerIdentity(): void
    {
        $this->corpusRow();

        $all = $this->service->birdsEye(1, 'all', [], 'impressive');

        foreach ($all['rows'] as $row) {
            $this->assertArrayNotHasKey('user_id', $row);
            $this->assertArrayNotHasKey('username', $row);
        }
    }

    public function testAnAnonymousVisitorCannotReachTheMineScope(): void
    {
        $this->ownRow();
        $this->corpusRow();

        // No user id means no personal archive to read — it falls back to the
        // corpus rather than returning somebody else's rows.
        $view = $this->service->birdsEye(null, 'mine', [], 'impressive');

        $this->assertSame('all', $view['scope']);
        $this->assertSame(0, $view['own_races']);
    }

    public function testRaceDetailDefaultsToTheNewestArchivedRace(): void
    {
        $this->ownRow(['season' => 107, 'race' => 13]);
        $this->ownRow(['season' => 108, 'race' => 2]);

        $detail = $this->service->raceDetail(1, null, null);

        $this->assertNotNull($detail);
        $this->assertSame(108, (int) $detail['season']);
    }

    public function testRiskDistributionIsAPercentageOfThatDivisionsRaces(): void
    {
        // Three Amateur races: two pushed a lot, one kept it on the track.
        $this->corpusRow(['race' => 1, 'q1_risk' => 'Push the car a lot']);
        $this->corpusRow(['race' => 2, 'q1_risk' => 'Push the car a lot']);
        $this->corpusRow(['race' => 3, 'q1_risk' => 'Keep the car on the track']);

        $view = $this->service->trackHistory(17, 1);
        $byChoice = [];
        foreach ($view['q1_risk']['choices'] as $row) {
            $byChoice[$row['choice']] = $row['cells'][0];
        }

        $this->assertSame(66.7, $byChoice['Push the car a lot']['percent']);
        $this->assertSame(33.3, $byChoice['Keep the car on the track']['percent']);
    }

    public function testRiskChoicesStayInCanonicalOrderNotFrequencyOrder(): void
    {
        $this->corpusRow(['race' => 1, 'q1_risk' => 'Push the car to the limit']);
        $this->corpusRow(['race' => 2, 'q1_risk' => 'Keep the car on the track']);

        $view = $this->service->trackHistory(17, 1);
        $order = array_column($view['q1_risk']['choices'], 'choice');

        // Least aggressive first, regardless of how many picked each.
        $this->assertSame(
            ['Keep the car on the track', 'Push the car to the limit'],
            $order,
        );
    }

    public function testTrackHistoryCarriesTheManagersOwnRacesAtThatCircuit(): void
    {
        $this->corpusRow();
        $this->ownRow(['track_id' => 17]);
        $this->ownRow(['race' => 14, 'track_id' => 11]);

        $view = $this->service->trackHistory(17, 1);

        $this->assertCount(1, $view['mine']);
        $this->assertSame(13, (int) $view['mine'][0]['race']);
    }

    public function testTrackHistoryForAnUnknownTrackReportsNoData(): void
    {
        $view = $this->service->trackHistory(999, 1);

        $this->assertFalse($view['has_data']);
        $this->assertSame(0, $view['total_races']);
    }

    public function testInsightsReusesTheIntelligenceReport(): void
    {
        $this->corpusRow();

        $view = $this->service->insights(null);

        // The carried-over report keys must survive, so the admin screen and
        // the Insights tab can never drift apart.
        foreach (['total', 'levels', 'attributes', 'prototype', 'tyres', 'strategy'] as $key) {
            $this->assertArrayHasKey($key, $view);
        }
    }

    public function testInsightsAddsTheWinnerProfileOnlyWhenATrackIsChosen(): void
    {
        $this->corpusRow();

        $this->assertArrayNotHasKey('winner_profile', $this->service->insights(null));
        $this->assertArrayHasKey('winner_profile', $this->service->insights(17));
    }
}
