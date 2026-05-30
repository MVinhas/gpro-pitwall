<?php

declare(strict_types=1);

namespace App\Service;

/**
 * For parts the manager must already swap (wear-flagged), suggests which
 * replacement level realigns the car's P/H/A profile with the track's
 * demand — same level, +1, or -1.
 *
 * Only suggests a level shift when it *changes* the PHA-similar verdict.
 * If the car already matches, returns 'same' with no claim. If no
 * single-step swap helps, returns null.
 */
final class PartUpgradeAdvisorService
{
    public const int MIN_LEVEL = 1;
    public const int MAX_LEVEL = 9;

    /**
     * @param array<string, array{power: int, handling: int, acceleration: int}> $contribution
     */
    public function __construct(
        private readonly PhaMatchService $pha,
        private readonly array $contribution,
    ) {
    }

    /**
     * @param array{power: int|float, handling: int|float, acceleration: int|float} $track
     * @param array{power: int|float, handling: int|float, acceleration: int|float} $car
     * @param list<array{part: string, level: int}> $swapCandidates
     * @return list<array{part: string, current_level: int, suggested_level: int, delta: int, rationale: string}>
     */
    public function suggest(array $track, array $car, array $swapCandidates): array
    {
        $suggestions = [];
        foreach ($swapCandidates as $candidate) {
            $part = $candidate['part'];
            if (!isset($this->contribution[$part])) {
                continue;
            }
            $currentLevel = $candidate['level'];
            $best = $this->bestOption($track, $car, $part, $currentLevel);
            if ($best === null) {
                continue;
            }
            $suggestions[] = $best;
        }
        return $suggestions;
    }

    /**
     * @param array{power: int|float, handling: int|float, acceleration: int|float} $track
     * @param array{power: int|float, handling: int|float, acceleration: int|float} $car
     * @return array{part: string, current_level: int, suggested_level: int, delta: int, rationale: string}|null
     */
    private function bestOption(array $track, array $car, string $part, int $currentLevel): ?array
    {
        $currentResult = $this->pha->evaluate($track, $car, false);
        $currentSimilar = (bool) $currentResult['pha_similar'];

        // Prefer the smallest level change that flips a non-match to a match.
        foreach ([-1, 1] as $delta) {
            $target = $currentLevel + $delta;
            if ($target < self::MIN_LEVEL || $target > self::MAX_LEVEL) {
                continue;
            }
            $shiftedCar = $this->carAfterSwap($car, $part, $delta);
            $shiftedResult = $this->pha->evaluate($track, $shiftedCar, false);
            if ((bool) $shiftedResult['pha_similar'] && !$currentSimilar) {
                return [
                    'part'            => $part,
                    'current_level'   => $currentLevel,
                    'suggested_level' => $target,
                    'delta'           => $delta,
                    'rationale'       => $this->rationale($part, $delta),
                ];
            }
        }

        return null;
    }

    /**
     * Would swapping `part` to `targetLevel` flip the car from "not PHA-aligned"
     * to "aligned" against the given track? Returns false if the car already
     * aligns, if no contribution data exists for the part, or if the new level
     * doesn't help.
     *
     * @param array{power: int|float, handling: int|float, acceleration: int|float} $track
     * @param array{power: int|float, handling: int|float, acceleration: int|float} $car
     */
    public function wouldAlignAt(
        string $part,
        int $targetLevel,
        int $currentLevel,
        array $track,
        array $car,
        bool $currentlySimilar,
    ): bool {
        if ($currentlySimilar || !isset($this->contribution[$part])) {
            return false;
        }
        $delta = $targetLevel - $currentLevel;
        if ($delta === 0) {
            return false;
        }
        $shiftedCar = $this->carAfterSwap($car, $part, $delta);
        return (bool) $this->pha->evaluate($track, $shiftedCar, false)['pha_similar'];
    }

    /**
     * Hypothetical car PHA after changing `part` by `delta` levels.
     * Returns the car unchanged when the part has no contribution data
     * so callers can score it without a special case.
     *
     * @param array{power: int|float, handling: int|float, acceleration: int|float} $car
     * @return array{power: float, handling: float, acceleration: float}
     */
    public function carAfterSwap(array $car, string $part, int $delta): array
    {
        $c = $this->contribution[$part] ?? ['power' => 0, 'handling' => 0, 'acceleration' => 0];
        return [
            'power'        => (float) $car['power']        + $c['power']        * $delta,
            'handling'     => (float) $car['handling']     + $c['handling']     * $delta,
            'acceleration' => (float) $car['acceleration'] + $c['acceleration'] * $delta,
        ];
    }

    private function rationale(string $part, int $delta): string
    {
        $direction = $delta > 0 ? 'higher' : 'lower';
        $c = $this->contribution[$part];
        $strongest = $this->strongestAttribute($c);
        return sprintf(
            'Replacing with a %s-level %s realigns your car — %s leans into %s.',
            $direction,
            $part,
            $part,
            $strongest,
        );
    }

    /**
     * @param array{power: int, handling: int, acceleration: int} $c
     */
    private function strongestAttribute(array $c): string
    {
        arsort($c);
        return (string) array_key_first($c);
    }
}
