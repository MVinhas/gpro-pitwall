<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\Sparkline;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Sparkline::class)]
final class SparklineTest extends TestCase
{
    public function testHigherValuesPlotHigher(): void
    {
        $this->assertSame('0,20 50,10 100,0', Sparkline::points([1, 2, 3], 100, 20));
    }

    public function testInvertedPutsTheLowestValueAtTheTop(): void
    {
        $this->assertSame('0,0 50,10 100,20', Sparkline::points([1, 2, 3], 100, 20, true));
    }

    public function testNullsAreSkipped(): void
    {
        $this->assertSame('0,20 100,0', Sparkline::points([1, null, 3], 100, 20));
    }

    public function testFewerThanTwoValuesGiveNothing(): void
    {
        $this->assertSame('', Sparkline::points([5], 100, 20));
        $this->assertSame('', Sparkline::points([null, 5, null], 100, 20));
        $this->assertSame('', Sparkline::points([], 100, 20));
    }

    public function testEqualValuesDrawAFlatLineAtMidHeight(): void
    {
        $this->assertSame('0,10 50,10 100,10', Sparkline::points([4, 4, 4], 100, 20));
    }

    public function testCoordinatesAreRoundedToOneDecimal(): void
    {
        $this->assertSame('0,20 33.3,13.3 66.7,6.7 100,0', Sparkline::points([0, 1, 2, 3], 100, 20));
    }
}
