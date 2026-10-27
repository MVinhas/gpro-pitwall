<?php

declare(strict_types=1);

namespace App\Support;

final readonly class Ordinal
{
    public static function of(int $n): string
    {
        $teens = $n % 100;
        $suffix = ($teens >= 11 && $teens <= 13)
            ? 'th'
            : match ($n % 10) {
                1 => 'st',
                2 => 'nd',
                3 => 'rd',
                default => 'th',
            };

        return $n . $suffix;
    }
}
