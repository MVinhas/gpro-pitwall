<?php

declare(strict_types=1);

namespace App\Support;

/**
 * GPRO's championship points: "the old Formula 1 point scoring system where
 * managers in positions 1-8 get 10-8-6-5-4-3-2-1 points" (Rules 1.5).
 */
final class RacePoints
{
    /** Points by finishing position; nothing outside the top 8. */
    public const array TABLE = [1 => 10, 2 => 8, 3 => 6, 4 => 5, 5 => 4, 6 => 3, 7 => 2, 8 => 1];

    public static function forFinish(?int $finish): ?int
    {
        return $finish === null ? null : (self::TABLE[$finish] ?? 0);
    }
}
