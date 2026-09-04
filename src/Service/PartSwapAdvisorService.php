<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Turns GPRO's per-part `*Options` arrays (from the GetCar payload) into a
 * short, decidable plan for each part the wear advisor has flagged.
 *
 * The plan is deliberately narrow: a manager replacing a worn part in GPRO
 * realistically moves one level down, stays put, or moves one level up. So
 * only the levels `current-1 … current+1` are considered, and each of those
 * levels yields **at most one** option — the cheapest way to get a part at
 * that level that survives the race, with a free garage spare always beating
 * a paid purchase (both survive, so paying for the same level is waste).
 * When the part still finishes as-is, a "keep it" option joins the list.
 *
 * Every option is projected against the upcoming race via CarWearService and
 * hard-filtered: cost > cash (free spares pass), end-wear > 100 %, level
 * outside the group's observed envelope `[min-1, max+1]` from GetMoneyLevels
 * (skipped when <3 peers observed).
 *
 * Ranking is PHA-first, cost second — the point of a swap is to make the car
 * resemble the track, and a cheaper part that unbalances the car is a bad
 * trade. Options sort by:
 *
 *   1. PHA alignment score, in 0.5-point bands
 *   2. free before paid, then cheapest
 *
 * The band matters: a real car sits near 80/85/80, so one level of one part
 * moves the score by a fraction of a point. Differences that fine are noise,
 * and inside a band the cheaper option is the better trade — `recommend_reason`
 * records which of the two actually decided it (`fit` or `value`) so the UI can
 * say so rather than claiming a fit win it didn't earn.
 *
 * `tierFor()` is deliberately NOT part of the sort. Its top-set rule flips on a
 * single point of difference between two near-equal attributes, which on a flat
 * car would let a rounding-level wobble override a genuine shape improvement.
 * Each option still carries its `tier`/`match` for display.
 *
 * `options[0]` is the recommendation, and it is what the UI pre-selects: the
 * advisor is only ever handed parts that cannot finish the race, so there is
 * no do-nothing option to weigh it against.
 *
 * @phpstan-type Pha array{power: float, handling: float, acceleration: float}
 * @phpstan-type SwapOption array{
 *     level: int,
 *     delta: int,
 *     cost: int,
 *     is_free: bool,
 *     start: float,
 *     end: float,
 *     fit: float,
 *     fit_delta: float,
 *     tier: int,
 *     match: string,
 *     pha: Pha,
 *     pha_delta: Pha,
 *     recommended: bool,
 *     recommend_reason: string,
 *     rationale: string,
 * }
 */
final class PartSwapAdvisorService
{
    /** End-of-race wear (%) below which a part is considered to survive. */
    public const float SURVIVE_THRESHOLD = 100.0;

    /** Group sample size below which the operating-band filter is skipped. */
    private const int GROUP_MIN_SAMPLE = 3;

    /** One-level buffer around the observed group envelope. */
    private const int GROUP_BUFFER = 1;

    /** How far either side of the current level a replacement may go. */
    private const int LEVEL_WINDOW = 1;

    /**
     * Alignment-score granularity for ranking, in points. Differences finer
     * than this are noise, so cost is allowed to break the tie instead.
     */
    private const float FIT_BAND = 0.5;

    public function __construct(
        private readonly CarWearService $carWear,
        private readonly PhaMatchService $pha,
        private readonly PartUpgradeAdvisorService $upgrade,
    ) {
    }

