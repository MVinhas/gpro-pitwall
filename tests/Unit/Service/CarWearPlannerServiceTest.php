<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\CarWearPlannerService;
use App\Service\CarWearService;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CarWearPlannerService::class)]
final class CarWearPlannerServiceTest extends TestCase
{
    /** @var array<string, mixed> */
    private const array SECRETS = [
        'driver_wear_factors' => [
            'concentration' => 1.0,
            'talent'        => 1.0,
            'experience'    => 1.0,
        ],
        'part_level_factors' => [
            1 => 1.05,
            9 => 1.0,
        ],
    ];

    private function planner(): CarWearPlannerService
    {
        return new CarWearPlannerService(
            new CarWearService(new PDO('sqlite::memory:'), self::SECRETS),
        );
    }

    /**
     * Every part wears `$each` per race except the ones named in `$overrides`.
     *
     * @param array<string, float> $overrides
     * @return array<string, float>
     */
    private static function base(float $each, array $overrides = []): array
    {
        $out = [];
        foreach (array_keys(CarWearService::PARTS_MAP) as $label) {
            $out[$label] = $overrides[$label] ?? $each;
        }
        return $out;
    }

    /**
     * @param array<string, array{level: int, wear: int}> $overrides
     * @return array<string, array{level: int, wear: int}>
     */
    private static function parts(int $level, int $wear, array $overrides = []): array
    {
        $out = [];
        foreach (array_keys(CarWearService::PARTS_MAP) as $label) {
            $out[$label] = $overrides[$label] ?? ['level' => $level, 'wear' => $wear];
        }
        return $out;
    }

    /**
     * @param list<int> $raceNumbers
     * @return list<array{race: int, track_id: int, track_name: ?string, power: ?int, handling: ?int, acceleration: ?int, is_current: bool}>
     */
    private static function season(array $raceNumbers): array
    {
        return array_map(static fn (int $n): array => [
            'race'         => $n,
            'track_id'     => $n,
            'track_name'   => 'Track ' . $n,
            'power'        => null,
            'handling'     => null,
            'acceleration' => null,
            'is_current'   => false,
        ], $raceNumbers);
    }

    public function testUpcomingTakesTheNextRaceAndTheFourAfterIt(): void
    {
        $rows = $this->planner()->upcoming(self::season(range(1, 17)), 6);

        $this->assertSame([6, 7, 8, 9, 10], array_column($rows, 'race'));
    }

    public function testUpcomingStopsAtTheEndOfTheSeason(): void
    {
        $rows = $this->planner()->upcoming(self::season(range(1, 17)), 15);

        $this->assertSame([15, 16, 17], array_column($rows, 'race'));
    }

    public function testUpcomingIsEmptyWhenTheNextRaceIsUnknown(): void
    {
        $this->assertSame([], $this->planner()->upcoming(self::season(range(1, 17)), 0));
    }

    public function testEachRaceAddsItsWearRoundedToAWholePercent(): void
    {
        // GPRO reports whole-percent wear after every race, so a planner that
        // carried fractions forward would drift a point off the game by the
        // third race. 18.49 + 17.43 + 13.2 rounds race by race to 18 + 17 + 13.
        $plan = $this->planner()->plan(
            [
                ['race' => 14, 'track_name' => 'A', 'ctr' => 0, 'base' => self::base(1.0, ['Gearbox' => 18.49])],
                ['race' => 15, 'track_name' => 'B', 'ctr' => 0, 'base' => self::base(1.0, ['Gearbox' => 17.43])],
                ['race' => 16, 'track_name' => 'C', 'ctr' => 0, 'base' => self::base(1.0, ['Gearbox' => 13.2])],
            ],
            self::parts(9, 0, ['Gearbox' => ['level' => 6, 'wear' => 24]]),
            1.0,
        );

        $this->assertSame([42, 59, 72], $plan['parts']['Gearbox']['ends']);
    }

    public function testClearTrackRiskAddsWearThroughThePartLevel(): void
    {
        // Level 1 factor 1.05; CTR 10 → 10 × 1.05^10 = 16.29 → 16.
        $plan = $this->planner()->plan(
            [['race' => 3, 'track_name' => 'A', 'ctr' => 10, 'base' => self::base(10.0)]],
            self::parts(1, 0),
            1.0,
        );

        $this->assertSame([16], $plan['parts']['Engine']['ends']);
    }

