<?php

declare(strict_types=1);

namespace App\Engineer;

final readonly class PlaceGap
{
    public const int MIN_PAIRS = 20;

    private const float DEFAULT_LAP_GAP = 0.10;

    // Q2 seconds per place per lap from the first corpus run on 2026-11-04; Master and Elite borrow Pro's.
    private const array FALLBACK_LAP_GAP = [
        'Rookie' => 0.36,
        'Amateur' => 0.19,
        'Pro' => 0.10,
        'Master' => 0.10,
        'Elite' => 0.10,
    ];

    /**
     * @param array<string, float> $medianByDivision seconds per place per lap
     */
    private function __construct(private array $medianByDivision)
    {
    }

    /**
     * @param array<string, list<float>> $lapGapsByDivision seconds per place per lap, one entry per measured pair
     */
    public static function fromLapGaps(array $lapGapsByDivision): self
    {
        $medians = [];
        foreach ($lapGapsByDivision as $division => $gaps) {
            if (count($gaps) >= self::MIN_PAIRS) {
                $medians[$division] = self::median($gaps);
            }
        }

        return new self($medians);
    }

    public static function fallback(): self
    {
        return self::fromLapGaps([]);
    }

    public function secondsPerPlace(?string $division, int $laps): float
    {
        $perLap = $division !== null
            ? ($this->medianByDivision[$division] ?? self::FALLBACK_LAP_GAP[$division] ?? self::DEFAULT_LAP_GAP)
            : self::DEFAULT_LAP_GAP;

        return $perLap * $laps;
    }

    /**
     * @param non-empty-list<float> $values
     */
    private static function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);

        return $count % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