    /**
     * @param list<array{part: string, level: int, start: int, est: float, end: float}> $flaggedParts
     * @param array<string, mixed> $carData full GetCar payload (with *Options arrays)
     * @param array<string, array{
     *     level: int, start: int, est: float, end: float, track_base: float
     * }> $wearParts
     * @param array<string, mixed> $driver mapped driver stats
     * @param array{power: int|float, handling: int|float, acceleration: int|float} $track
     * @param array{power: int|float, handling: int|float, acceleration: int|float} $car
     * @param int $risk driver risk used in the wear projection
     * @param list<int> $groupCarLevels peers' carLevel; empty / small samples skip the band filter
     * @param int $cash manager's available cash; paid options above this are dropped
     * @param array<string, float> $trainingWear per-part wear (%) from a planned testing
     *     session, keyed by part label. A replacement fitted now runs that session too,
     *     so it is charged on every option — including the survivability filter, which
     *     would otherwise green-light a part that dies before the flag. Testing wear is
     *     independent of part level, so the same flat figure applies to every option.
     * @return array<string, array{
     *     current: array{
     *         level: int, start: int, end: float, fit: float, match: string, pha: Pha
     *     },
     *     options: list<SwapOption>
     * }>
     */
    public function advise(
        array $flaggedParts,
        array $carData,
        array $wearParts,
        array $driver,
        array $track,
        array $car,
        int $risk,
        array $groupCarLevels,
        int $cash,
        array $trainingWear = [],
    ): array {
        $driverFactor = $this->carWear->driverFactor($driver);
        $band = $this->operatingBand($groupCarLevels);
        $currentPha = [
            'power'        => (float) $car['power'],
            'handling'     => (float) $car['handling'],
            'acceleration' => (float) $car['acceleration'],
        ];
        $currentFit   = $this->pha->alignmentScore($track, $currentPha);
        $currentMatch = $this->pha->matchLevel($track, $currentPha);
        $out = [];

        foreach ($flaggedParts as $flagged) {
            $part = $flagged['part'];
            if (!isset(CarWearService::PARTS_MAP[$part], $wearParts[$part])) {
                continue;
            }
            $map = CarWearService::PARTS_MAP[$part];
            $rawOptions = $carData[$map['options']] ?? null;
            if (!is_array($rawOptions) || $rawOptions === []) {
                continue;
            }

            $options = $this->optionsForPart(
                $rawOptions,
                $wearParts[$part]['track_base'],
                $driverFactor,
                $risk,
                $part,
                $flagged['level'],
                $track,
                $currentPha,
                $currentFit,
                $cash,
                $band,
                $trainingWear[$part] ?? 0.0,
            );

            $options = $this->flagChoices($this->rank($options));

            $out[$part] = [
                'current' => [
                    'level' => $flagged['level'],
                    'start' => $flagged['start'],
                    'end'   => $flagged['end'],
                    'fit'   => $currentFit,
                    'match' => $currentMatch,
                    'pha'   => $currentPha,
                ],
                'options' => $options,
            ];
        }

        return $out;
    }

    /**
     * Totals for the recommended plan: what it costs and what it does to the
     * car's P/H/A. Rendered server-side so the summary is correct without
     * JavaScript; the cockpit script recomputes the same numbers live as the
     * manager re-picks options.
     *
     * @param array<string, array{options: list<SwapOption>}> $advice output of advise()
     * @param array{power: int|float, handling: int|float, acceleration: int|float} $track
     * @param array{power: int|float, handling: int|float, acceleration: int|float} $car
     * @return array{
     *     cost: int,
     *     track: Pha,
     *     before: array{pha: Pha, fit: float, match: string},
     *     after: array{pha: Pha, fit: float, match: string}
     * }
     */
    public function summarise(array $advice, array $track, array $car): array
    {
        $before = [
            'power'        => (float) $car['power'],
            'handling'     => (float) $car['handling'],
            'acceleration' => (float) $car['acceleration'],
        ];
        $after = $before;
        $cost  = 0;

        foreach ($advice as $plan) {
            foreach ($plan['options'] as $option) {
                if (!$option['recommended']) {
                    continue;
                }
                $cost += $option['cost'];
                foreach (['power', 'handling', 'acceleration'] as $attr) {
                    $after[$attr] += $option['pha_delta'][$attr];
                }
            }
        }

        return [
            'cost'   => $cost,
            'track'  => [
                'power'        => (float) $track['power'],
                'handling'     => (float) $track['handling'],
                'acceleration' => (float) $track['acceleration'],
            ],
            'before' => [
                'pha'   => $before,
                'fit'   => $this->pha->alignmentScore($track, $before),
                'match' => $this->pha->matchLevel($track, $before),
            ],
            'after'  => [
                'pha'   => $after,
                'fit'   => $this->pha->alignmentScore($track, $after),
                'match' => $this->pha->matchLevel($track, $after),
            ],
        ];
    }

