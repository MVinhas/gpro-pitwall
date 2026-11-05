<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\PrizeTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PrizeTable::class)]
final class PrizeTableTest extends TestCase
{
    public function test_per_place_by_division(): void
    {
        $this->assertSame(282_051, PrizeTable::perPlace('Elite'));
        $this->assertSame(230_769, PrizeTable::perPlace('Master'));
        $this->assertSame(192_308, PrizeTable::perPlace('Pro'));
        $this->assertSame(153_846, PrizeTable::perPlace('Amateur'));
        $this->assertSame(102_564, PrizeTable::perPlace('Rookie'));
        $this->assertSame(0, PrizeTable::perPlace(null));
        $this->assertSame(0, PrizeTable::perPlace('Nowhere'));
    }

    public function test_at_falls_linearly_and_clamps_the_position(): void
    {
        $this->assertSame(12_000_000, PrizeTable::at('Amateur', 1));
        $this->assertSame(6_000_000, PrizeTable::at('Amateur', 40));
        $this->assertSame(9_076_923, PrizeTable::at('Amateur', 20));
        $this->assertSame(12_000_000, PrizeTable::at('Amateur', 0));
        $this->assertSame(6_000_000, PrizeTable::at('Amateur', 45));
        $this->assertSame(0, PrizeTable::at(null, 5));
        $this->assertSame(0, PrizeTable::at('Nowhere', 5));
    }
}
