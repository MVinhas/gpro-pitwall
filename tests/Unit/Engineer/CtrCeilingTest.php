<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\CtrCeiling;
use App\Service\CarWearService;
use App\Service\WearAdvisorService;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CtrCeiling::class)]
final class CtrCeilingTest extends TestCase
{
    private CarWearService $wear;
    private CtrCeiling $ceiling;

    protected function setUp(): void
    {
        $this->wear = new CarWearService(new PDO('sqlite::memory:'), [
            'driver_wear_factors' => [],
            'part_level_factors' => [1 => 1.02, 2 => 1.03],
        ]);
        $this->ceiling = new CtrCeiling($this->wear);
    }

    /**
     * @param array<string, array<string, mixed>> $parts
     */
    private function survives(array $parts, int $ctr, int $limit): bool
    {
        foreach ($parts as $part) {
            $end = $part['start'] + ($part['training'] ?? 0)
                + round($this->wear->raceWear($part['track_base'], $part['level'], 1.0, $ctr), 1);
            if ($end >= $limit) {
                return false;
            }
        }

        return true;
    }

    public function testCeilingIsTheLastSafeValue(): void
    {
        $parts = [
            'Chassis' => ['level' => 1, 'start' => 10.0, 'track_base' => 20.0],
            'Engine' => ['level' => 2, 'start' => 30.0, 'track_base' => 8.0],
        ];

        $c = $this->ceiling->for($parts, 1.0, WearAdvisorService::THRESHOLD_SWAP);

        $this->assertGreaterThan(0, $c);
        $this->assertLessThan(100, $c);
        $this->assertTrue($this->survives($parts, $c, 100));
        $this->assertFalse($this->survives($parts, $c + 1, 100));
    }

    public function testNothingBreaksAtAnyRiskReturns100(): void
    {
        $parts = ['Chassis' => ['level' => 1, 'start' => 5.0, 'track_base' => 1.0]];

        $this->assertSame(100, $this->ceiling->for($parts, 1.0, 100));
    }

    public function testNoPartsReturns100(): void
    {
        $this->assertSame(100, $this->ceiling->for([], 1.0, 100));
    }

    public function testAFreshPartThatStillReachesTheLimitReturnsZero(): void
    {
        $parts = ['Chassis' => ['level' => 1, 'start' => 10.0, 'track_base' => 5.0, 'training' => 100.0]];

        $this->assertSame(0, $this->ceiling->for($parts, 1.0, 100));
    }

    public function testAPartPastTheLimitAtZeroCountsAsFresh(): void
    {
        $worn = ['Chassis' => ['level' => 1, 'start' => 99.0, 'track_base' => 5.0]];
        $fresh = ['Chassis' => ['level' => 1, 'start' => 0.0, 'track_base' => 5.0]];

        $this->assertGreaterThan(0, $this->ceiling->for($worn, 1.0, 100));
        $this->assertSame($this->ceiling->for($fresh, 1.0, 100), $this->ceiling->for($worn, 1.0, 100));
    }

    public function testASwappedPartStillCarriesItsTrainingWear(): void
    {
        $worn = ['Chassis' => ['level' => 1, 'start' => 99.0, 'track_base' => 20.0, 'training' => 40.0]];
        $fresh = ['Chassis' => ['level' => 1, 'start' => 0.0, 'track_base' => 20.0, 'training' => 40.0]];
        $untrained = ['Chassis' => ['level' => 1, 'start' => 99.0, 'track_base' => 20.0]];

        $this->assertSame($this->ceiling->for($fresh, 1.0, 100), $this->ceiling->for($worn, 1.0, 100));
        $this->assertLessThan($this->ceiling->for($untrained, 1.0, 100), $this->ceiling->for($worn, 1.0, 100));
    }

    public function testRiskyLimitIsLowerThanSwapLimit(): void
    {
        $parts = ['Chassis' => ['level' => 1, 'start' => 10.0, 'track_base' => 20.0]];

        $swap = $this->ceiling->for($parts, 1.0, WearAdvisorService::THRESHOLD_SWAP);
        $risky = $this->ceiling->for($parts, 1.0, WearAdvisorService::THRESHOLD_RISKY);

        $this->assertLessThan($swap, $risky);
        $this->assertTrue($this->survives($parts, $risky, 90));
        $this->assertFalse($this->survives($parts, $risky + 1, 90));
    }

    public function testTrainingWearCountsTowardsTheCeiling(): void
    {
        $plain = ['Chassis' => ['level' => 1, 'start' => 10.0, 'track_base' => 20.0]];
        $trained = ['Chassis' => ['level' => 1, 'start' => 10.0, 'track_base' => 20.0, 'training' => 15.0]];

        $without = $this->ceiling->for($plain, 1.0, 100);
        $with = $this->ceiling->for($trained, 1.0, 100);

        $this->assertLessThan($without, $with);
        $this->assertTrue($this->survives($trained, $with, 100));
        $this->assertFalse($this->survives($trained, $with + 1, 100));
    }

    public function testDriverFactorScalesTheWear(): void
    {
        $parts = ['Chassis' => ['level' => 1, 'start' => 10.0, 'track_base' => 20.0]];

        $this->assertLessThan(
            $this->ceiling->for($parts, 1.0, 100),
            $this->ceiling->for($parts, 1.3, 100),
        );
    }

    public function testPastLimitAtZeroNamesTheStuckParts(): void
    {
        $parts = [
            'Engine' => ['level' => 1, 'start' => 95.0, 'track_base' => 6.0],
            'Gearbox' => ['level' => 1, 'start' => 10.0, 'track_base' => 6.0],
            'Brakes' => ['level' => 1, 'start' => 85.0, 'track_base' => 6.0, 'training' => 3.0],
        ];

        $this->assertSame(['Engine', 'Brakes'], $this->ceiling->pastLimitAtZero($parts, 1.0, 90));
        $this->assertSame([], $this->ceiling->pastLimitAtZero($parts, 1.0, 200));
    }
}