    /**
     * One option per reachable level: the cheapest surviving way to have a
     * part at that level fitted for this race.
     *
     * @param array<int, array<string, mixed>> $rawOptions
     * @param array{power: int|float, handling: int|float, acceleration: int|float} $track
     * @param Pha $currentPha
     * @param array{min: ?int, max: ?int} $band
     * @return list<SwapOption>
     */
    private function optionsForPart(
        array $rawOptions,
        float $trackBase,
        float $driverFactor,
        int $risk,
        string $part,
        int $currentLevel,
        array $track,
        array $currentPha,
        float $currentFit,
        int $cash,
        array $band,
        float $trainingWear,
    ): array {
        /** @var array<int, array{is_free: bool, level: int, cost: int, start: float, end: float}> $bestByLevel */
        $bestByLevel = [];

        foreach ($rawOptions as $opt) {
            if (($opt['disabled'] ?? '') === 'true') {
                continue;
            }
            $action = (int) ($opt['value']['value'] ?? 0);
            if ($action === 0) {
                continue;
            }

            $level = (int) ($opt['newLvl'] ?? 0);
            if (!$this->levelReachable($level, $currentLevel, $band)) {
                continue;
            }

            $cost  = (int) ($opt['value']['cost'] ?? 0);
            if ($cost > $cash) {
                continue;
            }

            $start = (float) ($opt['newWear'] ?? 0);
            $end   = $this->carWear->projectEndWear($trackBase, $level, $start, $driverFactor, $risk)
                + $trainingWear;
            if ($end > self::SURVIVE_THRESHOLD) {
                continue;
            }

            $candidate = [
                'is_free' => $action < 0,
                'level'   => $level,
                'cost'    => $cost,
                'start'   => $start,
                'end'     => $end,
            ];
            if (!isset($bestByLevel[$level]) || $this->cheaper($candidate, $bestByLevel[$level])) {
                $bestByLevel[$level] = $candidate;
            }
        }

        $options = [];
        foreach ($bestByLevel as $level => $c) {
            $delta      = $level - $currentLevel;
            $shiftedCar = $this->upgrade->carAfterSwap($currentPha, $part, $delta);
            $fit        = $this->pha->alignmentScore($track, $shiftedCar);

            $options[] = [
                'level'     => $level,
                'delta'     => $delta,
                'cost'      => $c['cost'],
                'is_free'   => $c['is_free'],
                'start'     => $c['start'],
                'end'       => $c['end'],
                'fit'       => $fit,
                'fit_delta' => $fit - $currentFit,
                'tier'      => $this->pha->tierFor($track, $shiftedCar),
                'match'     => $this->pha->matchLevel($track, $shiftedCar),
                'pha'       => $shiftedCar,
                'pha_delta' => $this->phaDelta($currentPha, $shiftedCar),

                // Overwritten by flagChoices() once the whole set is ranked;
                // seeded here so every option carries the full shape.
                'recommended'      => false,
                'recommend_reason' => '',

                'rationale' => $this->rationale($delta, $fit - $currentFit, $c['is_free']),
            ];
        }

        return $options;
    }

