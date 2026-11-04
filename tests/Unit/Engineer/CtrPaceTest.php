<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\CtrPace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CtrPace::class)]
final class CtrPaceTest extends TestCase
{
    public function test_seconds_per_point_per_lap(): void
    {
        $this->assertEqualsWithDelta(0.08, CtrPace::secondsPerPointPerLap(4.0, 180.0, 0.001), 1e-9);
    }

    public function test_no_average_speed_means_no_gain(): void
    {
        $this->assertSame(0.0, CtrPace::secondsPerPointPerLap(4.0, 0.0, 0.001));
    }
}
