<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Engineer\RiskCurve;
use App\Service\RiskAdvisorService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RiskAdvisorService::class)]
final class RiskAdvisorLearnedTest extends TestCase
{
    /**
     * A hill best near overtake 25, defend 30; the optional wet columns pull overtake down in the rain.
     *
     * @param list<string> $admitted
     */
    private static function curve(array $admitted = []): RiskCurve
    {
        $columns = ['grid', 'track', 'grip', 'wet', 'o', 'o2', 'd', 'd2'];
        $beta = [0.0, 0.0, 0.0, 0.0, 8.0, -16.0, 6.0, -10.0];
        if ($admitted !== []) {
            $columns = [...$columns, 'o_wet', 'd_wet'];
            $beta = [...$beta, -6.0, 0.0];
        }

        return RiskCurve::fromArray([
            'rows' => 400, 'drivers' => 100, 'ready' => true, 'core_wins' => 20, 'group_wins' => [],
            'admitted' => $admitted, 'columns' => $columns, 'beta' => $beta,
            'range' => [5, 50, 5, 50], 'usual' => [10, 10], 'mid_share' => 0.5,
        ]);
    }

    /** @return array<string, mixed> */
    private static function driver(): array
    {
        return ['concentration' => 120, 'talent' => 100, 'aggressiveness' => 100, 'experience' => 110, 'stamina' => 100,
            'motivation' => 100];
    }

    /** @return array<string, mixed> */
    private static function track(): array
    {
        return ['overtaking' => 'Normal', 'grip' => 'Normal', 'tyre_wear' => 'Medium', 'distance' => 250.0];
    }

    /**
     * @return array<string, mixed>
     */
    private function advise(RiskCurve $curve, bool $wet = false): array
    {
        return (new RiskAdvisorService())->advise(self::driver(), self::track(), $wet, 10.0, null, $curve);
    }

    public function testAReadyCurveGivesItsPairAndWhatItIsWorth(): void
    {
        $advice = $this->advise(self::curve());

        $this->assertSame(20, $advice['overtake']);
        $this->assertSame(25, $advice['defend']);
        $this->assertEqualsWithDelta(0.695, $advice['places'], 1e-9);
        $this->assertSame('learned', $advice['source']);
        $this->assertSame([10, 10], $advice['usual']);
        $this->assertSame([], $advice['factors']);
        $this->assertSame(400, $advice['races']);
    }

    public function testAnAdmittedGroupInPlayMovesThePairAndIsNamed(): void
    {
        $advice = $this->advise(self::curve(['weather']), true);

        $this->assertSame(5, $advice['overtake']);
        $this->assertSame(25, $advice['defend']);
        $this->assertSame(['weather'], $advice['factors']);
    }

    public function testACurveThatIsNotReadyLeavesTheHeuristicUntouched(): void
    {
        $heuristic = (new RiskAdvisorService())->suggest(self::driver(), self::track(), false, 10.0);

        $advice = $this->advise(RiskCurve::fallback());

        $this->assertSame($heuristic['overtake'], $advice['overtake']);
        $this->assertSame($heuristic['defend'], $advice['defend']);
        $this->assertSame('estimate', $advice['source']);
        $this->assertSame(0, $advice['races']);
        $this->assertSame(300, $advice['needed']);
        $this->assertNull($advice['places']);
        $this->assertNull($advice['usual']);
        $this->assertSame([], $advice['factors']);
    }

    public function testTheDivisionComesFromTheGroupLabel(): void
    {
        $this->assertSame('Pro', RiskAdvisorService::divisionFromGroup('Pro - 8'));
        $this->assertSame('Rookie', RiskAdvisorService::divisionFromGroup('Rookie - 31'));
        $this->assertNull(RiskAdvisorService::divisionFromGroup('Junk - 1'));
        $this->assertNull(RiskAdvisorService::divisionFromGroup(''));
    }
}