    public function testDriverFactorScalesEveryRace(): void
    {
        $plan = $this->planner()->plan(
            [['race' => 3, 'track_name' => 'A', 'ctr' => 0, 'base' => self::base(10.0)]],
            self::parts(9, 50),
            0.5,
        );

        $this->assertSame([55], $plan['parts']['Brakes']['ends']);
    }

    public function testAPartThatBreaksInTheNextRaceMustBeReplacedNow(): void
    {
        $plan = $this->planner()->plan(
            [
                ['race' => 14, 'track_name' => 'A', 'ctr' => 0, 'base' => self::base(5.0, ['Suspension' => 23.0])],
                ['race' => 15, 'track_name' => 'B', 'ctr' => 0, 'base' => self::base(5.0)],
            ],
            self::parts(9, 10, ['Suspension' => ['level' => 9, 'wear' => 79]]),
            1.0,
        );

        $susp = $plan['parts']['Suspension'];
        $this->assertSame(CarWearPlannerService::STATUS_NOW, $susp['status']);
        $this->assertSame(14, $susp['fails_at']);
        $this->assertSame('Replace now', $susp['verdict']);
    }

    public function testAPartThatBreaksLaterNamesTheRaceToReplaceItBefore(): void
    {
        $plan = $this->planner()->plan(
            [
                ['race' => 14, 'track_name' => 'A', 'ctr' => 0, 'base' => self::base(10.0)],
                ['race' => 15, 'track_name' => 'B', 'ctr' => 0, 'base' => self::base(10.0)],
                ['race' => 16, 'track_name' => 'Fuji', 'ctr' => 0, 'base' => self::base(10.0)],
            ],
            self::parts(9, 10, ['Brakes' => ['level' => 9, 'wear' => 75]]),
            1.0,
        );

        $brakes = $plan['parts']['Brakes'];
        $this->assertSame([85, 95, 105], $brakes['ends']);
        $this->assertSame(CarWearPlannerService::STATUS_LATER, $brakes['status']);
        $this->assertSame(16, $brakes['fails_at']);
        $this->assertSame('Replace before race 16', $brakes['verdict']);
    }

    public function testAPartThatLastsThePlanSaysHowFarItGoes(): void
    {
        $plan = $this->planner()->plan(
            [
                ['race' => 14, 'track_name' => 'A', 'ctr' => 0, 'base' => self::base(10.0)],
                ['race' => 15, 'track_name' => 'B', 'ctr' => 0, 'base' => self::base(10.0)],
            ],
            self::parts(9, 10),
            1.0,
        );

        $engine = $plan['parts']['Engine'];
        $this->assertSame(CarWearPlannerService::STATUS_OK, $engine['status']);
        $this->assertNull($engine['fails_at']);
        $this->assertSame('Lasts to race 15', $engine['verdict']);
    }

    public function testExactlyOneHundredPercentDoesNotFinish(): void
    {
        // WearAdvisorService::THRESHOLD_SWAP — a part at 100% has failed.
        $plan = $this->planner()->plan(
            [['race' => 5, 'track_name' => 'A', 'ctr' => 0, 'base' => self::base(10.0)]],
            self::parts(9, 90),
            1.0,
        );

        $this->assertSame(5, $plan['parts']['Chassis']['fails_at']);
    }

    public function testATrackWithoutWearDataStopsTheProjection(): void
    {
        // Adding zero for an unknown track would understate every later race,
        // so the cells from that race on are unknown instead.
        $plan = $this->planner()->plan(
            [
                ['race' => 14, 'track_name' => 'A', 'ctr' => 0, 'base' => self::base(10.0)],
                ['race' => 15, 'track_name' => 'Hanoi', 'ctr' => 0, 'base' => null],
                ['race' => 16, 'track_name' => 'C', 'ctr' => 0, 'base' => self::base(10.0)],
            ],
            self::parts(9, 10),
            1.0,
        );

        $this->assertSame([20, null, null], $plan['parts']['Engine']['ends']);
        $this->assertSame('Lasts to race 14', $plan['parts']['Engine']['verdict']);
        $this->assertSame(['Hanoi'], $plan['missing_tracks']);
        $this->assertFalse($plan['races'][1]['has_data']);
    }

