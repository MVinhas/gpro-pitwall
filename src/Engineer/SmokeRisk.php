<?php

declare(strict_types=1);

namespace App\Engineer;

use App\Service\WearAdvisorService;

/**
 * A part that reaches 100 % wear smokes and loses time; it only breaks and retires the car when the
 * smoke starts early enough. The breakpoints are Micael's observations (2026-11-05), not published rules:
 * up to 5 smoke laps never broke, around 20 broke about half the time, 30 or more always broke.
 */
final readonly class SmokeRisk
{
    public const int SMOKE_MIN_LAPS = 5;
    public const int SMOKE_MEDIAN_LAPS = 20;
    public const int SMOKE_MAX_LAPS = 30;

    private function __construct(
        public int $lap,
        public int $laps,
        public int $smokeLaps,
        public float $breakChance,
    ) {
    }

    public static function for(float $start, float $raceWear, int $laps): ?self
    {
        if ($raceWear <= 0 || $laps <= 0 || WearAdvisorService::percent($start + $raceWear) < 100) {
            return null;
        }

        $perLap = $raceWear / $laps;
        $lap = max(1, min($laps, (int) ceil(round((100 - $start) / $perLap, 6))));
        $smokeLaps = $laps - $lap + 1;

        return new self($lap, $laps, $smokeLaps, self::chance($smokeLaps));
    }

    /** @param array{start: int|float, end: int|float, training?: int|float} $row */
    public static function forRow(array $row, int $laps): ?self
    {
        $start = $row['start'] + ($row['training'] ?? 0);

        return self::for($start, $row['end'] - $start, $laps);
    }

    public function likelyBreak(): bool
    {
        return $this->breakChance >= 0.5;
    }

    public function phrase(): string
    {
        if ($this->likelyBreak()) {
            return 'likely to break and retire';
        }

        return 'smokes about ' . $this->smokeLaps . ($this->smokeLaps === 1 ? ' lap' : ' laps');
    }

    public function describe(?string $part = null): string
    {
        $reaches = ($part === null ? 'Reaches' : $part . ' reaches')
            . ' 100 % on lap ' . $this->lap . ' of ' . $this->laps;

        return $reaches . ': ' . $this->phrase();
    }

    private static function chance(int $smokeLaps): float
    {
        if ($smokeLaps <= self::SMOKE_MIN_LAPS) {
            return 0.0;
        }

        if ($smokeLaps >= self::SMOKE_MAX_LAPS) {
            return 1.0;
        }

        if ($smokeLaps <= self::SMOKE_MEDIAN_LAPS) {
            return 0.5 * ($smokeLaps - self::SMOKE_MIN_LAPS) / (self::SMOKE_MEDIAN_LAPS - self::SMOKE_MIN_LAPS);
        }

        return 0.5 + 0.5 * ($smokeLaps - self::SMOKE_MEDIAN_LAPS) / (self::SMOKE_MAX_LAPS - self::SMOKE_MEDIAN_LAPS);
    }
}
