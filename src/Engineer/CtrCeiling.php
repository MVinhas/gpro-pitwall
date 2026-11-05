<?php

declare(strict_types=1);

namespace App\Engineer;

use App\Service\CarWearService;
use App\Service\WearAdvisorService;

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
     * CTR-independent data keeps the advice stable when the manager follows it. With $carLocked (this
     * race's update is spent) nothing can be swapped before the race, so those parts keep their wear and
     * the ceiling falls to 0.
     *
     * @param array<string, array<string, mixed>> $parts label => {level, start, track_base, training?}
     */
    public function for(array $parts, float $driverFactor, int $limit, bool $carLocked = false): int
    {
        if (!$carLocked) {
            foreach ($this->pastLimitAtZero($parts, $driverFactor, $limit) as $label) {
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
     * Highest Clear Track Risk at which every part that can end this race below $limit still does, with the
     * race's update spent. A part past $limit even at CTR 0 reaches it whatever the CTR, so it caps nothing; with
     * no other part the ceiling is 100.
     *
     * @param array<string, array<string, mixed>> $parts label => {level, start, track_base, training?}
     */
    public function forSurvivors(array $parts, float $driverFactor, int $limit): int
    {
        $stuck = array_flip($this->pastLimitAtZero($parts, $driverFactor, $limit));

        return $this->for(array_diff_key($parts, $stuck), $driverFactor, $limit, true);
    }

    /**
     * Labels of the parts that reach $limit even at CTR 0: they stay past it whatever the CTR.
     *
     * @param array<string, array<string, mixed>> $parts label => {level, start, track_base, training?}
     * @return list<string>
     */
    public function pastLimitAtZero(array $parts, float $driverFactor, int $limit): array
    {
        $stuck = [];
        foreach ($parts as $label => $part) {
            if (!$this->survivesPart($part, $driverFactor, $limit, 0)) {
                $stuck[] = (string) $label;
            }
        }

        return $stuck;
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

        return WearAdvisorService::percent($end) < $limit;
    }
}
