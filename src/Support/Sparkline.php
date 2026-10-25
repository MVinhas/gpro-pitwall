<?php

declare(strict_types=1);

namespace App\Support;

final readonly class Sparkline
{
    /**
     * SVG `points` attribute: the non-null values spread evenly across $width,
     * higher values toward the top ($invert flips that so the lowest is on top).
     *
     * @param list<int|float|null> $values
     */
    public static function points(array $values, int $width, int $height, bool $invert = false): string
    {
        $series = array_values(array_filter($values, static fn (int|float|null $v): bool => $v !== null));
        $count = count($series);
        if ($count < 2) {
            return '';
        }

        $min = min($series);
        $span = max($series) - $min;
        $points = [];
        foreach ($series as $i => $value) {
            $x = $width * $i / ($count - 1);
            $ratio = $span > 0 ? ($value - $min) / $span : 0.5;
            $y = $height * ($invert ? $ratio : 1 - $ratio);
            $points[] = self::number($x) . ',' . self::number($y);
        }

        return implode(' ', $points);
    }

    private static function number(float|int $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');

        return $formatted === '-0' ? '0' : $formatted;
    }
}
