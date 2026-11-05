<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\SmokeCost;
use App\Engineer\SmokeRisk;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SmokeCost::class)]
final class SmokeCostTest extends TestCase
{
    public function test_prices_lost_time_and_break_exposure(): void
    {
        $risk = SmokeRisk::for(80, 25, 60);
        $this->assertNotNull($risk);

        $this->assertSame(
            ['time' => 1_950_000, 'break' => 1_200_000, 'total' => 3_150_000],
            SmokeCost::price($risk, 0.04, 2.0, 150_000, 9_000_000),
        );
    }

    public function test_a_single_smoke_lap_has_no_break_exposure(): void
    {
        $risk = SmokeRisk::for(70, 29.1, 50);
        $this->assertNotNull($risk);

        $this->assertSame(0, SmokeCost::price($risk, 0.04, 2.0, 150_000, 9_000_000)['break']);
    }

    public function test_no_time_cost_without_a_seconds_per_place_scale(): void
    {
        $risk = SmokeRisk::for(80, 25, 60);
        $this->assertNotNull($risk);

        $this->assertSame(0, SmokeCost::price($risk, 0.04, 0.0, 150_000, 9_000_000)['time']);
    }
}
