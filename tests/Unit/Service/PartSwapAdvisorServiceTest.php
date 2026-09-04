<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\CarWearService;
use App\Service\PartSwapAdvisorService;
use App\Service\PartUpgradeAdvisorService;
use App\Service\PhaMatchService;
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

    public function testOnlyOneLevelEitherSideOfTheCurrentLevelIsOffered(): void
    {
        $track = ['power' => 10, 'handling' => 10, 'acceleration' => 10];
        $car   = ['power' => 90, 'handling' => 80, 'acceleration' => 85];

        $carData = $this->carData([
            ['level' => 3, 'wear' => 0, 'cost' => 200_000,   'action' => 3],
            ['level' => 5, 'wear' => 0, 'cost' => 700_000,   'action' => 5],
            ['level' => 6, 'wear' => 0, 'cost' => 900_000,   'action' => 6],
            ['level' => 7, 'wear' => 0, 'cost' => 1_500_000, 'action' => 7],
            ['level' => 9, 'wear' => 0, 'cost' => 2_500_000, 'action' => 9],
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

        $levels = $this->levels($out['Engine']['options']);
        sort($levels);
        $this->assertSame([5, 6, 7], $levels);
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
}
