<?php

declare(strict_types=1);

namespace App\Engineer;

final class PrizeTable
{
    private const int PLACES = 40;

    /** Race prize in dollars as [P1, P40]; it falls linearly from P2 onwards (Rules 5.3). */
    private const array PRIZES = [
        'Elite' => [22_000_000, 11_000_000],
        'Master' => [18_000_000, 9_000_000],
        'Pro' => [15_000_000, 7_500_000],
        'Amateur' => [12_000_000, 6_000_000],
        'Rookie' => [8_000_000, 4_000_000],
    ];

    public static function perPlace(?string $division): int
    {
        if ($division === null || !isset(self::PRIZES[$division])) {
            return 0;
        }

        [$first, $last] = self::PRIZES[$division];

        return (int) round(($first - $last) / (self::PLACES - 1));
    }
}