    /**
     * PHA alignment first (in coarse bands), cost second.
     *
     * @param list<SwapOption> $options
     * @return list<SwapOption>
     */
    private function rank(array $options): array
    {
        usort($options, fn(array $a, array $b): int => $this->fitBand($b) <=> $this->fitBand($a)
            ?: $b['is_free'] <=> $a['is_free']
            ?: $a['cost'] <=> $b['cost']
            ?: $a['level'] <=> $b['level']);

        return $options;
    }

    /**
     * @param SwapOption $option
     */
    private function fitBand(array $option): int
    {
        return (int) round($option['fit'] / self::FIT_BAND);
    }

    /**
     * Marks the ranked leader, and records whether it led on alignment or
     * merely on price — only a strictly better alignment band is a fit win.
     *
     * @param list<SwapOption> $options
     * @return list<SwapOption>
     */
    private function flagChoices(array $options): array
    {
        $reason = count($options) > 1 && $this->fitBand($options[0]) > $this->fitBand($options[1])
            ? 'fit'
            : 'value';

        foreach ($options as $i => $_) {
            $options[$i]['recommended']      = $i === 0;
            $options[$i]['recommend_reason'] = $i === 0 ? $reason : '';
        }

        return $options;
    }

    /**
     * A free garage spare always beats a paid part at the same level — both
     * are already known to survive the race, so the money buys nothing.
     *
     * @param array{is_free: bool, level: int, cost: int, start: float, end: float} $a
     * @param array{is_free: bool, level: int, cost: int, start: float, end: float} $b
     */
    private function cheaper(array $a, array $b): bool
    {
        if ($a['is_free'] !== $b['is_free']) {
            return $a['is_free'];
        }
        if ($a['cost'] !== $b['cost']) {
            return $a['cost'] < $b['cost'];
        }
        return $a['end'] < $b['end'];
    }

    /**
     * @param array{min: ?int, max: ?int} $band
     */
    private function levelReachable(int $level, int $currentLevel, array $band): bool
    {
        if (abs($level - $currentLevel) > self::LEVEL_WINDOW) {
            return false;
        }
        if ($level < PartUpgradeAdvisorService::MIN_LEVEL || $level > PartUpgradeAdvisorService::MAX_LEVEL) {
            return false;
        }
        return $this->levelWithinBand($level, $band);
    }

    /**
     * @param Pha $before
     * @param Pha $after
     * @return Pha
     */
    private function phaDelta(array $before, array $after): array
    {
        return [
            'power'        => $after['power'] - $before['power'],
            'handling'     => $after['handling'] - $before['handling'],
            'acceleration' => $after['acceleration'] - $before['acceleration'],
        ];
    }

    /**
     * @param list<int> $groupCarLevels
     * @return array{min: ?int, max: ?int}
     */
    private function operatingBand(array $groupCarLevels): array
    {
        if (count($groupCarLevels) < self::GROUP_MIN_SAMPLE) {
            return ['min' => null, 'max' => null];
        }
        return [
            'min' => min($groupCarLevels) - self::GROUP_BUFFER,
            'max' => max($groupCarLevels) + self::GROUP_BUFFER,
        ];
    }

    /**
     * @param array{min: ?int, max: ?int} $band
     */
    private function levelWithinBand(int $level, array $band): bool
    {
        if ($band['min'] !== null && $level < $band['min']) {
            return false;
        }
        if ($band['max'] !== null && $level > $band['max']) {
            return false;
        }
        return true;
    }

    private function rationale(int $delta, float $fitDelta, bool $isFree): string
    {
        $move = match (true) {
            $delta < 0 => 'One level down',
            $delta > 0 => 'One level up',
            default    => 'Fresh part, same level',
        };
        $price = $isFree ? ' at no cost' : '';
        $shape = match (true) {
            $fitDelta >= self::FIT_BAND / 2  => 'brings your car closer to what this track asks for.',
            $fitDelta <= -self::FIT_BAND / 2 => 'pulls your car further from what this track asks for.',
            default                          => 'leaves your P/H/A balance where it is.',
        };

        return $move . $price . ' — ' . $shape;
    }
}
