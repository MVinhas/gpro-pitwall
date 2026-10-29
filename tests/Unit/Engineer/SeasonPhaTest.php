<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\SeasonPha;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SeasonPha::class)]
final class SeasonPhaTest extends TestCase
{
    public function testGainMatchesTheRankersFormula(): void
    {
        $contribution = ['power' => 0, 'handling' => 2, 'acceleration' => 1];
        $rounds = [['race' => 8, 'track_name' => null, 'base' => null]];
        $demands = [8 => ['power' => 10, 'handling' => 5, 'acceleration' => 5]];

        // 2 × 5/20 + 1 × 5/20 per level.
        $this->assertSame(0.75, SeasonPha::gain($contribution, 1, $rounds, $demands));
        $this->assertSame(-0.75, SeasonPha::gain($contribution, -1, $rounds, $demands));
        $this->assertSame(0.0, SeasonPha::gain($contribution, 1, $rounds, [8 => null]));
    }
}
