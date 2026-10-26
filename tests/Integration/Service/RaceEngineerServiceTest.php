<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Database\DatabaseSeeder;
use App\Engineer\CtrCeiling;
use App\Repository\EconomyLedgerRepository;
use App\Repository\SeasonObjectiveRepository;
use App\Repository\StandingsRepository;
use App\Repository\TrackRepository;
use App\Repository\WeekendStateRepository;
use App\Security\ApiTokenCrypto;
use App\Service\BoostFuelService;
use App\Service\CarWearPlannerService;
use App\Service\CarWearService;
use App\Service\FuelModel;
use App\Service\RaceEngineerService;
use App\Service\RiskAdvisorService;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RaceEngineerService::class)]
final class RaceEngineerServiceTest extends TestCase
{
    private const int SEASON = 112;
    private const int USER = 1;

    private PDO $db;
    private SeasonObjectiveRepository $pins;
    private StandingsRepository $standings;
    private WeekendStateRepository $weekends;
    private EconomyLedgerRepository $ledger;
    private RaceEngineerService $service;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        (new DatabaseSeeder(
            $this->db,
            ['Concentration' => 'concentration'],
            ['Rookie', 'Amateur', 'Pro', 'Master', 'Elite'],
            [],
            new ApiTokenCrypto('engineer-test-secret'),
        ))->migrate();

        $this->pins = new SeasonObjectiveRepository($this->db);
        $this->standings = new StandingsRepository($this->db);
        $this->weekends = new WeekendStateRepository($this->db);
        $this->ledger = new EconomyLedgerRepository($this->db);

        $wear = new CarWearService($this->db, [
            'driver_wear_factors' => ['concentration' => 1.0, 'talent' => 1.0, 'experience' => 1.0],
            'part_level_factors' => [1 => 1.05] + array_fill_keys(range(2, 11), 1.0),
        ]);

        $this->service = new RaceEngineerService(
            $this->pins,
            $this->standings,
            $this->weekends,
            $this->ledger,
            new TrackRepository($this->db),
            $wear,
            new CarWearPlannerService($wear),
            new CtrCeiling($wear),
            new RiskAdvisorService(),
            new BoostFuelService(),
            new FuelModel(['fuel_factors' => [
                'conc' => 0.0, 'agg' => 0.0, 'exp' => 0.0, 'te' => 0.0,
                'eng_lvl' => 0.0, 'ele_lvl' => 0.0, 'constant' => 0.0,
            ]]),
        );

