<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\PlaceGap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlaceGap::class)]
final class PlaceGapTest extends TestCase
{
    public function test_median_of_gaps(): void
    {
        $gap = PlaceGap::fromLapGaps(['Amateur' => array_map('floatval', range(1, 20))]);

        $this->assertEqualsWithDelta(10.5, $gap->secondsPerPlace('Amateur', 1), 1e-9);
    }

    public function test_fallback_below_min_pairs(): void
    {
        $gap = PlaceGap::fromLapGaps(['Amateur' => array_map('floatval', range(1, 19))]);

        $this->assertEqualsWithDelta(9.5, $gap->secondsPerPlace('Amateur', 50), 1e-9);
    }

    public function test_fallback_instance_matches_empty_corpus(): void
    {
        $this->assertEqualsWithDelta(0.36 * 10, PlaceGap::fallback()->secondsPerPlace('Rookie', 10), 1e-9);
        $this->assertEqualsWithDelta(0.10 * 10, PlaceGap::fallback()->secondsPerPlace('Elite', 10), 1e-9);
    }

    public function test_laps_scale(): void
    {
        $gap = PlaceGap::fallback();

        $this->assertEqualsWithDelta(
            $gap->secondsPerPlace('Rookie', 30) * 2,
            $gap->secondsPerPlace('Rookie', 60),
            1e-9
        );
    }

    public function test_unknown_division_gap(): void
    {
        $this->assertEqualsWithDelta(0.10 * 40, PlaceGap::fallback()->secondsPerPlace(null, 40), 1e-9);
    }

    public function test_array_round_trip_keeps_the_medians(): void
    {
        $gap = PlaceGap::fromLapGaps(['Amateur' => array_map('floatval', range(1, 20))]);
        $copy = PlaceGap::fromArray($gap->toArray());

        $this->assertSame($gap->toArray(), $copy->toArray());
        $this->assertEqualsWithDelta(10.5 * 3, $copy->secondsPerPlace('Amateur', 3), 1e-9);
        $this->assertEqualsWithDelta(0.36 * 10, $copy->secondsPerPlace('Rookie', 10), 1e-9);
    }

    public function test_array_round_trip_of_the_fallback_is_empty(): void
    {
        $this->assertSame([], PlaceGap::fallback()->toArray());
        $this->assertEqualsWithDelta(
            PlaceGap::fallback()->secondsPerPlace('Pro', 20),
            PlaceGap::fromArray([])->secondsPerPlace('Pro', 20),
            1e-9
        );
    }
}
