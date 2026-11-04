<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\CtrValue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CtrValue::class)]
final class CtrValueTest extends TestCase
{
    public function test_near_tie_keeps_lower(): void
    {
        $r = CtrValue::choose(
            [0 => 0, 10 => 500_000, 20 => 1_000_000],
            [0 => 0, 10 => 450_000, 20 => 940_000],
            0,
            false,
        );

        $this->assertSame(0, $r['ctr']);
        $this->assertSame('value', $r['kind']);
        $this->assertSame(0, $r['gain']);
        $this->assertSame(0, $r['cost']);
        // CTR 20 nets $60k over CTR 0, CTR 10 only $50k: the next step quoted is the best one, still under the bar.
        $this->assertSame(
            ['ctr' => 20, 'gain_more' => 1_000_000, 'cost_more' => 940_000, 'net_more' => 60_000],
            $r['next'],
        );
    }

    public function test_clearly_better_wins(): void
    {
        $r = CtrValue::choose([0 => 0, 10 => 800_000], [0 => 0, 10 => 200_000], 0, false);

        $this->assertSame(10, $r['ctr']);
        $this->assertSame(800_000, $r['gain']);
        $this->assertSame(200_000, $r['cost']);
        $this->assertNull($r['next']);
    }

    public function test_walks_past_a_dip(): void
    {
        $r = CtrValue::choose(
            [0 => 0, 10 => 250_000, 20 => 700_000],
            [0 => 0, 10 => 200_000, 20 => 400_000],
            0,
            false,
        );

        $this->assertSame(20, $r['ctr']);
    }

    public function test_each_step_is_measured_against_the_pick_not_its_neighbour(): void
    {
        // CTR 10 is $80k over CTR 0 and CTR 20 $80k over CTR 10, but $160k over the pick, CTR 0: it clears the bar.
        $r = CtrValue::choose([0 => 0, 10 => 80_000, 20 => 160_000], [0 => 0, 10 => 0, 20 => 0], 0, false);

        $this->assertSame(20, $r['ctr']);
        $this->assertNull($r['next']);
    }

    public function test_a_step_exactly_on_the_bar_does_not_move_the_pick(): void
    {
        $r = CtrValue::choose([0 => 0, 10 => CtrValue::MARGIN], [0 => 0, 10 => 0], 0, false);

        $this->assertSame(0, $r['ctr']);
        $this->assertSame(
            ['ctr' => 10, 'gain_more' => CtrValue::MARGIN, 'cost_more' => 0, 'net_more' => CtrValue::MARGIN],
            $r['next'],
        );
    }

    public function test_a_step_after_a_move_is_measured_from_the_new_pick(): void
    {
        // CTR 10 clears the bar over CTR 0; CTR 20 is $200k over CTR 0 but only $50k over the new pick.
        $r = CtrValue::choose([0 => 0, 10 => 150_000, 20 => 200_000], [0 => 0, 10 => 0, 20 => 0], 0, false);

        $this->assertSame(10, $r['ctr']);
        $this->assertSame(['ctr' => 20, 'gain_more' => 50_000, 'cost_more' => 0, 'net_more' => 50_000], $r['next']);
    }

    public function test_safety_takes_highest(): void
    {
        $r = CtrValue::choose([0 => 0, 10 => 10_000, 20 => 20_000], [0 => 0, 10 => 900_000, 20 => 990_000], 0, true);

        $this->assertSame(20, $r['ctr']);
        $this->assertSame('safety', $r['kind']);
        $this->assertSame(20_000, $r['gain']);
        $this->assertSame(990_000, $r['cost']);
        $this->assertNull($r['next']);
    }

    public function test_all_cost_no_gain(): void
    {
        $r = CtrValue::choose([0 => 0, 10 => 0, 20 => 0], [0 => 0, 10 => 300_000, 20 => 900_000], 0, false);

        $this->assertSame(0, $r['ctr']);
    }

    public function test_repairs_measured_from_zero(): void
    {
        $r = CtrValue::choose([5 => 400_000, 6 => 450_000], [5 => 130_000, 6 => 160_000], 100_000, false);

        $this->assertSame(5, $r['ctr']);
        $this->assertSame(400_000, $r['gain']);
        $this->assertSame(30_000, $r['cost']);
        $this->assertSame(['ctr' => 6, 'gain_more' => 50_000, 'cost_more' => 30_000, 'net_more' => 20_000], $r['next']);
    }

    public function test_next_takes_the_lowest_of_tied_best_nets(): void
    {
        $r = CtrValue::choose(
            [0 => 0, 1 => 50_000, 2 => 90_000, 3 => 90_000, 4 => 120_000],
            [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 60_000],
            0,
            false,
        );

        $this->assertSame(0, $r['ctr']);
        $this->assertSame(['ctr' => 2, 'gain_more' => 90_000, 'cost_more' => 0, 'net_more' => 90_000], $r['next']);
    }

    public function test_next_can_net_a_loss(): void
    {
        $r = CtrValue::choose([0 => 0, 5 => 100_000, 6 => 120_000], [0 => 0, 5 => 400_000, 6 => 300_000], 0, false);

        $this->assertSame(0, $r['ctr']);
        $this->assertSame(['ctr' => 6, 'gain_more' => 120_000, 'cost_more' => 300_000, 'net_more' => -180_000], $r['next']);
    }

    public function test_empty_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CtrValue::choose([], [], 0, false);
    }
}
