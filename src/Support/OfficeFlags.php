<?php

declare(strict_types=1);

namespace App\Support;

final readonly class OfficeFlags
{
    /**
     * GPRO's per-race "done" flags arrive as "1"/"0" (or int/bool once archived); one parser for all of them.
     *
     * @param array<string, mixed> $office
     */
    public static function done(array $office, string $flag): bool
    {
        return filter_var($office[$flag] ?? null, FILTER_VALIDATE_BOOLEAN);
    }
}
