<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\RidgeGram;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RidgeGram::class)]
final class RidgeGramTest extends TestCase
{
    /**
     * y = 2a - 3b exactly; the two columns are centred and unrelated.
     *
     * @return list<array{list<float>, float}>
     */
    private static function exact(): array
    {
        $rows = [];
        foreach ([[-2.0, 1.0], [-1.0, -2.0], [0.0, 0.0], [1.0, 2.0], [2.0, -1.0]] as [$a, $b]) {
            $rows[] = [[$a, $b], 2 * $a - 3 * $b];
        }

        return $rows;
    }

    /**
     * @param list<array{list<float>, float}> $rows
     */
    private static function gram(array $rows): RidgeGram
    {
        $gram = new RidgeGram(2);
        foreach ($rows as [$x, $y]) {
            $gram->add($x, $y);
        }

        return $gram;
    }

    public function testWithoutAPenaltyItRecoversTheCoefficients(): void
    {
        $beta = self::gram(self::exact())->solve([0, 1], 0.0);

        $this->assertNotNull($beta);
        $this->assertEqualsWithDelta(2.0, $beta[0], 1e-9);
        $this->assertEqualsWithDelta(-3.0, $beta[1], 1e-9);
    }

    public function testThePenaltyShrinksTowardZero(): void
    {
        $beta = self::gram(self::exact())->solve([0, 1], 5.0);

        $this->assertNotNull($beta);
        $this->assertGreaterThan(0.0, $beta[0]);
        $this->assertLessThan(2.0, $beta[0]);
        $this->assertLessThan(0.0, $beta[1]);
        $this->assertGreaterThan(-3.0, $beta[1]);
    }

    public function testAColumnSubsetIsFittedOnItsOwn(): void
    {
        $beta = self::gram(self::exact())->solve([1], 0.0);

        $this->assertNotNull($beta);
        $this->assertCount(1, $beta);
        $this->assertEqualsWithDelta(-3.0, $beta[0], 1e-9);
    }

    public function testTheErrorIsZeroForThePerfectFitAndTheSumOfSquaresForNoFit(): void
    {
        $gram = self::gram(self::exact());

        $this->assertEqualsWithDelta(0.0, $gram->sse([0, 1], [2.0, -3.0]), 1e-9);
        $this->assertEqualsWithDelta(130.0, $gram->sse([0, 1], [0.0, 0.0]), 1e-9);
    }

    public function testSumsCanBeAddedAndTakenApart(): void
    {
        $rows = self::exact();
        $all = self::gram($rows);
        $head = self::gram(array_slice($rows, 0, 2));
        $tail = self::gram(array_slice($rows, 2));

        $this->assertSame(5, $head->plus($tail)->rows());
        $this->assertSame(3, $all->minus($head)->rows());
        $this->assertEqualsWithDelta(
            $tail->sse([0, 1], [1.0, 1.0]),
            $all->minus($head)->sse([0, 1], [1.0, 1.0]),
            1e-9,
        );
        $this->assertEquals($all->solve([0, 1], 1.0), $head->plus($tail)->solve([0, 1], 1.0));
    }

    public function testNoRowsOrAnUnsolvableSystemGivesNull(): void
    {
        $this->assertNull((new RidgeGram(2))->solve([0, 1], 3.0));

        $twins = new RidgeGram(2);
        $twins->add([1.0, 1.0], 1.0);
        $twins->add([-1.0, -1.0], -1.0);
        $this->assertNull($twins->solve([0, 1], 0.0));
        $this->assertNotNull($twins->solve([0, 1], 3.0));
    }
}
