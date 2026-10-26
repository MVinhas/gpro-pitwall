<?php

declare(strict_types=1);

namespace App\Engineer;

use App\Service\CarWearService;

final readonly class CtrCeiling
{
    private const int MAX_CTR = 100;

    public function __construct(private CarWearService $wear)
    {
    }

    /**
     * Highest Clear Track Risk at which every non-excluded part ends the race below $limit.
     * Wear only grows with CTR, so the scan stops at the first failure. Forced-swap parts are
     * excluded because they are replaced before the race.
     *
     * @param array<string, array<string, mixed>> $parts label => {level, start, track_base, training?}
     * @param list<string> $exclude part labels to ignore
     */
    public function for(array $parts, float $driverFactor, int $limit, array $exclude = []): int
    {
        $parts = array_diff_key($parts, array_flip($exclude));

        $ceiling = 0;
        for ($ctr = 0; $ctr <= self::MAX_CTR; $ctr++) {
            if (!$this->survives($parts, $driverFactor, $limit, $ctr)) {
                break;
            }
            $ceiling = $ctr;
        }

        return $ceiling;
    }

    /**
     * @param array<string, array<string, mixed>> $parts
     */
    private function survives(array $parts, float $driverFactor, int $limit, int $ctr): bool
    {
        foreach ($parts as $part) {
            $raceWear = $this->wear->raceWear((float) $part['track_base'], (int) $part['level'], $driverFactor, $ctr);
            $end = (float) $part['start'] + (float) ($part['training'] ?? 0) + round($raceWear, 1);
            if ($end >= $limit) {
                return false;
            }
        }

        return true;
    }
}