    public function testHeadlineCountsPartsThatWontFinishTheNextRace(): void
    {
        $plan = $this->planner()->plan(
            [['race' => 14, 'track_name' => 'A', 'ctr' => 0, 'base' => self::base(10.0)]],
            self::parts(9, 10, [
                'Brakes'     => ['level' => 9, 'wear' => 95],
                'Suspension' => ['level' => 9, 'wear' => 92],
            ]),
            1.0,
        );

        $this->assertSame(CarWearPlannerService::STATUS_NOW, $plan['status']);
        $this->assertSame("2 parts won't finish the next race.", $plan['headline']);
    }

    public function testHeadlineNamesPartsThatWearOutLater(): void
    {
        $plan = $this->planner()->plan(
            [
                ['race' => 14, 'track_name' => 'A', 'ctr' => 0, 'base' => self::base(10.0)],
                ['race' => 15, 'track_name' => 'B', 'ctr' => 0, 'base' => self::base(10.0)],
            ],
            self::parts(9, 10, ['Brakes' => ['level' => 9, 'wear' => 85]]),
            1.0,
        );

        $this->assertSame(CarWearPlannerService::STATUS_LATER, $plan['status']);
        $this->assertSame('Every part finishes the next race; 1 wears out by race 15.', $plan['headline']);
    }

    public function testHeadlineWhenEverythingLasts(): void
    {
        $plan = $this->planner()->plan(
            [
                ['race' => 14, 'track_name' => 'A', 'ctr' => 0, 'base' => self::base(10.0)],
                ['race' => 15, 'track_name' => 'B', 'ctr' => 0, 'base' => self::base(10.0)],
            ],
            self::parts(9, 10),
            1.0,
        );

        $this->assertSame(CarWearPlannerService::STATUS_OK, $plan['status']);
        $this->assertSame('Every part lasts to race 15.', $plan['headline']);
    }

    public function testPartsFromCarDataReadsLevelsAndWear(): void
    {
        $parts = $this->planner()->partsFrom(
            ['lvlFWing' => 6, 'usaFWing' => 29, 'lvlBrakes' => '5', 'usaBrakes' => '53'],
            [],
            [],
        );

        $this->assertSame(['level' => 6, 'wear' => 29], $parts['Front Wing']);
        $this->assertSame(['level' => 5, 'wear' => 53], $parts['Brakes']);
        // A part the payload doesn't carry still gets a row.
        $this->assertSame(['level' => 1, 'wear' => 0], $parts['Engine']);
    }

    public function testPartsFromAppliesOverridesKeyedBySlugAndClampsThem(): void
    {
        $parts = $this->planner()->partsFrom(
            ['lvlFWing' => 6, 'usaFWing' => 29, 'lvlEngine' => 4, 'usaEngine' => 40],
            ['fwing' => '8', 'engine' => '42'],
            ['fwing' => '0', 'engine' => '-5', 'brakes' => 'abc'],
        );

        $this->assertSame(['level' => 8, 'wear' => 0], $parts['Front Wing']);
        // Levels run 1..9 and wear 0..99; junk falls back to the synced value.
        $this->assertSame(['level' => 9, 'wear' => 0], $parts['Engine']);
        $this->assertSame(0, $parts['Brakes']['wear']);
    }

    public function testEveryPartHasALowercaseSlug(): void
    {
        $slugs = CarWearPlannerService::slugs();

        $this->assertSame(array_keys(CarWearService::PARTS_MAP), array_keys($slugs));
        $this->assertSame('fwing', $slugs['Front Wing']);
        $this->assertSame('sidepod', $slugs['Sidepods']);
    }