        $this->db->exec(
            "INSERT INTO tracks (name, laps, distance, fuel_per_lap, fuel_per_lap_wet, boost_dry, boost_wet,
                overtaking, grip, tyre_wear, pit_time, wear_engine, wear_brakes, wear_electronics)
             VALUES ('Testring', 50, 250.0, 2.0, 3.0, 0.3, 0.4, 'Normal', 'Normal', 'Normal', 20.0, 40, 30, 10)"
        );
    }

    public function testBuildsObjectiveAndLinesFromStoredRows(): void
    {
        $this->seedPromotionFixture();

        $card = $this->service->forCockpit(self::USER, $this->cockpit());

        self::assertSame(self::SEASON, $card['season']);
        self::assertSame('promote', $card['objective']['objective']);
        self::assertFalse($card['objective']['pinned']);
        self::assertSame(['objective', 'cash'], array_slice(array_column($card['lines'], 'key'), 0, 2));
        $cash = $card['lines'][1];
        self::assertSame('Cash covers the season', $cash['answer']);
        self::assertNotNull($cash['figure']);
    }

    public function testUsesTheLatestStandingsRowNotAfterTheNextRace(): void
    {
        $this->seedPromotionFixture();
        $this->standing(12, 20, 5);

        $card = $this->service->forCockpit(self::USER, $this->cockpit());

        self::assertSame(2, $card['objective']['numbers']['pos']);
        self::assertSame(9, $card['objective']['numbers']['races_left']);
    }

    public function testPinIsAppliedForTheCurrentSeasonOnly(): void
    {
        $this->seedPromotionFixture();

        self::assertTrue($this->service->pin(self::USER, self::SEASON - 1, 'survive'));
        self::assertFalse($this->service->forCockpit(self::USER, $this->cockpit())['objective']['pinned']);

        self::assertTrue($this->service->pin(self::USER, self::SEASON, 'survive'));
        $card = $this->service->forCockpit(self::USER, $this->cockpit());
        self::assertTrue($card['objective']['pinned']);
        self::assertSame('survive', $card['objective']['objective']);
        self::assertSame('promote', $card['objective']['proposed']);
    }

    public function testPinValues(): void
    {
        self::assertTrue($this->service->pin(self::USER, self::SEASON, 'hold'));
        self::assertSame('hold', $this->pins->find(self::USER, self::SEASON));

        self::assertFalse($this->service->pin(self::USER, self::SEASON, 'bogus'));
        self::assertSame('hold', $this->pins->find(self::USER, self::SEASON));

        self::assertTrue($this->service->pin(self::USER, self::SEASON, 'auto'));
        self::assertNull($this->pins->find(self::USER, self::SEASON));

        self::assertFalse($this->service->pin(self::USER, self::SEASON, 'bogus'));
        self::assertNull($this->pins->find(self::USER, self::SEASON));
    }

    public function testWearTrackAndWeatherDriveCtrRiskAndBoostLines(): void
    {
        $this->seedPromotionFixture();

        $keys = array_column($this->service->forCockpit(self::USER, $this->cockpit())['lines'], 'key');

        self::assertContains('ctr', $keys);
        self::assertContains('risk', $keys);
        self::assertContains('boost', $keys);
    }

    public function testBoostLitresUseTheWetCoefficientWhenTheRaceStartsWet(): void
    {
        $this->seedPromotionFixture();
        $weather = ['q1_wet' => false, 'q2_wet' => false, 'race_rain_avg' => 60.0, 'race_start_wet' => true];

        $lines = $this->service->forCockpit(self::USER, $this->cockpit(['weather' => $weather]))['lines'];
        $boost = array_values(array_filter($lines, static fn (array $l): bool => $l['key'] === 'boost'))[0];

        // 9 laps x 5 km x 0.4 = 18 L
        self::assertSame('Boost: 3 sets · +18 L', $boost['answer']);
    }

    public function testForcedSwapPartsCountAsFreshInTheCeilingAndArePricedFromTheSwapPlan(): void
    {
        $this->seedPromotionFixture();
        $engine = ['level' => 1, 'start' => 95, 'est' => 40.0, 'end' => 135.0, 'track_base' => 8.0];
        $brakes = ['level' => 5, 'start' => 10, 'est' => 15.0, 'end' => 25.0, 'track_base' => 30.0];

        $lines = $this->service->forCockpit(self::USER, $this->cockpit([
            'ctr' => 100,
            'wear' => ['track_name' => 'Testring', 'laps' => 50, 'parts' => ['Engine' => $engine, 'Brakes' => $brakes]],
            'wear_advice' => [
                'swap' => [['part' => 'Engine', 'level' => 5, 'start' => 95, 'est' => 40.0, 'end' => 135.0]],
                'risky' => [], 'watch' => [], 'headline' => '',
            ],
            'swap_plan' => ['cost' => 200_000],
        ]))['lines'];
        $byKey = array_column($lines, null, 'key');

        self::assertSame('CTR 51', $byKey['ctr']['answer']);
        self::assertSame('Swap Engine', $byKey['parts']['answer']);
        self::assertSame('$200k', $byKey['parts']['figure']);
    }

    public function testCeilingIsStableWhenFollowed(): void
    {
        $this->seedPromotionFixture();
        $this->service->pin(self::USER, self::SEASON, 'survive');
        $parts = [
            'Engine' => ['level' => 1, 'start' => 60, 'est' => 0.0, 'end' => 0.0, 'track_base' => 8.0],
            'Brakes' => ['level' => 1, 'start' => 10, 'est' => 0.0, 'end' => 0.0, 'track_base' => 3.0],
        ];
        $swapAt100 = ['swap' => [['part' => 'Engine', 'level' => 5, 'start' => 60, 'est' => 50.0, 'end' => 110.0]]];
        $none = ['swap' => []];
        $targetAt = function (int $ctr, array $advice) use ($parts): string {
            $lines = $this->service->forCockpit(self::USER, $this->cockpit([
                'ctr' => $ctr,
                'wear' => ['track_name' => 'Testring', 'laps' => 50, 'parts' => $parts],
                'wear_advice' => $advice + ['risky' => [], 'watch' => [], 'headline' => ''],
            ]))['lines'];

            return array_column($lines, null, 'key')['ctr']['answer'];
        };

        $first = $targetAt(100, $swapAt100);
        self::assertMatchesRegularExpression('/^CTR \d+$/', $first);
        $target = (int) substr($first, 4);
        self::assertLessThan(100, $target);

        self::assertSame($first, $targetAt($target, $none));
    }

    public function testWithoutWearTheCtrLineIsOmitted(): void
    {
        $this->seedPromotionFixture();

        $keys = array_column($this->service->forCockpit(self::USER, $this->cockpit(['wear' => null]))['lines'], 'key');

        self::assertNotContains('ctr', $keys);
        self::assertContains('risk', $keys);
        self::assertContains('boost', $keys);
    }

    public function testATrackMissingFromTheDatabaseKeepsCtrButOmitsRiskAndBoost(): void
    {
        $this->seedPromotionFixture();

        $keys = array_column(
            $this->service->forCockpit(self::USER, $this->cockpit(['track_name' => 'Nowhere']))['lines'],
            'key',
        );

        self::assertContains('ctr', $keys);
        self::assertNotContains('risk', $keys);
        self::assertNotContains('boost', $keys);
    }

    public function testPushAdviceFollowsTheNextRacesRound(): void
    {
        $this->seedPromotionFixture();
        $this->seedWearTracks();
        $calendar = [
            ['race' => 9, 'track_name' => 'Alpha'],
            ['race' => 10, 'track_name' => 'Beta'],
            ['race' => 11, 'track_name' => 'Gamma'],
        ];
        $reasonFor = function (int $raceNb) use ($calendar): string {
            $lines = $this->service->forCockpit(self::USER, $this->cockpit([
                'office' => ['seasonNb' => self::SEASON, 'raceNb' => $raceNb],
                'car' => $this->pricedCar(),
                'season_calendar' => $calendar,
            ]))['lines'];

            return array_column($lines, null, 'key')['ctr']['reason'];
        };

        // Alpha wears the 10x engine, Beta the cheap brakes, Gamma everything alike.
        self::assertStringStartsWith('Race rated Hold back: ', $reasonFor(9));
        self::assertStringStartsWith('Race rated Push: the highest CTR where no part breaks', $reasonFor(10));
        self::assertStringStartsWith('Race rated Normal: ', $reasonFor(11));
    }

    public function testStaleSetupKeepsTheRiskLineWithoutWeather(): void
    {
        $this->seedPromotionFixture();

        $lines = $this->service->forCockpit(
            self::USER,
            $this->cockpit(['weather' => null, 'setup_stale' => true]),
        )['lines'];
        $risk = array_values(array_filter($lines, static fn (array $l): bool => $l['key'] === 'risk'))[0];

        self::assertSame('After you save the race setup', $risk['answer']);
    }

    public function testTestingCostIsTheMeanOfThisSeasonsSessions(): void
    {
        $this->seedPromotionFixture();
        $this->movement('Testing session 1', -80_000, 8);
        $this->movement('Testing session 2', -120_000, 9);
        $this->movement('Practice lap', -5_000, 9);

        $lines = $this->service->forCockpit(self::USER, $this->cockpit([
            'testing_open' => true,
            'testing_targets' => [[
                'offset' => 3, 'season' => 'current', 'race' => 13, 'track_id' => 1, 'track_name' => 'Testring',
                'power' => 1, 'handling' => 1, 'acceleration' => 1,
            ]],
        ]))['lines'];
        $testing = array_values(array_filter($lines, static fn (array $l): bool => $l['key'] === 'testing'))[0];

        self::assertSame('Test at Testring', $testing['answer']);
        self::assertSame('$100k', $testing['figure']);
    }

    public function testNoStoredRowsStillReturnsLines(): void
    {
        $card = $this->service->forCockpit(99, $this->cockpit(['menu' => ['cash' => 1_000_000]]));

        self::assertNull($card['objective']['objective']);
        self::assertSame('Objective unknown', $card['objective']['label']);
        self::assertSame('objective', $card['lines'][0]['key']);
        self::assertSame('cash', $card['lines'][1]['key']);
        self::assertSame('Costs unknown — sync once after a race', $card['lines'][1]['answer']);
    }

    public function testSeasonOverReturnsOnlyTheObjectiveLine(): void
    {
        $this->seedPromotionFixture();
        $this->standing(17, 2, 120);

        $card = $this->service->forCockpit(self::USER, $this->cockpit(['office' => [
            'seasonNb' => self::SEASON, 'raceNb' => 18,
        ]]));

        self::assertTrue($card['objective']['over']);
        self::assertCount(1, $card['lines']);
    }

    /** @param array<string, mixed> $overrides */
    private function cockpit(array $overrides = []): array
    {
        return array_merge([
            'office' => ['seasonNb' => self::SEASON, 'raceNb' => 10],
            'menu' => ['IDM' => 7, 'cash' => 5_000_000],
            'money_levels' => ['managers' => [['IDM' => 7, 'carLevelRank' => 2]]],
            'division' => 'Amateur',
            'car' => [],
            'driver' => ['concentration' => 100, 'talent' => 90, 'experience' => 80],
            'wear' => ['track_name' => 'Testring', 'laps' => 50, 'parts' => [
                'Engine' => ['level' => 5, 'start' => 10, 'est' => 20.0, 'end' => 30.0, 'track_base' => 40.0],
                'Brakes' => ['level' => 5, 'start' => 10, 'est' => 15.0, 'end' => 25.0, 'track_base' => 30.0],
            ]],
            'wear_advice' => ['swap' => [], 'risky' => [], 'watch' => [], 'headline' => ''],
            'swap_plan' => null,
            'training_picks' => [],
            'testing_targets' => [],
            'testing_open' => false,
            'season_calendar' => [
                ['race' => 9, 'track_name' => 'Testring'],
                ['race' => 10, 'track_name' => 'Testring'],
            ],
            'track_name' => 'Testring',
            'weather' => ['q1_wet' => false, 'q2_wet' => false, 'race_rain_avg' => 0.0, 'race_start_wet' => false],
            'setup_stale' => false,
            'ctr' => 30,
        ], $overrides);
    }

    private function seedWearTracks(): void
    {
        $columns = array_column(CarWearService::PARTS_MAP, 'db');
        $insert = $this->db->prepare(
            'INSERT INTO tracks (name, laps, distance, ' . implode(', ', $columns) . ')
             VALUES (:name, 50, 250.0, ' . implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)) . ')'
        );
        $heavy = ['Alpha' => 'wear_engine', 'Beta' => 'wear_brakes'];
        foreach (['Alpha', 'Beta', 'Gamma'] as $name) {
            $params = [':name' => $name];
            foreach ($columns as $column) {
                $params[':' . $column] = match (true) {
                    ($heavy[$name] ?? null) === $column => 20.0,
                    isset($heavy[$name]) => 5.0,
                    default => 8.0,
                };
            }
            $insert->execute($params);
        }
    }

    /** @return array<string, mixed> level 1 parts, an engine ten times the price of any other */
    private function pricedCar(): array
    {
        $car = [];
        foreach (CarWearService::PARTS_MAP as $label => $map) {
            $car[$map['lvl']] = 1;
            $car[$map['wear']] = 0;
            $car[$map['options']] = [
                ['value' => ['value' => 1, 'cost' => $label === 'Engine' ? 10_000_000 : 1_000_000], 'newLvl' => '1'],
            ];
        }

        return $car;
    }

    private function seedPromotionFixture(): void
    {
        $this->standing(9, 2, 60);
        $this->weekends->save([
            'user_id' => self::USER, 'season' => self::SEASON, 'race' => 9, 'source' => 'snapshot',
            'driver_json' => json_encode(['salary' => 100_000], JSON_THROW_ON_ERROR),
            'td_json' => null,
            'staff_json' => json_encode(['salary' => 50_000], JSON_THROW_ON_ERROR),
            'facilities_json' => null, 'car_json' => null,
            'cash' => 5_000_000, 'pts' => 60, 'pos' => 2,
        ]);
        foreach ([8, 9] as $race) {
            $this->movement('Sponsor Acme', 300_000, $race);
            $this->movement('Race ' . $race . ' finish position', 100_000, $race);
            $this->movement('Facilities upkeep', -50_000, $race);
            $this->movement('Tyre supplier', -20_000, $race);
        }
    }

    private function standing(int $race, int $pos, int $pts): void
    {
        $managers = [];
        for ($i = 1; $i <= 28; $i++) {
            $managers[] = [
                'pos' => $i,
                'pts' => $i === $pos ? $pts : max(0, 70 - $i * 2),
                'manBgColor' => match (true) {
                    $i <= 3 => '#007700',
                    $i >= 26 => '#990000',
                    default => '#ffffff',
                },
            ];
        }
        $this->standings->save([
            'user_id' => self::USER, 'season' => self::SEASON, 'race' => $race,
            'own_pos' => $pos, 'own_pts' => $pts, 'group_label' => 'Amateur - 4',
            'managers_json' => json_encode($managers, JSON_THROW_ON_ERROR),
        ]);
    }

    private function movement(string $description, int $amount, int $race): void
    {
        $at = sprintf('2026-10-%02d 12:00:00', $race);
        $this->ledger->save([
            'user_id' => self::USER, 'season' => self::SEASON, 'race' => $race, 'source' => 'economy',
            'occurred_at' => $at, 'description' => $description, 'amount' => $amount,
            'balance_after' => null, 'fingerprint' => sha1($description . $race . $amount),
        ]);
    }
}
