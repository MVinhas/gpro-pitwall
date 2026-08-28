<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\PilotCalculatorService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PilotCalculatorService::class)]
final class PilotCalculatorServiceTest extends TestCase
{
    /**
     * Synthetic weights — the real factors are private game formulas. The
     * behaviours pinned here (weighted sum, cap clamping, motivation-first
     * reduction order) are independent of the actual coefficients.
     *
     * @var array<string, float>
     */
    private const array FACTORS = [
        'concentration'     => 0.10,
        'talent'            => 0.30,
        'aggressiveness'    => 0.05,
        'experience'        => 0.15,
        'technical_insight' => 0.10,
        'stamina'           => 0.10,
        'charisma'          => 0.05,
        'motivation'        => 0.20,
    ];

    /** @var array<string, int> */
    private const array CAPS = ['Rookie' => 50, 'Elite' => 200];

    private function service(): PilotCalculatorService
    {
        return new PilotCalculatorService(self::FACTORS, self::CAPS);
    }

    /** @return array<string, mixed> */
    private function pilot(int $value = 100): array
    {
        return array_fill_keys(array_keys(self::FACTORS), $value);
    }

    public function testOverallIsTheWeightedSumOfTheFactorColumns(): void
    {
        $overall = $this->service()->calculateOverall([
            'talent' => 100,
            'motivation' => 50,
        ]);

        $this->assertEqualsWithDelta((100 * 0.30) + (50 * 0.20), $overall, 0.0001);
    }

    public function testOverallIsZeroForAPilotWithNoKnownStats(): void
    {
        $this->assertSame(0.0, $this->service()->calculateOverall([]));
    }

    public function testColumnsWithoutAFactorAreIgnored(): void
    {
        $withExtra = $this->service()->calculateOverall(['talent' => 100, 'shoe_size' => 44]);
        $withoutExtra = $this->service()->calculateOverall(['talent' => 100]);

        $this->assertSame($withoutExtra, $withExtra);
    }

    public function testStringNumericStatsAreCoercedToInt(): void
    {
        $this->assertEqualsWithDelta(
            30.0,
            $this->service()->calculateOverall(['talent' => '100']),
            0.0001
        );
    }

    public function testAPilotAtOrBelowTheCapIsReturnedUntouched(): void
    {
        $pilot = ['talent' => 10, 'motivation' => 10];

        $this->assertSame($pilot, $this->service()->adjustPilotStats($pilot, 'Elite'));
    }

    public function testAnUnknownDivisionGetsAnEffectivelyUnlimitedCap(): void
    {
        $pilot = $this->pilot(100);

        $this->assertSame($pilot, $this->service()->adjustPilotStats($pilot, 'No Such Division'));
    }

    public function testMotivationAbsorbsTheReductionAloneWhenItIsLargeEnough(): void
    {
        // OA = 30 + 20 = 50 against a cap of 45: a 5-point cut, well inside
        // motivation's own 20-point contribution, so nothing else is touched.
        $service = new PilotCalculatorService(self::FACTORS, ['Rookie' => 45]);
        $pilot = ['talent' => 100, 'motivation' => 100];

        $adjusted = $service->adjustPilotStats($pilot, 'Rookie');

        $this->assertSame(100, $adjusted['talent']);
        $this->assertSame(75, (int) $adjusted['motivation']);
        $this->assertEqualsWithDelta(45.0, $service->calculateOverall($adjusted), 0.0001);
    }

    /**
     * Stats are integers, so the scaled-down pilot lands on the nearest whole
     * value rather than exactly on the cap. The residue is bounded by rounding,
     * not open-ended — pin the bound so a future change to the rounding cannot
     * quietly let a pilot drift meaningfully over its division cap.
     */
    public function testTheAdjustedPilotLandsAtTheDivisionCapWithinIntegerRounding(): void
    {
        $service = $this->service();

        $before = $service->calculateOverall($this->pilot(100));
        $adjusted = $service->adjustPilotStats($this->pilot(100), 'Rookie');
        $after = $service->calculateOverall($adjusted);

        $this->assertLessThan($before, $after);
        $this->assertEqualsWithDelta(50.0, $after, 1.0);
    }

    public function testMotivationIsExhaustedBeforeSecondaryStatsAreScaledDown(): void
    {
        $service = $this->service();

        $adjusted = $service->adjustPilotStats($this->pilot(100), 'Rookie');

        $this->assertSame(0, $adjusted['motivation']);
        $this->assertLessThan(100, (int) $adjusted['concentration']);
    }

    public function testTalentIsNeverScaledDownEvenWhenSecondaryStatsAre(): void
    {
        $service = $this->service();

        $adjusted = $service->adjustPilotStats($this->pilot(100), 'Rookie');

        $this->assertSame(100, $adjusted['talent']);
    }

    public function testNoStatIsEverDrivenNegative(): void
    {
        $service = new PilotCalculatorService(self::FACTORS, ['Rookie' => 0]);

        $adjusted = $service->adjustPilotStats($this->pilot(100), 'Rookie');

        foreach ($adjusted as $value) {
            $this->assertGreaterThanOrEqual(0, (int) $value);
        }
    }

    public function testAPilotWithoutMotivationStillGetsScaledDown(): void
    {
        $service = $this->service();
        $pilot = ['talent' => 100, 'concentration' => 100, 'experience' => 100];

        $adjusted = $service->adjustPilotStats($pilot, 'Rookie');

        $this->assertLessThan(100, (int) $adjusted['concentration']);
        $this->assertSame(100, $adjusted['talent']);
    }

    public function testAZeroMotivationFactorSkipsStraightToSecondaryScaling(): void
    {
        $factors = self::FACTORS;
        $factors['motivation'] = 0.0;
        $service = new PilotCalculatorService($factors, self::CAPS);

        $adjusted = $service->adjustPilotStats($this->pilot(100), 'Rookie');

        $this->assertSame(100, $adjusted['motivation']);
        $this->assertLessThan(100, (int) $adjusted['concentration']);
    }

    public function testAPilotWithNoAdjustableContributionIsReturnedAsIs(): void
    {
        $service = new PilotCalculatorService(['talent' => 1.0], ['Rookie' => 5]);
        $pilot = ['talent' => 100];

        $this->assertSame($pilot, $service->adjustPilotStats($pilot, 'Rookie'));
    }
}
