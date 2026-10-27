<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\SeasonBudget;
use App\Service\CarWearService;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SeasonBudget::class)]
final class SeasonBudgetTest extends TestCase
{
    private CarWearService $wear;
    private SeasonBudget $budget;

    protected function setUp(): void
    {
        $this->wear = new CarWearService(new PDO('sqlite::memory:'), [
            'driver_wear_factors' => [],
            'part_level_factors' => [1 => 1.02, 2 => 1.03],
        ]);
        $this->budget = new SeasonBudget($this->wear);
    }

    /**
     * @return array{race: int, track_name: ?string, base: ?array<string, float>}
     */
    private function round(int $race, ?float $engine, ?string $name = null): array
    {
        return ['race' => $race, 'track_name' => $name, 'base' => $engine === null ? null : ['Engine' => $engine]];
    }

    public function testBalanceFollowsRunningIncomeAndRepairs(): void
    {
        $out = $this->budget->forecast(
            [$this->round(5, 2.0), $this->round(6, 2.0)],
            ['Engine' => ['level' => 1, 'start' => 10]],
            ['Engine' => 180_000],
            1.0,
            50,
            100,
            1_000_000,
            300_000,
            200_000,
        );

        $this->assertSame([900_000, 800_000], array_column($out['rows'], 'balance'));
        $this->assertSame([5, 6], array_column($out['rows'], 'race'));
        $this->assertSame([300_000, 300_000], array_column($out['rows'], 'running'));
        $this->assertSame([200_000, 200_000], array_column($out['rows'], 'income'));
        $this->assertSame([0, 0], array_column($out['rows'], 'repairs'));
        $this->assertSame([], $out['partial']);
    }

    public function testPartCrossingTheLimitIsReplacedBeforeTheRaceAndCharged(): void
    {
        $perRace = round($this->wear->raceWear(10.0, 1, 1.0, 0), 1);
        $this->assertGreaterThan(5.0, $perRace);
        $this->assertLessThan(60.0, $perRace);

        $out = $this->budget->forecast(
            [$this->round(1, 10.0, 'A'), $this->round(2, 10.0, 'B')],
            ['Engine' => ['level' => 1, 'start' => 95]],
            ['Engine' => 180_000],
            1.0,
            0,
            100,
            1_000_000,
            0,
            0,
        );

        $this->assertSame([['part' => 'Engine', 'cost' => 180_000]], $out['rows'][0]['replaced']);
        $this->assertSame(180_000, $out['rows'][0]['repairs']);
        $this->assertSame(820_000, $out['rows'][0]['balance']);
        $this->assertSame([], $out['rows'][1]['replaced']);
        $this->assertSame(0, $out['rows'][1]['repairs']);
        $this->assertSame(820_000, $out['rows'][1]['balance']);
    }

    public function testReplacedPartWearsOutAgainLater(): void
    {
        $out = $this->budget->forecast(
            array_map(fn (int $r): array => $this->round($r, 40.0), [1, 2, 3, 4]),
            ['Engine' => ['level' => 1, 'start' => 0]],
            ['Engine' => 100],
            1.0,
            0,
            100,
            1_000,
            0,
            0,
        );

        $this->assertSame([0, 0, 100, 0], array_column($out['rows'], 'repairs'));
    }

    public function testTrainingWearCountsOnlyInTheFirstRound(): void
    {
        $rounds = [$this->round(1, 10.0), $this->round(2, 10.0)];
        $perRace = round($this->wear->raceWear(10.0, 1, 1.0, 0), 1);
        $start = 100 - 2 * $perRace - 1;

        $without = $this->budget->forecast(
            $rounds,
            ['Engine' => ['level' => 1, 'start' => $start]],
            ['Engine' => 50],
            1.0,
            0,
            100,
            1_000,
            0,
            0,
        );
        $this->assertSame([0, 0], array_column($without['rows'], 'repairs'));

        $with = $this->budget->forecast(
            $rounds,
            ['Engine' => ['level' => 1, 'start' => $start, 'training' => 2]],
            ['Engine' => 50],
            1.0,
            0,
            100,
            1_000,
            0,
            0,
        );
        $this->assertSame([0, 50], array_column($with['rows'], 'repairs'));

        $threeRounds = [$this->round(1, 10.0), $this->round(2, 10.0), $this->round(3, 10.0)];
        $firstOnly = $this->budget->forecast(
            $threeRounds,
            ['Engine' => ['level' => 1, 'start' => 100 - 10 - 3 * $perRace - 1, 'training' => 10]],
            ['Engine' => 50],
            1.0,
            0,
            100,
            1_000,
            0,
            0,
        );
        $this->assertSame([0, 0, 0], array_column($firstOnly['rows'], 'repairs'));
    }

