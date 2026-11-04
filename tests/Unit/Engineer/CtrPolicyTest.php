<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\CtrPolicy;
use App\Engineer\EnergyModel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CtrPolicy::class)]
final class CtrPolicyTest extends TestCase
{
    public function test_safety_three_places_above_the_line(): void
    {
        $this->assertSame(3, CtrPolicy::SAFETY_PLACES);
        $this->assertTrue(CtrPolicy::safetyFor(23, 26));
        $this->assertTrue(CtrPolicy::safetyFor(30, 26));
    }

    public function test_no_safety_further_up(): void
    {
        $this->assertFalse(CtrPolicy::safetyFor(22, 26));
    }

    public function test_no_safety_without_a_projection_or_a_line(): void
    {
        $this->assertFalse(CtrPolicy::safetyFor(null, 26));
        $this->assertFalse(CtrPolicy::safetyFor(30, null));
        $this->assertFalse(CtrPolicy::safetyFor(null, null));
    }

    public function test_none_has_no_gain_and_the_hard_energy_cap(): void
    {
        $none = CtrPolicy::none();

        $this->assertSame(EnergyModel::HARD_MAX, $none->energyCap);
        $this->assertTrue($none->energyEstimate);
        $this->assertNull($none->energyStart);
        $this->assertSame(0, $none->gainPerPoint);
        $this->assertSame(0.0, $none->placesPerPoint);
        $this->assertFalse($none->paceKnown);
        $this->assertFalse($none->safety);
    }
}
