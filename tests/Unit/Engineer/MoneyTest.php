<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Money::class)]
final class MoneyTest extends TestCase
{
    /**
     * @return array<string, array{int, string}>
     */
    public static function shortCases(): array
    {
        return [
            'millions' => [1_234_567, '$1.2M'],
            'whole million drops the decimal' => [2_000_000, '$2M'],
            'thousands' => [410_000, '$410k'],
            'rounds up to a million' => [999_600, '$1M'],
            'small' => [850, '$850'],
            'zero' => [0, '$0'],
            'negative' => [-1_500_000, '-$1.5M'],
        ];
    }

    #[DataProvider('shortCases')]
    public function testShort(int $amount, string $expected): void
    {
        $this->assertSame($expected, Money::short($amount));
    }

    public function testFullKeepsEveryDigit(): void
    {
        $this->assertSame('$1,234,567', Money::full(1_234_567));
        $this->assertSame('-$410,000', Money::full(-410_000));
    }
}
