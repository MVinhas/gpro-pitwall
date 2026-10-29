<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Engineer\SeasonBudget;
use App\Engineer\SeasonObjective;
use App\Engineer\StrengthFloor;
use App\Engineer\SwapLevelPicker;
use App\Engineer\SwapSeason;
use App\Service\CarWearService;
use App\Service\PartSwapAdvisorService;
use App\Service\PartUpgradeAdvisorService;
use App\Service\PhaMatchService;
use ArrayObject;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PartSwapAdvisorService::class)]
final class PartSwapAdvisorServiceTest extends TestCase
{
    private PartSwapAdvisorService $svc;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $carWear = new CarWearService($pdo, [
            'driver_wear_factors' => ['concentration' => 1.0, 'talent' => 1.0, 'experience' => 1.0],
            'part_level_factors'  => [
                1 => 1.02, 2 => 1.018, 3 => 1.016, 4 => 1.014,
                5 => 1.012, 6 => 1.01, 7 => 1.008, 8 => 1.006, 9 => 1.004,
            ],
        ]);
        $pha = new PhaMatchService();
        $upgrade = new PartUpgradeAdvisorService($pha, [
            'Engine'    => ['power' => 6, 'handling' => 0, 'acceleration' => 2],
            'Gearbox'   => ['power' => 3, 'handling' => 1, 'acceleration' => 5],
        ]);
        $this->svc = new PartSwapAdvisorService($carWear, $pha, $upgrade);
    }

    /**
     * @param list<array{level: int, wear: int, cost: int, action: int}> $opts
     * @return array<string, mixed>
     */
    private function carData(array $opts): array
    {
        $options = [];
        foreach ($opts as $o) {
            $options[] = [
                'value'    => ['value' => $o['action'], 'cost' => $o['cost']],
                'newLvl'   => (string) $o['level'],
                'newWear'  => (string) $o['wear'],
                'disabled' => 'false',
                'text'     => '',
            ];
        }
        return ['engineOptions' => $options];
    }

    /**
     * @return list<array{part: string, level: int, start: int, est: float, end: float}>
     */
    private function flaggedEngine(int $level = 6, int $start = 95, float $end = 100.0): array
    {
        return [['part' => 'Engine', 'level' => $level, 'start' => $start, 'est' => $end - $start, 'end' => $end]];
    }

    /**
     * @return array<string, array{level: int, start: int, est: float, end: float, track_base: float}>
     */
    private function wearParts(float $trackBase = 5.0, int $level = 6, int $start = 95, float $end = 100.0): array
    {
        return ['Engine' => [
            'level' => $level, 'start' => $start, 'est' => $end - $start, 'end' => $end, 'track_base' => $trackBase,
        ]];
    }

    /**
     * @param list<array<string, mixed>> $options
     * @return list<int>
     */
    private function levels(array $options): array
    {
        return array_values(array_map(static fn(array $o): int => (int) $o['level'], $options));
    }

    /**
     * @param list<array<string, mixed>> $options
     * @return array<string, mixed>
     */
    private function atLevel(array $options, int $level): array
    {
        foreach ($options as $o) {
            if ($o['level'] === $level) {
                return $o;
            }
        }
        self::fail('No replacement option at level ' . $level);
    }

    public function testOneLevelDownAndEveryOfferedLevelUpAreOffered(): void
    {
        $track = ['power' => 10, 'handling' => 10, 'acceleration' => 10];
        $car   = ['power' => 90, 'handling' => 80, 'acceleration' => 85];

        $carData = $this->carData([
            ['level' => 3, 'wear' => 0, 'cost' => 200_000,   'action' => 3],
            ['level' => 4, 'wear' => 0, 'cost' => 400_000,   'action' => 4],
            ['level' => 5, 'wear' => 0, 'cost' => 700_000,   'action' => 5],
            ['level' => 6, 'wear' => 0, 'cost' => 900_000,   'action' => 6],
            ['level' => 7, 'wear' => 0, 'cost' => 1_500_000, 'action' => 7],
        ]);

        $out = $this->svc->advise(
            $this->flaggedEngine(level: 5),
            $carData,
            $this->wearParts(level: 5),
            [],
            $track,
            $car,
            0,
            [],
            10_000_000,
        );

        $levels = $this->levels($out['Engine']['options']);
        sort($levels);
        $this->assertSame([4, 5, 6, 7], $levels);
    }

    public function testEveryOptionNamesItsKind(): void
    {
        $track = ['power' => 10, 'handling' => 10, 'acceleration' => 10];
        $car   = ['power' => 90, 'handling' => 80, 'acceleration' => 85];

        $out = $this->svc->advise(
            $this->flaggedEngine(),
            $this->carData([
                ['level' => 5, 'wear' => 20, 'cost' => 0,         'action' => -1],
                ['level' => 6, 'wear' => 0,  'cost' => 900_000,   'action' => 6],
                ['level' => 8, 'wear' => 0,  'cost' => 2_000_000, 'action' => 8],
            ]),
            $this->wearParts(),
            [],
            $track,
            $car,
            0,
            [],
            10_000_000,
        );
        $byLevel = array_column($out['Engine']['options'], null, 'level');
        $this->assertSame('free_downgrade', $byLevel[5]['kind']);
        $this->assertSame('sidegrade', $byLevel[6]['kind']);
        $this->assertSame('upgrade', $byLevel[8]['kind']);

        $paid = $this->svc->advise(
            $this->flaggedEngine(),
            $this->carData([['level' => 5, 'wear' => 0, 'cost' => 700_000, 'action' => 5]]),
            $this->wearParts(),
            [],
            $track,
            $car,
            0,
            [],
            10_000_000,
        );
        $this->assertSame('downgrade', $paid['Engine']['options'][0]['kind']);
    }

    /**
     * @return array{array<string, mixed>, array<string, mixed>, list<array<string, mixed>>}
     */
    private function seasonScenario(): array
    {
        return [
            ['power' => 10, 'handling' => 10, 'acceleration' => 10],
            ['power' => 90, 'handling' => 80, 'acceleration' => 85],
            $this->carData([
                ['level' => 5, 'wear' => 0, 'cost' => 700_000,   'action' => 5],
                ['level' => 6, 'wear' => 0, 'cost' => 900_000,   'action' => 6],
                ['level' => 7, 'wear' => 0, 'cost' => 1_500_000, 'action' => 7],
            ]),
        ];
    }

    private function aSeason(): SwapSeason
    {
        return new SwapSeason(
            'survive',
            ['v' => 1, 'ctr' => 0, 'mode' => 'nurse', 'safety' => 0, 'swaps' => [], 'refused_swap_cost' => null,
                'target' => 0, 'target_kind' => 'cushion', 'cushion' => 0, 'season_start' => null, 'envelope' => 0,
                'shortfall' => null, 'rows' => [], 'partial' => [], 'race' => 1],
            [],
            [],
            [],
            [],
            1.0,
            10_000_000,
            0,
            0,
            new StrengthFloor(null, null, null),
        );
    }

    /**
     * @param array<string, mixed>|null $pick
     */
    private function withPicker(?array $pick): PartSwapAdvisorService
    {
        $picker = $this->createStub(SwapLevelPicker::class);
        $picker->method('pick')->willReturn($pick);

        return new PartSwapAdvisorService(
            new CarWearService(new PDO('sqlite::memory:'), [
                'driver_wear_factors' => ['concentration' => 1.0, 'talent' => 1.0, 'experience' => 1.0],
                'part_level_factors'  => [
                    1 => 1.02, 2 => 1.018, 3 => 1.016, 4 => 1.014,
                    5 => 1.012, 6 => 1.01, 7 => 1.008, 8 => 1.006, 9 => 1.004,
                ],
            ]),
            new PhaMatchService(),
            new PartUpgradeAdvisorService(new PhaMatchService(), [
                'Engine'  => ['power' => 6, 'handling' => 0, 'acceleration' => 2],
                'Gearbox' => ['power' => 3, 'handling' => 1, 'acceleration' => 5],
            ]),
            $picker,
        );
    }

    public function testWithASeasonThePickersChoiceIsTheRecommendation(): void
    {
        [$track, $car, $carData] = $this->seasonScenario();
        $svc = $this->withPicker([
            'level' => 7, 'kind' => 'upgrade', 'cost' => 1_500_000, 'season_pha' => 9.0, 'net_cost' => 1,
            'affordable' => true, 'reason' => 'most_pha',
        ]);

        $out = $svc->advise(
            $this->flaggedEngine(),
            $carData,
            $this->wearParts(),
            [],
            $track,
            $car,
            0,
            [],
            10_000_000,
            [],
            $this->aSeason(),
        );

        $options = $out['Engine']['options'];
        $this->assertSame(7, $options[0]['level']);
        $this->assertSame([7], array_column(array_filter($options, static fn(array $o): bool => $o['recommended']), 'level'));
        $this->assertSame('season', $options[0]['recommend_reason']);
        $this->assertTrue($out['Engine']['season']);
        $this->assertSame('Most PHA the season budget allows', $out['Engine']['season_reason']);

        $rest = $this->levels(array_slice($options, 1));
        $plain = $this->levels($this->svc->advise(
            $this->flaggedEngine(),
            $carData,
            $this->wearParts(),
            [],
            $track,
            $car,
            0,
            [],
            10_000_000,
        )['Engine']['options']);
        $this->assertSame(array_values(array_diff($plain, [7])), $rest);

        $summary = $svc->summarise($out, $track, $car);
        $this->assertSame(1_500_000, $summary['cost']);
    }

    public function testWithoutASeasonTodaysTrackFitRankingStands(): void
    {
        [$track, $car, $carData] = $this->seasonScenario();
        $svc = $this->withPicker([
            'level' => 7, 'kind' => 'upgrade', 'cost' => 1_500_000, 'season_pha' => 9.0, 'net_cost' => 1,
            'affordable' => true, 'reason' => 'most_pha',
        ]);

        $out = $svc->advise($this->flaggedEngine(), $carData, $this->wearParts(), [], $track, $car, 0, [], 10_000_000);
        $plain = $this->svc->advise($this->flaggedEngine(), $carData, $this->wearParts(), [], $track, $car, 0, [], 10_000_000);

        $this->assertFalse($out['Engine']['season']);
        $this->assertNull($out['Engine']['season_reason']);
        $this->assertSame($plain['Engine']['options'], $out['Engine']['options']);
    }

    public function testWithoutASeasonOnlyLevelsWithinOneStepAreRecommended(): void
    {
        // Power-hungry track: every Engine level adds power, so L8 fits best, yet without a season plan
        // the recommendation stays within one level of the current L5.
        $track = ['power' => 20, 'handling' => 5, 'acceleration' => 5];
        $car   = ['power' => 80, 'handling' => 90, 'acceleration' => 85];
        $carData = $this->carData([
            ['level' => 4, 'wear' => 0, 'cost' => 300_000,   'action' => 4],
            ['level' => 5, 'wear' => 0, 'cost' => 500_000,   'action' => 5],
            ['level' => 6, 'wear' => 0, 'cost' => 700_000,   'action' => 6],
            ['level' => 7, 'wear' => 0, 'cost' => 900_000,   'action' => 7],
            ['level' => 8, 'wear' => 0, 'cost' => 1_100_000, 'action' => 8],
        ]);

        $options = $this->svc->advise(
            $this->flaggedEngine(level: 5),
            $carData,
            $this->wearParts(level: 5),
            [],
            $track,
            $car,
            0,
            [],
            10_000_000,
        )['Engine']['options'];

        $byLevel = array_column($options, null, 'level');
        $this->assertGreaterThan($byLevel[6]['fit'], $byLevel[8]['fit']);
        $recommended = array_column(array_filter($options, static fn(array $o): bool => $o['recommended']), 'level');
        $this->assertCount(1, $recommended);
        $this->assertSame([6], array_values($recommended));
        $this->assertTrue($options[0]['recommended']);
        $this->assertSame([4, 5, 6, 7, 8], (static function (array $l): array {
            sort($l);
            return $l;
        })($this->levels($options)));
    }

    public function testWithoutASeasonAndNothingWithinOneStepTheSmallestJumpIsRecommended(): void
    {
        $track = ['power' => 20, 'handling' => 5, 'acceleration' => 5];
        $car   = ['power' => 80, 'handling' => 90, 'acceleration' => 85];
        $carData = $this->carData([
            ['level' => 7, 'wear' => 0, 'cost' => 900_000,   'action' => 7],
            ['level' => 8, 'wear' => 0, 'cost' => 1_100_000, 'action' => 8],
        ]);

        $options = $this->svc->advise(
            $this->flaggedEngine(level: 5),
            $carData,
            $this->wearParts(level: 5),
            [],
            $track,
            $car,
            0,
            [],
            10_000_000,
        )['Engine']['options'];

        $recommended = array_values(array_column(array_filter($options, static fn(array $o): bool => $o['recommended']), 'level'));
        $this->assertSame([7], $recommended);
        $this->assertSame(7, $options[0]['level']);
        $this->assertSame('value', $options[0]['recommend_reason']);
    }

    public function testWhenThePickerFindsNothingTheTrackFitRankingStands(): void
    {
        [$track, $car, $carData] = $this->seasonScenario();
        $svc = $this->withPicker(null);

        $out = $svc->advise(
            $this->flaggedEngine(),
            $carData,
            $this->wearParts(),
            [],
            $track,
            $car,
            0,
            [],
            10_000_000,
            [],
            $this->aSeason(),
        );
        $plain = $this->svc->advise($this->flaggedEngine(), $carData, $this->wearParts(), [], $track, $car, 0, [], 10_000_000);

        $this->assertFalse($out['Engine']['season']);
        $this->assertSame('No season pick for this part: ranked for this race', $out['Engine']['season_reason']);
        $this->assertSame($plain['Engine']['options'], $out['Engine']['options']);
    }

    public function testEachLevelYieldsExactlyOneOptionAndAFreeSpareBeatsAPaidPart(): void
    {
        $track = ['power' => 10, 'handling' => 10, 'acceleration' => 10];
        $car   = ['power' => 90, 'handling' => 80, 'acceleration' => 85];

        // Two ways to reach L5: a free garage spare and a paid fresh part.
        // Both survive, so the money buys nothing and only the free one shows.
        $carData = $this->carData([
            ['level' => 5, 'wear' => 20, 'cost' => 0,       'action' => -1],
            ['level' => 5, 'wear' => 0,  'cost' => 700_000, 'action' => 5],
            ['level' => 6, 'wear' => 0,  'cost' => 900_000, 'action' => 6],
        ]);

        $out = $this->svc->advise(
            $this->flaggedEngine(),
            $carData,
            $this->wearParts(),
            [],
            $track,
            $car,
            0,
            [],
            10_000_000,
        );

        $options = $out['Engine']['options'];
        $atFive = array_filter($options, static fn(array $o): bool => $o['level'] === 5);
        $this->assertCount(1, $atFive);

        $l5 = $this->atLevel($options, 5);
        $this->assertTrue($l5['is_free']);
        $this->assertSame(0, $l5['cost']);
    }

    public function testAlignmentOutranksCostWhenChoosingTheRecommendation(): void
    {
        // Handling-hungry track. Engine adds power (+6) and acceleration (+2),
        // so dropping a level moves the car's balance towards the track — even
        // though it is by far the most expensive option here.
        $track = ['power' => 5, 'handling' => 15, 'acceleration' => 5];
        $car   = ['power' => 100, 'handling' => 90, 'acceleration' => 85];

        $carData = $this->carData([
            ['level' => 5, 'wear' => 0, 'cost' => 1_500_000, 'action' => 5],
            ['level' => 6, 'wear' => 0, 'cost' => 900_000,   'action' => 6],
            ['level' => 7, 'wear' => 0, 'cost' => 100_000,   'action' => 7],
        ]);

        $out = $this->svc->advise(
            $this->flaggedEngine(),
            $carData,
            $this->wearParts(),
            [],
            $track,
            $car,
            0,
            [],
            10_000_000,
        );

        $options = $out['Engine']['options'];
        $this->assertSame([5, 6, 7], $this->levels($options));
        $this->assertTrue($options[0]['recommended']);
        $this->assertSame(1_500_000, $options[0]['cost']);
        $this->assertGreaterThan(0.0, $options[0]['fit_delta']);
        $this->assertLessThan(0.0, $options[2]['fit_delta']);
    }

    public function testEveryOptionCarriesAPlainBalanceVerdictMatchingItsFitChange(): void
    {
        // Same handling-hungry track: one level down helps the balance, a fresh
        // part at the same level changes nothing, one level up hurts it. The UI
        // shows these words instead of a raw fit delta nobody can read.
        $track = ['power' => 5, 'handling' => 15, 'acceleration' => 5];
        $car   = ['power' => 100, 'handling' => 90, 'acceleration' => 85];
        $carData = $this->carData([
            ['level' => 5, 'wear' => 0, 'cost' => 1_500_000, 'action' => 5],
            ['level' => 6, 'wear' => 0, 'cost' => 900_000,   'action' => 6],
            ['level' => 7, 'wear' => 0, 'cost' => 100_000,   'action' => 7],
        ]);

        $out = $this->svc->advise($this->flaggedEngine(), $carData, $this->wearParts(), [], $track, $car, 0, [], 10_000_000);

        $byLevel = array_column($out['Engine']['options'], null, 'level');
        $this->assertSame('closer', $byLevel[5]['balance']);
        $this->assertSame('same', $byLevel[6]['balance']);
        $this->assertSame('further', $byLevel[7]['balance']);

        // The words follow the advisor's significance band, not the sign alone.
        foreach ($out['Engine']['options'] as $option) {
            $expected = match (true) {
                $option['fit_delta'] >= 0.25  => 'closer',
                $option['fit_delta'] <= -0.25 => 'further',
                default                       => 'same',
            };
            $this->assertSame($expected, $option['balance']);
        }
    }

    public function testTheRankedLeaderIsTheRecommendation(): void
    {
        $track = ['power' => 5, 'handling' => 15, 'acceleration' => 5];
        $car   = ['power' => 100, 'handling' => 90, 'acceleration' => 85];

        $carData = $this->carData([
            ['level' => 5, 'wear' => 0, 'cost' => 1_500_000, 'action' => 5],
            ['level' => 6, 'wear' => 0, 'cost' => 900_000,   'action' => 6],
        ]);

        $out = $this->svc->advise(
            $this->flaggedEngine(end: 104.0),
            $carData,
            $this->wearParts(end: 104.0),
            [],
            $track,
            $car,
            0,
            [],
            10_000_000,
        );

        $options = $out['Engine']['options'];
        $this->assertTrue($options[0]['recommended']);
        $this->assertSame('fit', $options[0]['recommend_reason']);
        $this->assertSame([false], array_column(array_slice($options, 1), 'recommended'));
    }

    public function testTheLeaderIsFlaggedAsAValueWinWhenItOnlyBeatsTheRestOnPrice(): void
    {
        // A Gearbox level is worth 3 P / 1 H / 5 A, which against this track
        // pulls the car's balance in two directions at once: L5, L6 and L7 all
        // land in the same alignment band, so the free spare wins on price
        // alone. Calling that "best fit" would claim a win it did not earn.
        $track = ['power' => 17, 'handling' => 12, 'acceleration' => 9];
        $car   = ['power' => 82, 'handling' => 84, 'acceleration' => 83];

        $options = [];
        foreach ([
            ['level' => 5, 'wear' => 20, 'cost' => 0,       'action' => -1],
            ['level' => 6, 'wear' => 0,  'cost' => 900_000, 'action' => 6],
        ] as $o) {
            $options[] = [
                'value'    => ['value' => $o['action'], 'cost' => $o['cost']],
                'newLvl'   => (string) $o['level'],
                'newWear'  => (string) $o['wear'],
                'disabled' => 'false',
                'text'     => '',
            ];
        }

        $out = $this->svc->advise(
            [['part' => 'Gearbox', 'level' => 6, 'start' => 95, 'est' => 5.0, 'end' => 100.0]],
            ['gearOptions' => $options],
            ['Gearbox' => [
                'level' => 6, 'start' => 95, 'est' => 5.0, 'end' => 100.0, 'track_base' => 5.0,
            ]],
            [],
            $track,
            $car,
            0,
            [],
            10_000_000,
        );

        $best = $out['Gearbox']['options'][0];
        $this->assertSame(5, $best['level']);
        $this->assertTrue($best['is_free']);
        $this->assertSame('value', $best['recommend_reason']);
    }

    public function testEveryOptionCarriesTheCarPhaItWouldProduce(): void
    {
        $track = ['power' => 10, 'handling' => 10, 'acceleration' => 10];
        $car   = ['power' => 90, 'handling' => 80, 'acceleration' => 85];

        $carData = $this->carData([
            ['level' => 7, 'wear' => 0, 'cost' => 1_500_000, 'action' => 7],
        ]);

        $out = $this->svc->advise(
            $this->flaggedEngine(),
            $carData,
            $this->wearParts(),
            [],
            $track,
            $car,
            0,
            [],
            10_000_000,
        );

        $this->assertSame(
            ['power' => 90.0, 'handling' => 80.0, 'acceleration' => 85.0],
            $out['Engine']['current']['pha'],
        );

        $up = $this->atLevel($out['Engine']['options'], 7);
        // Engine contributes P+6 / A+2 per level.
        $this->assertSame(['power' => 96.0, 'handling' => 80.0, 'acceleration' => 87.0], $up['pha']);
        $this->assertSame(['power' => 6.0, 'handling' => 0.0, 'acceleration' => 2.0], $up['pha_delta']);
    }

    public function testPlannedTrainingLapsRaiseEveryOptionsProjectedEndWear(): void
    {
        $track = ['power' => 10, 'handling' => 10, 'acceleration' => 10];
        $car   = ['power' => 90, 'handling' => 80, 'acceleration' => 85];

        $carData = $this->carData([
            ['level' => 5, 'wear' => 0, 'cost' => 700_000, 'action' => 5],
        ]);

        $noTraining = $this->svc->advise(
            $this->flaggedEngine(),
            $carData,
            $this->wearParts(),
            [],
            $track,
            $car,
            0,
            [],
            10_000_000,
        );
        $withTraining = $this->svc->advise(
            $this->flaggedEngine(),
            $carData,
            $this->wearParts(),
            [],
            $track,
            $car,
            0,
            [],
            10_000_000,
            ['Engine' => 12.5],
        );

        $before = $this->atLevel($noTraining['Engine']['options'], 5)['end'];
        $after  = $this->atLevel($withTraining['Engine']['options'], 5)['end'];

        // A fitted replacement runs the planned session too, so the training
        // wear lands on it at the same flat rate (testing wear is independent
        // of part level) as on the part it replaces.
        $this->assertEqualsWithDelta($before + 12.5, $after, 0.001);
    }

    public function testAnOptionThatCannotSurviveTrainingPlusRaceIsDropped(): void
    {
        $track = ['power' => 10, 'handling' => 10, 'acceleration' => 10];
        $car   = ['power' => 90, 'handling' => 80, 'acceleration' => 85];

        // Starts pre-worn: comfortably survivable on its own, doomed once a
        // long testing session is charged on top.
        $carData = $this->carData([
            ['level' => 5, 'wear' => 85, 'cost' => 700_000, 'action' => 5],
        ]);
        $args = fn(array $training): array => [
            $this->flaggedEngine(),
            $carData,
            $this->wearParts(),
            [],
            $track,
            $car,
            0,
            [],
            10_000_000,
            $training,
        ];

        $noTraining = $this->svc->advise(...$args([]));
        $withTraining = $this->svc->advise(...$args(['Engine' => 40.0]));

        $this->assertNotSame([], $noTraining['Engine']['options']);
        $this->assertSame([], $withTraining['Engine']['options']);
    }

    public function testTrainingWearDefaultsToNothingForPartsWithNoPlannedSession(): void
    {
        $track = ['power' => 10, 'handling' => 10, 'acceleration' => 10];
        $car   = ['power' => 90, 'handling' => 80, 'acceleration' => 85];

        $carData = $this->carData([
            ['level' => 5, 'wear' => 0, 'cost' => 700_000, 'action' => 5],
        ]);

        $omitted = $this->svc->advise(
            $this->flaggedEngine(),
            $carData,
            $this->wearParts(),
            [],
            $track,
            $car,
            0,
            [],
            10_000_000,
        );
        // A map that simply doesn't mention Engine must behave identically.
        $unrelated = $this->svc->advise(
            $this->flaggedEngine(),
            $carData,
            $this->wearParts(),
            [],
            $track,
            $car,
            0,
            [],
            10_000_000,
            ['Gearbox' => 30.0],
        );

        $this->assertSame(
            $this->atLevel($omitted['Engine']['options'], 5)['end'],
            $this->atLevel($unrelated['Engine']['options'], 5)['end'],
        );
    }

    public function testAFreeSpareThatCannotSurviveFallsBackToThePaidPartAtThatLevel(): void
    {
        $track = ['power' => 10, 'handling' => 10, 'acceleration' => 10];
        $car   = ['power' => 90, 'handling' => 80, 'acceleration' => 85];

        // Free L5 spare starts at 95 % pre-worn, won't survive a big track.
        $carData = $this->carData([
            ['level' => 5, 'wear' => 95, 'cost' => 0,       'action' => -1],
            ['level' => 5, 'wear' => 0,  'cost' => 700_000, 'action' => 5],
        ]);

        $out = $this->svc->advise(
            $this->flaggedEngine(),
            $carData,
            $this->wearParts(trackBase: 20.0),
            [],
            $track,
            $car,
            0,
            [],
            10_000_000,
        );

        $l5 = $this->atLevel($out['Engine']['options'], 5);
        $this->assertFalse($l5['is_free']);
        $this->assertSame(700_000, $l5['cost']);
    }

    public function testLevelsOutsideTheGroupOperatingBandAreDropped(): void
    {
        $track = ['power' => 10, 'handling' => 10, 'acceleration' => 10];
        $car   = ['power' => 90, 'handling' => 80, 'acceleration' => 85];

        // Group is 5..6, so the band is [4, 7]... but the flagged part sits at
        // L8, putting only L7 inside both the band and the ±1 window.
        $carData = $this->carData([
            ['level' => 7, 'wear' => 0, 'cost' => 1_500_000, 'action' => 7],
            ['level' => 8, 'wear' => 0, 'cost' => 1_800_000, 'action' => 8],
            ['level' => 9, 'wear' => 0, 'cost' => 2_500_000, 'action' => 9],
        ]);

        $out = $this->svc->advise(
            $this->flaggedEngine(level: 8),
            $carData,
            $this->wearParts(level: 8),
            [],
            $track,
            $car,
            0,
            [5, 5, 6, 6],
            10_000_000,
        );

        $this->assertSame([7], $this->levels($out['Engine']['options']));
    }

    public function testCashFilterDropsUnaffordablePaidOptionsButKeepsFreeSpares(): void
    {
        $track = ['power' => 11, 'handling' => 9, 'acceleration' => 10];
        $car   = ['power' => 11, 'handling' => 9, 'acceleration' => 10];

        $carData = $this->carData([
            ['level' => 5, 'wear' => 30, 'cost' => 0,         'action' => -1],
            ['level' => 6, 'wear' => 0,  'cost' => 9_000_000, 'action' => 6],
        ]);

        $out = $this->svc->advise(
            $this->flaggedEngine(),
            $carData,
            $this->wearParts(),
            [],
            $track,
            $car,
            0,
            [],
            500_000,
        );

        $this->assertSame([5], $this->levels($out['Engine']['options']));
        $this->assertTrue($out['Engine']['options'][0]['is_free']);
    }

    public function testWearFilterDropsOptionsThatWontSurvive(): void
    {
        $track = ['power' => 11, 'handling' => 9, 'acceleration' => 10];
        $car   = ['power' => 11, 'handling' => 9, 'acceleration' => 10];

        // The L1 fresh part starts at 70 %; the level-1 wear factor compounds
        // at risk 100 and pushes it past 100 %. L2 stays comfortably under.
        $carData = $this->carData([
            ['level' => 1, 'wear' => 70, 'cost' => 100_000, 'action' => 1],
            ['level' => 2, 'wear' => 0,  'cost' => 200_000, 'action' => 2],
        ]);

        $out = $this->svc->advise(
            $this->flaggedEngine(level: 2),
            $carData,
            $this->wearParts(trackBase: 8.0, level: 2),
            [],
            $track,
            $car,
            100,
            [],
            10_000_000,
        );

        $this->assertSame([2], $this->levels($out['Engine']['options']));
    }

    public function testEmptyOptionsWhenEverythingFiltersOut(): void
    {
        $track = ['power' => 11, 'handling' => 9, 'acceleration' => 10];
        $car   = ['power' => 11, 'handling' => 9, 'acceleration' => 10];

        $carData = $this->carData([
            ['level' => 6, 'wear' => 0, 'cost' => 9_000_000, 'action' => 6],
        ]);

        $out = $this->svc->advise(
            $this->flaggedEngine(),
            $carData,
            $this->wearParts(),
            [],
            $track,
            $car,
            0,
            [],
            100_000,
        );

        $this->assertSame([], $out['Engine']['options']);
    }

    public function testSummariseTotalsTheCostAndPhaShiftOfTheRecommendedPlan(): void
    {
        // Power-hungry track, so the recommendation on a must-replace part is
        // the level up — a real cost and a real PHA shift to total up.
        $track = ['power' => 15, 'handling' => 5, 'acceleration' => 5];
        $car   = ['power' => 100, 'handling' => 90, 'acceleration' => 85];

        $carData = $this->carData([
            ['level' => 6, 'wear' => 0, 'cost' => 900_000,   'action' => 6],
            ['level' => 7, 'wear' => 0, 'cost' => 1_500_000, 'action' => 7],
        ]);

        $advice = $this->svc->advise(
            $this->flaggedEngine(end: 104.0),
            $carData,
            $this->wearParts(end: 104.0),
            [],
            $track,
            $car,
            0,
            [],
            10_000_000,
        );

        $summary = $this->svc->summarise($advice, $track, $car);

        $this->assertSame(1_500_000, $summary['cost']);
        $this->assertSame(['power' => 100.0, 'handling' => 90.0, 'acceleration' => 85.0], $summary['before']['pha']);
        $this->assertSame(['power' => 106.0, 'handling' => 90.0, 'acceleration' => 87.0], $summary['after']['pha']);
        $this->assertGreaterThan($summary['before']['fit'], $summary['after']['fit']);
        $this->assertSame(
            ['power' => 15.0, 'handling' => 5.0, 'acceleration' => 5.0],
            $summary['track'],
        );
    }

    private function wear(): CarWearService
    {
        return new CarWearService(new PDO('sqlite::memory:'), [
            'driver_wear_factors' => ['concentration' => 1.0, 'talent' => 1.0, 'experience' => 1.0],
            'part_level_factors'  => [
                1 => 1.02, 2 => 1.018, 3 => 1.016, 4 => 1.014,
                5 => 1.012, 6 => 1.01, 7 => 1.008, 8 => 1.006, 9 => 1.004,
            ],
        ]);
    }

    /**
     * A picker that records what it is asked and answers each part from $picks (null when absent).
     *
     * @param ArrayObject<int, array{part: string, cash: int, candidates: list<array<string, mixed>>,
     *   paidPrice: array<int, ?int>}> $calls
     * @param array<string, array<string, mixed>> $picks
     */
    private function spyPicker(ArrayObject $calls, array $picks = []): SwapLevelPicker
    {
        return new readonly class ($this->wear(), $calls, $picks) extends SwapLevelPicker {
            /**
             * @param ArrayObject<int, array<string, mixed>> $calls
             * @param array<string, array<string, mixed>> $picks
             */
            public function __construct(CarWearService $wear, private ArrayObject $calls, private array $picks)
            {
                parent::__construct(new SeasonBudget($wear), []);
            }

            public function pick(SwapSeason $s, string $part, int $currentLevel, array $candidates, array $paidPrice): ?array
            {
                $this->calls->append([
                    'part' => $part, 'cash' => $s->cash, 'candidates' => $candidates, 'paidPrice' => $paidPrice,
                ]);

                /** @var ?array{level: int, kind: 'free_downgrade'|'downgrade'|'sidegrade'|'upgrade', cost: int,
                 *   season_pha: float, net_cost: int, affordable: bool,
                 *   reason: 'most_pha'|'top3_keep'|'top3_pays'|'budget_short'} $pick */
                $pick = $this->picks[$part] ?? null;

                return $pick;
            }
        };
    }

    private function advisorWith(SwapLevelPicker $picker): PartSwapAdvisorService
    {
        $pha = new PhaMatchService();

        return new PartSwapAdvisorService(
            $this->wear(),
            $pha,
            new PartUpgradeAdvisorService($pha, [
                'Engine'  => ['power' => 6, 'handling' => 0, 'acceleration' => 2],
                'Gearbox' => ['power' => 3, 'handling' => 1, 'acceleration' => 5],
            ]),
            $picker,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function rawOption(int $level, int $cost, int $action, int $wear = 0, array $overrides = []): array
    {
        return array_merge([
            'value'    => ['value' => $action, 'cost' => $cost],
            'newLvl'   => (string) $level,
            'newWear'  => (string) $wear,
            'disabled' => 'false',
            'text'     => '',
        ], $overrides);
    }

    public function testThePickerIsHandedTheSurvivorsAndTheCheapestPaidPricePerLevel(): void
    {
        $calls = new ArrayObject();
        $svc = $this->advisorWith($this->spyPicker($calls));
        $carData = ['engineOptions' => [
            $this->rawOption(5, 0, -1, wear: 98),
            $this->rawOption(5, 700_000, 5),
            $this->rawOption(6, 900_000, 6),
            $this->rawOption(6, 800_000, 6),
            $this->rawOption(6, 0, -1, wear: 20),
            $this->rawOption(7, 1_500_000, 7, overrides: ['disabled' => 'true']),
            $this->rawOption(7, 1_600_000, 7),
        ]];

        $svc->advise(
            $this->flaggedEngine(),
            $carData,
            $this->wearParts(),
            [],
            ['power' => 10, 'handling' => 10, 'acceleration' => 10],
            ['power' => 90, 'handling' => 80, 'acceleration' => 85],
            0,
            [],
            10_000_000,
            [],
            $this->aSeason(),
        );

        $this->assertCount(1, $calls);
        $candidates = array_column($calls[0]['candidates'], null, 'level');
        ksort($candidates);
        $this->assertSame([5, 6, 7], array_keys($candidates));
        $this->assertSame([700_000, false, 0.0], [$candidates[5]['cost'], $candidates[5]['is_free'], $candidates[5]['start']]);
        $this->assertSame([0, true, 20.0], [$candidates[6]['cost'], $candidates[6]['is_free'], $candidates[6]['start']]);
        $this->assertSame(1_600_000, $candidates[7]['cost']);
        $paid = $calls[0]['paidPrice'];
        ksort($paid);
        $this->assertSame([5 => 700_000, 6 => 800_000, 7 => 1_600_000], $paid);
    }

    public function testForcedPartsAreJudgedFirstAndEachPickIsCommittedBeforeTheNext(): void
    {
        $calls = new ArrayObject();
        $pick = static fn (int $net): array => [
            'level' => 7, 'kind' => 'upgrade', 'cost' => 1_500_000, 'season_pha' => 2.0, 'net_cost' => $net,
            'affordable' => true, 'reason' => 'most_pha',
        ];
        $svc = $this->advisorWith($this->spyPicker($calls, ['Engine' => $pick(400_000), 'Gearbox' => $pick(-50_000)]));
        $options = [$this->rawOption(6, 900_000, 6), $this->rawOption(7, 1_500_000, 7)];
        $flagged = [
            ['part' => 'Electronics', 'level' => 6, 'start' => 60, 'est' => 30.0, 'end' => 90.0, 'optional' => true],
            ['part' => 'Gearbox', 'level' => 6, 'start' => 95, 'est' => 5.0, 'end' => 100.0],
            ['part' => 'Engine', 'level' => 6, 'start' => 95, 'est' => 5.0, 'end' => 100.0],
        ];
        $wearParts = [];
        foreach (['Electronics', 'Gearbox', 'Engine'] as $label) {
            $wearParts[$label] = ['level' => 6, 'start' => 60, 'est' => 5.0, 'end' => 65.0, 'track_base' => 5.0];
        }

        $out = $svc->advise(
            $flagged,
            ['engineOptions' => $options, 'gearOptions' => $options, 'electronicsOptions' => $options],
            $wearParts,
            [],
            ['power' => 10, 'handling' => 10, 'acceleration' => 10],
            ['power' => 90, 'handling' => 80, 'acceleration' => 85],
            0,
            [],
            10_000_000,
            [],
            $this->aSeason(),
        );

        $this->assertSame(['Engine', 'Gearbox', 'Electronics'], array_column((array) $calls, 'part'));
        $this->assertSame([10_000_000, 9_600_000, 9_600_000], array_column((array) $calls, 'cash'));
        $this->assertSame(400_000, $out['Engine']['season_pick']['net_cost'] ?? null);
        $this->assertSame('most_pha', $out['Engine']['season_pick']['reason'] ?? null);
        $this->assertNull($out['Electronics']['season_pick']);
    }

    public function testForcedPartsAreJudgedMostWornFirstWhateverOrderTheCallerPasses(): void
    {
        // The Cockpit passes only the parts that break; the Engineer also passes the ones past a lower limit.
        // Judging the most worn first gives the breaking parts the same commitments on both screens.
        $options = [$this->rawOption(6, 900_000, 6), $this->rawOption(7, 1_500_000, 7)];
        $wearParts = [];
        foreach (['Brakes', 'Engine', 'Gearbox'] as $label) {
            $wearParts[$label] = ['level' => 6, 'start' => 60, 'est' => 5.0, 'end' => 65.0, 'track_base' => 5.0];
        }
        $order = function (array $flagged) use ($options, $wearParts): array {
            $calls = new ArrayObject();
            $this->advisorWith($this->spyPicker($calls))->advise(
                $flagged,
                ['engineOptions' => $options, 'gearOptions' => $options, 'brakesOptions' => $options],
                $wearParts,
                [],
                ['power' => 10, 'handling' => 10, 'acceleration' => 10],
                ['power' => 90, 'handling' => 80, 'acceleration' => 85],
                0,
                [],
                10_000_000,
                [],
                $this->aSeason(),
            );

            return array_column((array) $calls, 'part');
        };
        $brakes = ['part' => 'Brakes', 'level' => 6, 'start' => 60, 'est' => 32.0, 'end' => 92.0];
        $engine = ['part' => 'Engine', 'level' => 6, 'start' => 95, 'est' => 10.0, 'end' => 105.0];
        $gearbox = ['part' => 'Gearbox', 'level' => 6, 'start' => 95, 'est' => 10.0, 'end' => 105.0];

        $this->assertSame(['Engine', 'Gearbox', 'Brakes'], $order([$brakes, $gearbox, $engine]));
        $this->assertSame(['Engine', 'Gearbox', 'Brakes'], $order([$engine, $brakes, $gearbox]));
    }

    public function testTwoForcedUpgradesThatEachFitAloneButNotTogetherLeaveTheSecondAtItsLevel(): void
    {
        // Both parts break in race 8 and the plan replaces each for 900k. An L7 is 1.5M: each part's upgrade
        // nets 600k and fits on its own, but the budget leaves room for only one of them.
        $wear = $this->wear();
        $budget = new SeasonBudget($wear);
        $rounds = [
            ['race' => 8, 'track_name' => null, 'base' => ['Engine' => 10.0, 'Gearbox' => 10.0]],
            ['race' => 9, 'track_name' => null, 'base' => ['Engine' => 10.0, 'Gearbox' => 10.0]],
        ];
        $parts = ['Engine' => ['level' => 6, 'start' => 95], 'Gearbox' => ['level' => 6, 'start' => 95]];
        $prices = ['Engine' => 900_000, 'Gearbox' => 900_000];
        $rows = $budget->forecast($rounds, $parts, $prices, 1.0, 0, 100, 10_000_000, 0, 0)['rows'];
        $this->assertSame(1_800_000, $rows[0]['repairs']);
        $season = new SwapSeason(
            SeasonObjective::HOLD,
            ['v' => 1, 'ctr' => 0, 'mode' => 'nurse', 'safety' => 0, 'swaps' => [], 'refused_swap_cost' => null,
                'target' => 7_400_000, 'target_kind' => 'cushion', 'cushion' => 7_400_000, 'season_start' => null,
                'envelope' => 800_000, 'shortfall' => null, 'rows' => $rows, 'partial' => [], 'race' => 8],
            $rounds,
            [8 => ['power' => 10, 'handling' => 10, 'acceleration' => 10],
                9 => ['power' => 10, 'handling' => 10, 'acceleration' => 10]],
            $parts,
            $prices,
            1.0,
            10_000_000,
            0,
            0,
            new StrengthFloor(5, 40, 26),
        );
        $svc = $this->advisorWith(new SwapLevelPicker($budget, [
            'Engine'  => ['power' => 6, 'handling' => 0, 'acceleration' => 2],
            'Gearbox' => ['power' => 3, 'handling' => 1, 'acceleration' => 5],
        ]));
        $options = [$this->rawOption(6, 900_000, 6), $this->rawOption(7, 1_500_000, 7)];
        $wearParts = [];
        foreach (['Engine', 'Gearbox'] as $label) {
            $wearParts[$label] = ['level' => 6, 'start' => 95, 'est' => 10.0, 'end' => 105.0, 'track_base' => 5.0];
        }
        $advise = fn (array $flagged): array => $svc->advise(
            $flagged,
            ['engineOptions' => $options, 'gearOptions' => $options],
            $wearParts,
            [],
            ['power' => 10, 'handling' => 10, 'acceleration' => 10],
            ['power' => 90, 'handling' => 80, 'acceleration' => 85],
            0,
            [],
            10_000_000,
            [],
            $season,
        );
        $engine = ['part' => 'Engine', 'level' => 6, 'start' => 95, 'est' => 10.0, 'end' => 105.0];
        $gearbox = ['part' => 'Gearbox', 'level' => 6, 'start' => 95, 'est' => 10.0, 'end' => 105.0];

        $alone = $advise([$gearbox]);
        $this->assertSame(7, $alone['Gearbox']['season_pick']['level'] ?? null);

        $both = $advise([$engine, $gearbox]);
        $this->assertSame(7, $both['Engine']['season_pick']['level'] ?? null);
        $this->assertSame(600_000, $both['Engine']['season_pick']['net_cost'] ?? null);
        $this->assertSame(6, $both['Gearbox']['season_pick']['level'] ?? null);
        $this->assertSame(6, $both['Gearbox']['options'][0]['level']);
    }
}
