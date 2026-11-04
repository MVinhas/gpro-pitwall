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
}
