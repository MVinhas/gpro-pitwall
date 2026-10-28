<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\Ordinal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Ordinal::class)]
final class OrdinalTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function ordinals(): iterable
    {
        foreach ([
            1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', 10 => '10th', 11 => '11th', 12 => '12th', 13 => '13th',
            21 => '21st', 22 => '22nd', 23 => '23rd', 101 => '101st', 111 => '111th',
        ] as $n => $expected) {
            yield (string) $n => [$n, $expected];
        }
    }

    #[DataProvider('ordinals')]
    public function testOrdinals(int $n, string $expected): void
    {
        $this->assertSame($expected, Ordinal::of($n));
    }
}
