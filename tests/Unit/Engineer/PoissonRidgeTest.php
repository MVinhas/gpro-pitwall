<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\PoissonRidge;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PoissonRidge::class)]
final class PoissonRidgeTest extends TestCase
{
    /** Knuth's sampler over a seeded generator, so a run is reproducible. */
    private static function poisson(float $mean): int
    {
        $limit = exp(-$mean);
        $k = 0;
        $p = 1.0;
        do {
            $k++;
            $p *= mt_rand() / mt_getrandmax();
        } while ($p > $limit);

        return $k - 1;
    }

    /**
     * @return array{list<list<float>>, list<int>, list<float>}
     */
    private static function sample(int $n, int $seed, float $b0, float $b1, float $b2, bool $withOffset = false): array
    {
        mt_srand($seed);
        $x = [];
        $y = [];
        $offset = [];
        for ($i = 0; $i < $n; $i++) {
            $x1 = mt_rand() / mt_getrandmax() * 2 - 1;
            $x2 = mt_rand() / mt_getrandmax() * 2 - 1;
            $laps = $withOffset ? 40.0 + mt_rand(0, 40) : 1.0;
            $x[] = [$x1, $x2];
            $offset[] = log($laps);
            $y[] = self::poisson($laps * exp($b0 + $b1 * $x1 + $b2 * $x2));
        }

        return [$x, $y, $offset];
    }

    public function testRecoversTheRateOfAKnownProcess(): void
    {
        [$x, $y, $offset] = self::sample(3000, 7, 0.5, 0.8, -0.4);

        $model = PoissonRidge::fit($x, $y, $offset, 0.01);

        $this->assertNotNull($model);
        $this->assertEqualsWithDelta(exp(0.5 + 0.8), $model->predict([1.0, 0.0]), 0.25);
        $this->assertEqualsWithDelta(exp(0.5 - 0.4), $model->predict([0.0, 1.0]), 0.2);
        $this->assertEqualsWithDelta(exp(0.5), $model->predict([0.0, 0.0]), 0.15);
    }

    public function testTheOffsetScalesThePredictionLikeExposure(): void
    {
        [$x, $y, $offset] = self::sample(3000, 11, -3.0, 0.6, 0.0, true);

        $model = PoissonRidge::fit($x, $y, $offset, 0.01);

        $this->assertNotNull($model);
        $perLap = $model->predict([0.5, 0.0], log(1.0));
        $this->assertEqualsWithDelta(exp(-3.0 + 0.3), $perLap, 0.01);
        $this->assertEqualsWithDelta(60.0 * $perLap, $model->predict([0.5, 0.0], log(60.0)), 1e-9);
    }

    public function testRidgeKeepsThinDataNearTheMeanInsteadOfSwinging(): void
    {
        [$x, $y, $offset] = self::sample(25, 3, 0.2, 1.5, 0.0);

        $loose = PoissonRidge::fit($x, $y, $offset, 0.001);
        $tight = PoissonRidge::fit($x, $y, $offset, 20.0);

        $this->assertNotNull($loose);
        $this->assertNotNull($tight);
        $spread = static fn (PoissonRidge $m): float => $m->predict([1.0, 0.0]) / $m->predict([-1.0, 0.0]);
        $this->assertGreaterThan(1.0, $spread($tight));
        $this->assertLessThan($spread($loose), $spread($tight));
    }

    public function testAHigherFeatureRaisesTheRateWhenTheTrueSlopeIsPositive(): void
    {
        [$x, $y, $offset] = self::sample(800, 5, 0.0, 0.7, 0.0);

        $model = PoissonRidge::fit($x, $y, $offset, 1.0);

        $this->assertNotNull($model);
        $this->assertGreaterThan($model->predict([-0.5, 0.0]), $model->predict([0.5, 0.0]));
    }

    public function testAConstantColumnIsHarmless(): void
    {
        [$x, $y, $offset] = self::sample(500, 9, 0.3, 0.5, 0.0);
        $x = array_map(static fn (array $row): array => [...$row, 4.0], $x);

        $model = PoissonRidge::fit($x, $y, $offset, 1.0);

        $this->assertNotNull($model);
        $this->assertEqualsWithDelta($model->predict([0.2, 0.1, 4.0]), $model->predict([0.2, 0.1, 99.0]), 1e-9);
    }

    public function testTooFewRowsOrNoVariationGivesNull(): void
    {
        $this->assertNull(PoissonRidge::fit([[1.0, 2.0]], [3], [0.0], 1.0));
        $this->assertNull(PoissonRidge::fit([], [], [], 1.0));
        $this->assertNull(PoissonRidge::fit([[1.0], [2.0], [3.0]], [0, 0, 0], [0.0, 0.0, 0.0], 1.0));
    }

    public function testRoundTripsThroughAnArray(): void
    {
        [$x, $y, $offset] = self::sample(400, 13, 0.4, 0.6, -0.2);
        $model = PoissonRidge::fit($x, $y, $offset, 1.0);
        $this->assertNotNull($model);

        $copy = PoissonRidge::fromArray($model->toArray());

        $this->assertSame($model->predict([0.3, -0.7], 0.5), $copy->predict([0.3, -0.7], 0.5));
        $this->assertSame($model->toArray(), json_decode((string) json_encode($model->toArray()), true));
    }
}
