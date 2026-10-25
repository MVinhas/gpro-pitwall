<?php

declare(strict_types=1);

namespace App\Calibration;

final readonly class LedgerCategories
{
    public const array ORDER = ['Sponsors', 'Prize money', 'Salaries', 'Facilities', 'Tyres', 'Track time', 'Other'];

    private const array PATTERNS = [
        'Sponsors' => '/^Sponsor/i',
        'Prize money' => '/^(Race \d+ finish position|Qualifying position)/i',
        'Salaries' => '/^Salar/i',
        'Facilities' => '/^Facilit/i',
        'Tyres' => '/^Tyre/i',
        'Track time' => '/^(Practice lap|Qualify \d+ lap|Testing session)/i',
    ];

    public static function categorise(string $description): string
    {
        foreach (self::PATTERNS as $category => $pattern) {
            if (preg_match($pattern, trim($description)) === 1) {
                return $category;
            }
        }

        return 'Other';
    }
}
