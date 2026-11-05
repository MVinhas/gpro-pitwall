<?php

declare(strict_types=1);

namespace App\Support;

final readonly class OfficeFlags
{
    /**
     * One parser for GPRO's per-race "done" flags. Most arrive as "1"/"0", but doneTesting and donePractice are lap
     * counts ("0".."100" and "0".."8"), so any number above zero means done; archived offices may hold int/bool.
     *
     * @param array<string, mixed> $office
     */
    public static function done(array $office, string $flag): bool
    {
        $value = $office[$flag] ?? null;

        return is_numeric($value) ? (float) $value > 0 : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