    public function testMissingPriceChargesZeroAndMarksPartial(): void
    {
        $out = $this->budget->forecast(
            [$this->round(1, 10.0), $this->round(2, 10.0)],
            ['Engine' => ['level' => 1, 'start' => 99]],
            ['Engine' => null],
            1.0,
            0,
            100,
            1_000,
            0,
            0,
        );

        $this->assertSame([['part' => 'Engine', 'cost' => 0]], $out['rows'][0]['replaced']);
        $this->assertSame(0, $out['rows'][0]['repairs']);
        $this->assertSame(['Engine price unknown'], $out['partial']);
    }

    public function testMissingPriceIsReportedOnce(): void
    {
        $out = $this->budget->forecast(
            array_map(fn (int $r): array => $this->round($r, 40.0), [1, 2, 3, 4]),
            ['Engine' => ['level' => 1, 'start' => 0]],
            [],
            1.0,
            0,
            100,
            1_000,
            0,
            0,
        );

        $this->assertSame(['Engine price unknown'], $out['partial']);
    }

    public function testUnknownTrackRowIsPartialWithNoRepairs(): void
    {
        $out = $this->budget->forecast(
            [$this->round(1, null, 'Nowhere'), $this->round(2, null)],
            ['Engine' => ['level' => 1, 'start' => 99]],
            ['Engine' => 180_000],
            1.0,
            0,
            100,
            1_000_000,
            100_000,
            0,
        );

        $this->assertFalse($out['rows'][0]['wear_known']);
        $this->assertSame(0, $out['rows'][0]['repairs']);
        $this->assertSame([], $out['rows'][0]['replaced']);
        $this->assertSame('Nowhere', $out['rows'][0]['track_name']);
        $this->assertSame(900_000, $out['rows'][0]['balance']);
        $this->assertSame(['Nowhere wear unknown', 'race 2 wear unknown'], $out['partial']);
    }

    public function testRoundMissingAPartBaseIsTreatedAsUnknown(): void
    {
        $out = $this->budget->forecast(
            [
                ['race' => 1, 'track_name' => 'Gap', 'base' => ['Chassis' => 5.0]],
                ['race' => 2, 'track_name' => null, 'base' => ['Chassis' => 5.0]],
            ],
            ['Engine' => ['level' => 1, 'start' => 99]],
            ['Engine' => 180_000],
            1.0,
            0,
            100,
            1_000_000,
            0,
            0,
        );

        $this->assertFalse($out['rows'][0]['wear_known']);
        $this->assertSame(0, $out['rows'][0]['repairs']);
        $this->assertSame([], $out['rows'][0]['replaced']);
        $this->assertSame(['Gap wear unknown', 'race 2 wear unknown'], $out['partial']);
    }

    public function testUnknownRowLeavesPartWearForTheNextRound(): void
    {
        $out = $this->budget->forecast(
            [$this->round(1, null), $this->round(2, 10.0)],
            ['Engine' => ['level' => 1, 'start' => 99]],
            ['Engine' => 180_000],
            1.0,
            0,
            100,
            1_000_000,
            0,
            0,
        );

        $this->assertTrue($out['rows'][1]['wear_known']);
        $this->assertSame(180_000, $out['rows'][1]['repairs']);
    }

    public function testHigherCtrCostsMoreRepairs(): void
    {
        $repairsAt = function (int $ctr): int {
            $out = $this->budget->forecast(
                array_map(fn (int $r): array => $this->round($r, 8.0), [1, 2, 3, 4, 5, 6]),
                ['Engine' => ['level' => 2, 'start' => 0]],
                ['Engine' => 100],
                1.0,
                $ctr,
                100,
                0,
                0,
                0,
            );
            return array_sum(array_column($out['rows'], 'repairs'));
        };

        $this->assertSame(0, $repairsAt(20));
        $this->assertGreaterThan($repairsAt(20), $repairsAt(80));
    }

    public function testAFreshPartReachingTheLimitInOneRaceMakesTheForecastInfeasible(): void
    {
        $at = fn (int $ctr): array => $this->budget->forecast(
            [$this->round(1, 15.0), $this->round(2, 15.0)],
            ['Engine' => ['level' => 1, 'start' => 0]],
            ['Engine' => 100],
            1.0,
            $ctr,
            100,
            1_000_000,
            0,
            0,
        );

        $this->assertGreaterThanOrEqual(100.0, round($this->wear->raceWear(15.0, 1, 1.0, 100), 1));
        $this->assertTrue($at(100)['infeasible']);
        $this->assertFalse($at(50)['infeasible']);
    }
}
