<?php

declare(strict_types=1);

namespace App\Support;

final readonly class TrackName
{
    /**
     * GPRO race data names a circuit "Brno (Czech Republic)" where the tracks
     * table holds "Brno". Without stripping the country suffix every lookup
     * misses, and per-km rates and calibration silently come back empty.
     */
    public static function base(string $name): string
    {
        return trim((string) preg_replace('/\s*\([^)]*\)\s*$/', '', trim($name)));
    }
}
