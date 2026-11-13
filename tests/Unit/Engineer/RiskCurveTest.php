<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\RiskCurve;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The corpora and the expected numbers come from `.local/tools/risk_curve_fixtures.py`, which runs the
 * same integer arithmetic through the reference implementation.
 *
 * @phpstan-import-type Row from RiskCurve
 * @phpstan-import-type Context from RiskCurve
 */
#[CoversClass(RiskCurve::class)]
final class RiskCurveTest extends TestCase
{
    private int $state = 0;

    private function below(int $n): int
    {
        $this->state = ($this->state * 1103515245 + 12345) % 2147483648;

        return ($this->state >> 8) % $n;
    }

    /**
     * @param callable(float, float, Row): float $effect places a pair of risks (as fractions) is worth in a race
     *
     * @return list<Row>
     */
    private function corpus(int $drivers, int $races, callable $effect, int $top = 10, int $firstDriver = 1000): array
    {
        $this->state = 7;
        $rows = [];
        for ($k = 0; $k < $drivers; $k++) {
            $agg = 20 + $this->below(150);
            $exp = 30 + $this->below(180);
            $con = 60 + $this->below(160);
            $bias = $this->below(7) - 3;
            for ($j = 0; $j < $races; $j++) {
                $row = [
                    'driver' => $firstDriver + $k, 'season' => 113,
                    'grid' => 1 + $this->below(30),
                    'overtake' => 5 + 5 * $this->below($top),
                    'defend' => 5 + 5 * $this->below($top),
                    'wet' => $this->below(4) === 0,
                    'track' => $this->below(5),
                    'grip' => $this->below(5),
                    'agg' => $agg, 'exp' => $exp, 'con' => $con,
                    'gained' => 0,
                ];
                $jitter = $this->below(2001) / 1000 - 1.0;
                $places = $bias + $effect(min(70, $row['overtake']) / 100, min(70, $row['defend']) / 100, $row) + $jitter;
                $row['gained'] = (int) floor($places + 0.5);
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** A hill in both dials: best near overtake 25, defend 30. */
    private static function hill(float $o, float $d): float
    {
        return 16 * $o - 32 * $o * $o + 12 * $d - 20 * $d * $d;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return Context
     */
    private static function context(array $overrides = []): array
    {
        /** @var Context */
        return $overrides + ['agg' => 60, 'exp' => 90, 'con' => 120, 'grid' => null, 'wet' => false, 'track' => 2, 'grip' => 2];
    }

    public function testAHillIsLearnedAndTheAdviceIsItsKnee(): void
    {
        $curve = RiskCurve::fit($this->corpus(80, 6, static fn (float $o, float $d): float => self::hill($o, $d)));

        $this->assertTrue($curve->ready());
        $this->assertSame(480, $curve->rows());
        $this->assertSame(80, $curve->drivers());
        $this->assertSame(20, $curve->coreWins());
        $this->assertSame([], $curve->admitted());
        $this->assertSame([5, 50, 5, 50], $curve->range());
        $this->assertSame([25, 30], $curve->usual());

        $advice = $curve->recommend(self::context());

        $this->assertNotNull($advice);
        $this->assertSame(20, $advice['overtake']);
        $this->assertSame(20, $advice['defend']);
        $this->assertEqualsWithDelta(-0.184, $advice['places'], 0.001);
    }

    public function testTooFewRacesIsNotReady(): void
    {
        $curve = RiskCurve::fit($this->corpus(49, 6, static fn (float $o, float $d): float => self::hill($o, $d)));

        $this->assertFalse($curve->ready());
        $this->assertSame(294, $curve->rows());
        $this->assertNull($curve->recommend(self::context()));
    }

    public function testDriversWithFewerThanThreeRacesInASeasonAreLeftOut(): void
    {
        $hill = static fn (float $o, float $d): float => self::hill($o, $d);

        $none = RiskCurve::fit($this->corpus(150, 2, $hill));
        $mixed = RiskCurve::fit([...$this->corpus(80, 6, $hill), ...$this->corpus(40, 2, $hill, 10, 6000)]);

        $this->assertSame(0, $none->rows());
        $this->assertFalse($none->ready());
        $this->assertSame(480, $mixed->rows());
        $this->assertSame(80, $mixed->drivers());
    }

    public function testRisksThatChangeNothingAreNotTrusted(): void
    {
        $curve = RiskCurve::fit($this->corpus(80, 6, static fn (): float => 0.0));

        $this->assertFalse($curve->ready());
        $this->assertSame(480, $curve->rows());
        $this->assertSame(7, $curve->coreWins());
        $this->assertNull($curve->recommend(self::context()));
    }

    public function testRisksThatOnlyCostPlacesGiveTheLowestPairSeen(): void
    {
        $curve = RiskCurve::fit($this->corpus(80, 6, static fn (float $o, float $d): float => -6 * $o - 4 * $d));

        $advice = $curve->recommend(self::context());

        $this->assertNotNull($advice);
        $this->assertSame(5, $advice['overtake']);
        $this->assertSame(5, $advice['defend']);
        $this->assertGreaterThan(2.0, $advice['places']);
    }

    public function testRainThatPunishesOvertakeRiskIsAdmittedAndLowersTheWetAdvice(): void
    {
        $curve = RiskCurve::fit($this->corpus(
            80,
            6,
            static fn (float $o, float $d, array $row): float => self::hill($o, $d) - ($row['wet'] ? 14 * $o : 0.0),
        ));

        $dry = $curve->recommend(self::context());
        $wet = $curve->recommend(self::context(['wet' => true]));

        $this->assertContains('weather', $curve->admitted());
        $this->assertSame(20, $curve->groupWins()['weather']);
        $this->assertNotNull($dry);
        $this->assertNotNull($wet);
        $this->assertSame(15, $dry['overtake']);
        $this->assertSame(5, $wet['overtake']);
        $this->assertContains('weather', $curve->factors(self::context(['wet' => true])));
        $this->assertNotContains('weather', $curve->factors(self::context()));
    }

    public function testAMidfieldEffectUsesTheGridSlotAndAveragesWhenItIsUnknown(): void
    {
        $curve = RiskCurve::fit($this->corpus(
            80,
            6,
            static fn (float $o, float $d, array $row): float => self::hill($o, $d)
                + ($row['grid'] >= 6 && $row['grid'] <= 25 ? 12 * $o : 0.0),
        ));

        $this->assertSame(['midfield'], $curve->admitted());
        $this->assertSame(20, $curve->recommend(self::context(['grid' => 1]))['overtake'] ?? null);
        $this->assertSame(35, $curve->recommend(self::context(['grid' => 12]))['overtake'] ?? null);
        $this->assertSame(30, $curve->recommend(self::context())['overtake'] ?? null);
        $this->assertSame(['midfield'], $curve->factors(self::context(['grid' => 12])));
        $this->assertSame([], $curve->factors(self::context(['grid' => 1])));
        $this->assertSame([], $curve->factors(self::context()));
    }

    public function testRisksAboveSeventyAreReadAsSeventyAndTheSearchStaysInTheRangeSeen(): void
    {
        $curve = RiskCurve::fit($this->corpus(80, 6, static fn (float $o, float $d): float => self::hill($o, $d), 20));

        $this->assertSame([10, 70, 10, 70], $curve->range());
        $advice = $curve->recommend(self::context());
        $this->assertNotNull($advice);
        $this->assertSame(15, $advice['overtake']);
        $this->assertSame(25, $advice['defend']);
    }

    public function testACurveSurvivesTheCache(): void
    {
        $curve = RiskCurve::fit($this->corpus(80, 6, static fn (float $o, float $d): float => self::hill($o, $d)));

        $copy = RiskCurve::fromArray($curve->toArray());

        $this->assertSame($curve->toArray(), $copy->toArray());
        $this->assertSame($curve->recommend(self::context()), $copy->recommend(self::context()));
    }

    public function testTheFallbackIsNotReady(): void
    {
        $curve = RiskCurve::fallback();

        $this->assertFalse($curve->ready());
        $this->assertSame(0, $curve->rows());
        $this->assertNull($curve->recommend(self::context()));
        $this->assertSame([], $curve->factors(self::context()));
    }
}
