<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Compares a track's Power/Handling/Acceleration demand against the car's
 * P/H/A strength and produces a qualifying push verdict.
 *
 * Verdicts:
 *   - all_in  : PHA-similar AND the next race is the driver's favourite
 *   - push    : PHA-similar OR favourite (exactly one)
 *   - neutral : neither — no recommendation is shown
 *
 * "PHA-similar" = both the track and the car have a strictly distinct P/H/A
 * ordering and their top two attributes coincide in order. With three
 * attributes, a matching top-2 forces the third to match too. Ties (e.g. a
 * fresh car at 13/13/13) yield no exploitable ordering and are not similar.
 */
final class PhaMatchService
{
    public const string VERDICT_ALL_IN = 'all_in';
    public const string VERDICT_PUSH = 'push';
    public const string VERDICT_NEUTRAL = 'neutral';

    private const array ATTRS = ['power', 'handling', 'acceleration'];

    /**
     * @param array<string, mixed> $track keys: power, handling, acceleration
     * @param array<string, mixed> $car   keys: power, handling, acceleration
     * @return array{
     *   verdict: string,
     *   pha_similar: bool,
     *   favourite: bool,
     *   attributes: array<string, array{track: float, car: float, track_rank: int, car_rank: int, aligned: bool}>
     * }
     */
    public function evaluate(array $track, array $car, bool $favouriteTrack): array
    {
        $trackVals = $this->normalise($track);
        $carVals = $this->normalise($car);

        $trackRanks = $this->ranks($trackVals);
        $carRanks = $this->ranks($carVals);

        $similar = $this->phaSimilar($trackVals, $carVals, $trackRanks, $carRanks);

        $verdict = match (true) {
            $similar && $favouriteTrack => self::VERDICT_ALL_IN,
            $similar || $favouriteTrack => self::VERDICT_PUSH,
            default                     => self::VERDICT_NEUTRAL,
        };

        $attributes = [];
        foreach (self::ATTRS as $attr) {
            $attributes[$attr] = [
                'track'      => $trackVals[$attr],
                'car'        => $carVals[$attr],
                'track_rank' => $trackRanks[$attr],
                'car_rank'   => $carRanks[$attr],
                'aligned'    => $trackRanks[$attr] === $carRanks[$attr],
            ];
        }

        return [
            'verdict'     => $verdict,
            'pha_similar' => $similar,
            'favourite'   => $favouriteTrack,
            'attributes'  => $attributes,
        ];
    }

    /** Tier values — lower is better; suitable for sorting. */
    public const int TIER_PERFECT   = 0;
    public const int TIER_TOP_MATCH = 1;
    public const int TIER_TOP_SWAP  = 2;
    public const int TIER_TRASH     = 3;

    /**
     * Classifies how well the car's PHA shape matches what the track
     * rewards. Ranks group attributes by demand / strength (ties share
     * a rank — competition ranking), then compares tiers:
     *
     *   - Perfect   : every car attribute sits at the same rank as the
     *                 corresponding track attribute (including ties).
     *   - Top match : the car's top-ranked attributes (one or more if
     *                 tied) coincide with the track's top-ranked set.
     *   - Top swap  : the car's top set coincides with the track's
     *                 second tier (only meaningful when the track's
     *                 top isn't tied with the second tier).
     *   - Trash     : the car's top set coincides with the track's
     *                 worst tier — the wrong car for this track.
     *
     * @param array<string, mixed> $track
     * @param array<string, mixed> $car
     */
    public function tierFor(array $track, array $car): int
    {
        $trackRanks = $this->ranks($this->normalise($track));
        $carRanks   = $this->ranks($this->normalise($car));

        if ($this->ranksMatch($trackRanks, $carRanks)) {
            return self::TIER_PERFECT;
        }

        $trackTopAttrs = $this->attrsAtRank($trackRanks, 1);
        $carTopAttrs   = $this->attrsAtRank($carRanks, 1);

        // Top match: the car's top set overlaps with the track's top set
        // (any common attribute is enough — covers tied tracks where the
        // car may only carry one of the tied top attributes).
        if ($this->setsIntersect($trackTopAttrs, $carTopAttrs)) {
            return self::TIER_TOP_MATCH;
        }

        // Top swap only exists when the track has a clear, untied top —
        // otherwise there's no meaningful "second tier" to fall into.
        if (count($trackTopAttrs) === 1) {
            $trackSecondRank = $this->nextRankAfter($trackRanks, 1);
            if ($trackSecondRank !== null) {
                $trackSecondAttrs = $this->attrsAtRank($trackRanks, $trackSecondRank);
                if ($this->setsIntersect($trackSecondAttrs, $carTopAttrs)) {
                    return self::TIER_TOP_SWAP;
                }
            }
        }

        return self::TIER_TRASH;
    }

    /**
     * @param array<string, int> $a
     * @param array<string, int> $b
     */
    private function ranksMatch(array $a, array $b): bool
    {
        foreach (self::ATTRS as $attr) {
            if ($a[$attr] !== $b[$attr]) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string, int> $ranks
     * @return list<string>
     */
    private function attrsAtRank(array $ranks, int $rank): array
    {
        $out = [];
        foreach ($ranks as $attr => $r) {
            if ($r === $rank) {
                $out[] = $attr;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private function setsIntersect(array $a, array $b): bool
    {
        return array_intersect($a, $b) !== [];
    }

    /**
     * @param array<string, int> $ranks
     */
    private function nextRankAfter(array $ranks, int $rank): ?int
    {
        $next = null;
        foreach ($ranks as $r) {
            if ($r > $rank && ($next === null || $r < $next)) {
                $next = $r;
            }
        }
        return $next;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, float>
     */
    private function normalise(array $raw): array
    {
        $out = [];
        foreach (self::ATTRS as $attr) {
            $out[$attr] = (float) ($raw[$attr] ?? 0);
        }
        return $out;
    }

    /**
     * Competition rank by value descending (1 = highest). Equal values share
     * the lower rank number, which makes a tied set detectable downstream.
     *
     * @param array<string, float> $vals
     * @return array<string, int>
     */
    private function ranks(array $vals): array
    {
        $ranks = [];
        foreach ($vals as $attr => $v) {
            $rank = 1;
            foreach ($vals as $other) {
                if ($other > $v) {
                    $rank++;
                }
            }
            $ranks[$attr] = $rank;
        }
        return $ranks;
    }

    /**
     * @param array<string, float> $trackVals
     * @param array<string, float> $carVals
     * @param array<string, int> $trackRanks
     * @param array<string, int> $carRanks
     */
    private function phaSimilar(array $trackVals, array $carVals, array $trackRanks, array $carRanks): bool
    {
        // A tied ordering (any repeated value) has no unambiguous top-2.
        if (!$this->allDistinct($trackVals) || !$this->allDistinct($carVals)) {
            return false;
        }

        // Same attribute at rank 1 and rank 2 on both sides. With distinct
        // triples this also forces rank 3 to coincide.
        return $this->attrAtRank($trackRanks, 1) === $this->attrAtRank($carRanks, 1)
            && $this->attrAtRank($trackRanks, 2) === $this->attrAtRank($carRanks, 2);
    }

    /**
     * @param array<string, float> $vals
     */
    private function allDistinct(array $vals): bool
    {
        return count(array_unique(array_values($vals))) === count($vals);
    }

    /**
     * @param array<string, int> $ranks
     */
    private function attrAtRank(array $ranks, int $rank): ?string
    {
        foreach ($ranks as $attr => $r) {
            if ($r === $rank) {
                return $attr;
            }
        }
        return null;
    }
}
