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
     * Highest Clear Track Risk at which every part ends the race below $limit. Wear only grows with CTR,
     * so the scan stops at the first failure. A part that reaches $limit even at CTR 0 is counted as
     * swapped before the race (start 0, training wear kept): it needs replacing whatever the CTR, and
     * CTR-independent data keeps the advice stable when the manager follows it.
     *
     * @param array<string, array<string, mixed>> $parts label => {level, start, track_base, training?}
     */
    public function for(array $parts, float $driverFactor, int $limit): int
    {
        foreach ($parts as $label => $part) {
            if (!$this->survivesPart($part, $driverFactor, $limit, 0)) {
                $parts[$label]['start'] = 0.0;
            }
        }

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
            if (!$this->survivesPart($part, $driverFactor, $limit, $ctr)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $part
     */
    private function survivesPart(array $part, float $driverFactor, int $limit, int $ctr): bool
    {
        $raceWear = $this->wear->raceWear((float) $part['track_base'], (int) $part['level'], $driverFactor, $ctr);
        $end = (float) $part['start'] + (float) ($part['training'] ?? 0) + round($raceWear, 1);

        return $end < $limit;
    }
}
