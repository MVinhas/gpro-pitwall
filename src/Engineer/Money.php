<?php

declare(strict_types=1);

namespace App\Engineer;

final readonly class Money
{
    public static function full(int $amount): string
    {
        return ($amount < 0 ? '-' : '') . '$' . number_format(abs($amount));
    }

    public static function short(int $amount): string
    {
        $abs = abs($amount);
        $sign = $amount < 0 ? '-' : '';

        return match (true) {
            $abs >= 999_500 => $sign . '$' . self::trim(round($abs / 1_000_000, 1)) . 'M',
            $abs >= 1_000 => $sign . '$' . (int) round($abs / 1_000) . 'k',
            default => $sign . '$' . $abs,
        };
    }

    private static function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