    public function testSeasonGivesEachRoundsWearForThisDriverBeforeRisk(): void
    {
        $rows = $this->planner()->seasonWear(
            [
                ['race' => 1, 'track_name' => 'A', 'base' => self::base(10.0, ['Brakes' => 24.6])],
                ['race' => 2, 'track_name' => 'B', 'base' => self::base(8.0)],
                ['race' => 3, 'track_name' => 'Hanoi', 'base' => null],
            ],
            2,
            0.5,
        );

        $this->assertSame(5, $rows[0]['parts']['Engine']);
        $this->assertSame(12, $rows[0]['parts']['Brakes']);
        $this->assertSame('Brakes', $rows[0]['hardest']);
        // (10 × 5 + 12) / 11 = 5.6 → 6
        $this->assertSame(6, $rows[0]['overall']);
        $this->assertTrue($rows[0]['is_past']);
        $this->assertFalse($rows[0]['is_next']);

        $this->assertTrue($rows[1]['is_next']);
        $this->assertFalse($rows[1]['is_past']);

        $this->assertNull($rows[2]['parts']);
        $this->assertNull($rows[2]['overall']);
        $this->assertNull($rows[2]['hardest']);
    }

    public function testSeasonRatesEachRoundAgainstTheSeasonAverage(): void
    {
        // Overall 10, 13, 7 → season mean 10. Within 15% of it is average;
        // further out is heavy or light.
        $rows = $this->planner()->seasonWear(
            [
                ['race' => 1, 'track_name' => 'A', 'base' => self::base(10.0)],
                ['race' => 2, 'track_name' => 'B', 'base' => self::base(13.0)],
                ['race' => 3, 'track_name' => 'C', 'base' => self::base(7.0)],
                ['race' => 4, 'track_name' => 'Hanoi', 'base' => null],
            ],
            1,
            1.0,
        );

        $this->assertSame(
            [
                CarWearPlannerService::RATING_AVERAGE,
                CarWearPlannerService::RATING_HEAVY,
                CarWearPlannerService::RATING_LIGHT,
                null,
            ],
            array_column($rows, 'rating'),
        );
    }

    public function testPartPricesReadTheCostOfANewPartAtTheCurrentLevel(): void
    {
        $prices = $this->planner()->partPrices([
            'lvlEngine'     => 7,
            'engineOptions' => [
                ['value' => ['value' => 0, 'cost' => 0], 'newLvl' => '7', 'text' => "Don't replace"],
                ['value' => ['value' => 6, 'cost' => 9650191], 'newLvl' => '6'],
                ['value' => ['value' => 7, 'cost' => 11951761], 'newLvl' => '7'],
                // A downgrade to the same level is free and says nothing about price.
                ['value' => ['value' => -1, 'cost' => 0], 'newLvl' => '7'],
            ],
        ]);

        $this->assertSame(11951761, $prices['Engine']);
        // No options for a part: its price is unknown, not zero.
        $this->assertNull($prices['Brakes']);
    }

    public function testSeasonAdvisesPushingWhereTheWearLandsOnCheapParts(): void
    {
        // Engine costs ten times any other part. Round A wears the engine,
        // round B the brakes, round C everything alike. Level 1 parts, so
        // clear-track risk adds wear (factor 1.05 per point).
        $prices = array_fill_keys(array_keys(CarWearService::PARTS_MAP), 1_000_000);
        $prices['Engine'] = 10_000_000;

        $rows = $this->planner()->seasonWear(
            [
                ['race' => 1, 'track_name' => 'A', 'base' => self::base(5.0, ['Engine' => 20.0])],
                ['race' => 2, 'track_name' => 'B', 'base' => self::base(5.0, ['Brakes' => 20.0])],
                ['race' => 3, 'track_name' => 'C', 'base' => self::base(8.0)],
                ['race' => 4, 'track_name' => 'Hanoi', 'base' => null],
            ],
            1,
            1.0,
            self::parts(1, 0),
            $prices,
        );

        $this->assertSame(
            [
                CarWearPlannerService::ADVICE_HOLD,
                CarWearPlannerService::ADVICE_PUSH,
                CarWearPlannerService::ADVICE_NORMAL,
                null,
            ],
            array_column($rows, 'advice'),
        );
    }

    public function testSeasonGivesNoAdviceWithoutEveryPartsPrice(): void
    {
        $prices = array_fill_keys(array_keys(CarWearService::PARTS_MAP), 1_000_000);
        $prices['Engine'] = null;

        $rows = $this->planner()->seasonWear(
            [['race' => 1, 'track_name' => 'A', 'base' => self::base(5.0)]],
            1,
            1.0,
            self::parts(1, 0),
            $prices,
        );

        $this->assertNull($rows[0]['advice']);
    }
}
