<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\EnergyModel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnergyModel::class)]
final class EnergyModelTest extends TestCase
{
    private const array STAMINA = [20, 50, 80];

    /**
     * Even CTR only, so 50 * (a + b*ctr + s*stamina) is a whole number and the rows carry no rounding noise.
     *
     * @return list<array{ctr: int, from: int, to: int, laps: int, stamina: int}>
     */
    private function exactRows(int $count = 40): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $ctr = 2 * ($i % 20);
            $stamina = self::STAMINA[$i % 3];
            $drain = (int) round(50 * (0.1 + 0.03 * $ctr - 0.002 * $stamina));
            $rows[] = ['ctr' => $ctr, 'from' => 100, 'to' => 100 - $drain, 'laps' => 50, 'stamina' => $stamina];
        }

        return $rows;
    }

    /**
     * @return list<array{q1_end: int, q2_end: int, race_from: int}>
     */
    private function qualiRows(float $r0, float $r1, int $q1Drain, int $count = 30): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $q2End = 20 + 10 * ($i % 8);
            $rows[] = ['q1_end' => $q2End + $q1Drain, 'q2_end' => $q2End, 'race_from' => (int) round($r0 + $r1 * $q2End)];
        }

        return $rows;
    }

    public function test_fit_recovers_drain_coefficients(): void
    {
        $model = EnergyModel::fit($this->exactRows(), []);

        $result = $model->cap(100, true, 50, 50, 'Amateur');

        $this->assertSame(60, $result['cap']);
        $this->assertFalse($result['estimate']);
        $this->assertSame(100, $result['start']);
    }

    public function test_cap_respects_margin(): void
    {
        $model = EnergyModel::fit($this->exactRows(), []);

        $cap = $model->cap(100, true, 70, 50, 'Amateur')['cap'];
        $left = static fn (int $c): float => 100 - 70 * (0.1 + 0.03 * $c - 0.002 * 50);

        $this->assertGreaterThanOrEqual(EnergyModel::ENERGY_MARGIN, $left($cap));
        $this->assertLessThan(EnergyModel::ENERGY_MARGIN, $left($cap + 1));
    }

    public function test_cap_is_zero_when_nothing_qualifies(): void
    {
        $model = EnergyModel::fit($this->exactRows(), []);

        $result = $model->cap(100, true, 2000, 20, 'Amateur');

        $this->assertSame(0, $result['cap']);
        $this->assertFalse($result['estimate']);
    }

    public function test_hard_max(): void
    {
        $rows = [];
        for ($i = 0; $i < 40; $i++) {
            $ctr = 10 * ($i % 4);
            $stamina = self::STAMINA[$i % 3];
            $rows[] = ['ctr' => $ctr, 'from' => 100, 'to' => 100 - (int) round(1000 * (0.01 + 0.0001 * $ctr)), 'laps' => 1000, 'stamina' => $stamina];
        }

        $result = EnergyModel::fit($rows, [])->cap(100, true, 10, 50, 'Amateur');

        $this->assertSame(EnergyModel::HARD_MAX, $result['cap']);
        $this->assertFalse($result['estimate']);
    }

    public function test_censored_rows_are_ignored(): void
    {
        $censored = [];
        for ($i = 0; $i < 20; $i++) {
            $censored[] = ['ctr' => 70, 'from' => 100, 'to' => 0, 'laps' => 50, 'stamina' => 50];
        }

        $clean = EnergyModel::fit($this->exactRows(), []);
        $mixed = EnergyModel::fit(array_merge($this->exactRows(), $censored), []);

        foreach ([30, 50, 70] as $laps) {
            $this->assertSame($clean->cap(100, true, $laps, 50, 'Pro'), $mixed->cap(100, true, $laps, 50, 'Pro'));
        }
        $this->assertFalse($mixed->cap(100, true, 50, 50, 'Pro')['estimate']);
    }

    public function test_fallback_on_few_rows(): void
    {
        $model = EnergyModel::fit($this->exactRows(29), []);

        $this->assertSame(['cap' => 35, 'estimate' => true, 'start' => 100], $model->cap(100, true, 50, 50, 'Amateur'));
    }

    public function test_fallback_on_degenerate_slope(): void
    {
        $rows = [];
        for ($i = 0; $i < 40; $i++) {
            $ctr = 2 * ($i % 20);
            $rows[] = ['ctr' => $ctr, 'from' => 100, 'to' => 100 - (50 - $ctr), 'laps' => 50, 'stamina' => self::STAMINA[$i % 3]];
        }

        $result = EnergyModel::fit($rows, [])->cap(100, true, 50, 50, 'Pro');

        $this->assertSame(47, $result['cap']);
        $this->assertTrue($result['estimate']);
    }

    public function test_fallback_without_energy(): void
    {
        $model = EnergyModel::fit($this->exactRows(), []);

        $this->assertSame(['cap' => 35, 'estimate' => true, 'start' => null], $model->cap(null, true, 50, 50, 'Amateur'));
    }

    public function test_fallback_without_laps(): void
    {
        $model = EnergyModel::fit($this->exactRows(), []);

        $this->assertSame(['cap' => 35, 'estimate' => true, 'start' => 100], $model->cap(100, true, 0, 50, 'Amateur'));
    }

    public function test_fallback_instance_matches_empty_fit(): void
    {
        $this->assertEquals(EnergyModel::fit([], []), EnergyModel::fallback());
    }

    public function test_unknown_division_uses_the_highest_fallback(): void
    {
        $this->assertSame(65, EnergyModel::fallback()->cap(100, true, 50, 50, null)['cap']);
    }

    public function test_hard_max_bounds_the_fallback(): void
    {
        foreach (['Rookie' => 25, 'Amateur' => 35, 'Pro' => 47, 'Master' => 65, 'Elite' => 65] as $division => $cap) {
            $this->assertSame($cap, EnergyModel::fallback()->cap(null, true, 50, 50, $division)['cap']);
        }
    }

    public function test_quali_drain_and_recovery(): void
    {
        $model = EnergyModel::fit([], $this->qualiRows(70.0, 0.3, 3));

        $this->assertSame(83, $model->raceStart(50, false));
        $this->assertSame(99, $model->raceStart(95, true));
    }

    public function test_recovery_is_fitted_from_rows_below_full(): void
    {
        $rows = $this->qualiRows(50.0, 0.5, 3);
        $rows[] = ['q1_end' => 90, 'q2_end' => 85, 'race_from' => 100];
        $rows[] = ['q1_end' => 95, 'q2_end' => 90, 'race_from' => 100];
        $model = EnergyModel::fit([], $rows);

        $this->assertSame(80, $model->raceStart(60, true));
    }

    public function test_recovery_defaults_apply_below_min_rows(): void
    {
        $model = EnergyModel::fit([], $this->qualiRows(50.0, 0.5, 3, 29));

        $this->assertSame(88, $model->raceStart(60, true));
        $this->assertSame(87, $model->raceStart(60, false));
    }

    public function test_recovery_never_below_q2_end(): void
    {
        $model = EnergyModel::fit([], $this->qualiRows(0.0, 0.5, 3));

        $this->assertSame(60, $model->raceStart(60, true));
    }

    public function test_race_start_is_capped_at_100(): void
    {
        $this->assertSame(100, EnergyModel::fallback()->raceStart(100, true));
    }

    public function test_array_round_trip_keeps_a_fitted_model(): void
    {
        $model = EnergyModel::fit($this->exactRows(), $this->qualiRows(50.0, 0.5, 3));
        $copy = EnergyModel::fromArray($model->toArray());

        $this->assertSame($model->toArray(), $copy->toArray());
        $this->assertSame($model->cap(70, true, 50, 50, 'Pro'), $copy->cap(70, true, 50, 50, 'Pro'));
        $this->assertSame($model->raceStart(60, false), $copy->raceStart(60, false));
    }

    public function test_array_round_trip_keeps_an_unfitted_drain_null(): void
    {
        $model = EnergyModel::fallback();
        $copy = EnergyModel::fromArray($model->toArray());

        $this->assertNull($model->toArray()['drain']);
        $this->assertSame($model->toArray(), $copy->toArray());
        $this->assertSame($model->cap(70, true, 50, 50, 'Pro'), $copy->cap(70, true, 50, 50, 'Pro'));
    }

    public function test_from_array_survives_a_serialize_round_trip(): void
    {
        $model = EnergyModel::fit($this->exactRows(), []);
        $copy = EnergyModel::fromArray(unserialize(serialize($model->toArray())));

        $this->assertSame($model->toArray(), $copy->toArray());
    }
}
