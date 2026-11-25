<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\RacePoints;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RacePoints::class)]
final class RacePointsTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int}>
     */
    public static function finishes(): iterable
    {
        yield 'win' => [1, 10];
        yield '2nd' => [2, 8];
        yield '3rd' => [3, 6];
        yield '4th' => [4, 5];
        yield '5th' => [5, 4];
        yield '6th' => [6, 3];
        yield '7th' => [7, 2];
        yield '8th' => [8, 1];
        yield '9th scores nothing' => [9, 0];
        yield '10th scores nothing' => [10, 0];
        yield 'back of the field' => [40, 0];
    }

    #[DataProvider('finishes')]
    public function testPaysGprosTopEightScale(int $finish, int $points): void
    {
        $this->assertSame($points, RacePoints::forFinish($finish));
    }

    public function testNoFinishHasNoPoints(): void
    {
        $this->assertNull(RacePoints::forFinish(null));
    }
}
