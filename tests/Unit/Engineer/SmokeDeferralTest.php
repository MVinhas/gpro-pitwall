<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\SeasonBudget;
use App\Engineer\SmokeDeferral;
use App\Service\CarWearService;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SmokeDeferral::class)]
final class SmokeDeferralTest extends TestCase
{
    private SeasonBudget $budget;

    protected function setUp(): void
    {
        $this->budget = new SeasonBudget(new CarWearService(new PDO('sqlite::memory:'), [
            'driver_wear_factors' => [],
            'part_level_factors' => [1 => 1.02, 2 => 1.03],
        ]));
    }

    /**
     * @return list<array{race: int, track_name: ?string, base: ?array<string, float>}>
     */
    private function rounds(int $count, ?float $engine = 40.0): array
    {
        $rounds = [];
        for ($race = 1; $race <= $count; $race++) {
            $rounds[] = ['race' => $race, 'track_name' => null, 'base' => $engine === null ? null : ['Engine' => $engine]];
        }

        return $rounds;
    }

    private function saving(int $rounds, ?int $price = 1_000_000, ?float $engine = 40.0): ?int
    {
        return SmokeDeferral::saving(
            $this->budget,
            $this->rounds($rounds, $engine),
            'Engine',
            ['level' => 1, 'start' => 70],
            $price,
            1.0,
            0,
        );
    }

    public function testDeferringSavesAPurchase(): void
    {
        $this->assertSame(1_000_000, $this->saving(3));
    }

    public function testDeferringSavesNothingWhenBothBuyOnce(): void
    {
        $this->assertSame(0, $this->saving(2));
    }

    public function testLastRaceSavesTheWholePrice(): void
    {
        $this->assertSame(1_000_000, $this->saving(1));
    }

    public function testUnknownPriceIsNull(): void
    {
        $this->assertNull($this->saving(3, null));
    }

    public function testAnUnknownWearRoundChargesNothingInBothRuns(): void
    {
        $this->assertSame(0, $this->saving(3, 1_000_000, null));

        $rounds = $this->rounds(3);
        $rounds[] = ['race' => 4, 'track_name' => 'Hanoi', 'base' => null];

        $this->assertSame(1_000_000, SmokeDeferral::saving(
            $this->budget,
            $rounds,
            'Engine',
            ['level' => 1, 'start' => 70],
            1_000_000,
            1.0,
            0,
        ));
    }
}
