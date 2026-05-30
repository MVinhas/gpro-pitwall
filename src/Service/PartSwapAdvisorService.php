<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Turns GPRO's per-part `*Options` arrays (from the GetCar payload) into
 * up to four named recommendations per part the wear advisor has flagged.
 *
 * Each option from GPRO is first re-projected against the upcoming race
 * via CarWearService, classified by post-swap PHA tier, and
 * hard-filtered: cost > cash (free downgrades pass), end-wear > 100 %,
 * level outside the group's observed envelope `[min-1, max+1]` from
 * GetMoneyLevels (skipped when <3 peers observed).
 *
 * The survivors are bucketed into at most four slots:
 *
 *   - free_downgrade  : highest-level free spare that survives
 *   - downgrade       : closest paid lower-level fresh part that survives
 *   - sidegrade       : fresh replacement at the current level
 *   - upgrade         : closest paid higher-level fresh part that survives
 *
 * Empty slots are omitted. Within a slot, ties on level resolve to the
 * candidate with the lowest PHA rank distance (best alignment).
 */
final class PartSwapAdvisorService
{
    /** End-of-race wear (%) below which a part is considered to survive. */
    public const float SURVIVE_THRESHOLD = 100.0;

    /** Group sample size below which the operating-band filter is skipped. */
    private const int GROUP_MIN_SAMPLE = 3;

    /** One-level buffer around the observed group envelope. */
    private const int GROUP_BUFFER = 1;

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
     * @return array<string, array{
     *     current: array{level: int, start: int, end: float},
     *     picks: list<array{
     *         slot: string,
     *         level: int,
     *         cost: int,
     *         end: float,
     *         action_kind: string,
     *         pha_tier: int,
     *         rationale: string,
     *     }>
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
    ): array {
        $driverFactor = $this->carWear->driverFactor($driver);
        $band = $this->operatingBand($groupCarLevels);
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
            $trackBase = $wearParts[$part]['track_base'];

            $picks = $this->pickOptionsForPart(
                $rawOptions,
                $trackBase,
                $driverFactor,
                $risk,
                $part,
                $flagged['level'],
                $track,
                $car,
                $cash,
                $band,
            );

            $out[$part] = [
                'current' => [
                    'level' => $flagged['level'],
                    'start' => $flagged['start'],
                    'end'   => $flagged['end'],
                ],
                'picks'   => $picks,
            ];
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $rawOptions
     * @param array{power: int|float, handling: int|float, acceleration: int|float} $track
     * @param array{power: int|float, handling: int|float, acceleration: int|float} $car
     * @param array{min: ?int, max: ?int} $band
     * @return list<array{
     *   slot: string, level: int, cost: int, end: float,
     *   action_kind: string, pha_tier: int, rationale: string
     * }>
     */
    private function pickOptionsForPart(
        array $rawOptions,
        float $trackBase,
        float $driverFactor,
        int $risk,
        string $part,
        int $currentLevel,
        array $track,
        array $car,
        int $cash,
        array $band,
    ): array {
        $currentTier = $this->pha->tierFor($track, $car);
        $candidates = [];

        foreach ($rawOptions as $opt) {
            if (($opt['disabled'] ?? '') === 'true') {
                continue;
            }
            $action = (int) ($opt['value']['value'] ?? 0);
            if ($action === 0) {
                continue;
            }

            $level = (int) ($opt['newLvl'] ?? 0);
            $cost  = (int) ($opt['value']['cost'] ?? 0);
            $start = (float) ($opt['newWear'] ?? 0);
            $end   = $this->carWear->projectEndWear($trackBase, $level, $start, $driverFactor, $risk);

            if ($cost > $cash) {
                continue;
            }
            if ($end > self::SURVIVE_THRESHOLD) {
                continue;
            }
            if (!$this->levelWithinBand($level, $band)) {
                continue;
            }

            $delta      = $level - $currentLevel;
            $shiftedCar = $this->upgrade->carAfterSwap($car, $part, $delta);
            $tier       = $this->pha->tierFor($track, $shiftedCar);
            $actionKind = $delta > 0 ? 'upgrade' : ($delta < 0 ? 'downgrade' : 'refresh');

            $candidates[] = [
                'is_free'     => $action < 0,
                'level'       => $level,
                'cost'        => $cost,
                'end'         => $end,
                'action_kind' => $actionKind,
                'pha_tier'   => $tier,
            ];
        }

        $picks = [];

        $free = $this->pickFreeDowngrade($candidates, $currentLevel);
        if ($free !== null) {
            $picks[] = $this->finalisePick('free_downgrade', $free, $currentTier);
        }

        $down = $this->pickClosestPaid($candidates, $currentLevel, downgrade: true);
        if ($down !== null) {
            $picks[] = $this->finalisePick('downgrade', $down, $currentTier);
        }

        $side = $this->pickAtLevel($candidates, $currentLevel, paidOnly: true);
        if ($side !== null) {
            $picks[] = $this->finalisePick('sidegrade', $side, $currentTier);
        }

        $up = $this->pickClosestPaid($candidates, $currentLevel, downgrade: false);
        if ($up !== null) {
            $picks[] = $this->finalisePick('upgrade', $up, $currentTier);
        }

        // Order the four (or fewer) slots by tier (Perfect first), then cost.
        usort(
            $picks,
            static fn(array $a, array $b): int
                => $a['pha_tier'] <=> $b['pha_tier'] ?: $a['cost'] <=> $b['cost'],
        );

        return $picks;
    }

    /**
     * @param list<array{is_free: bool, level: int, cost: int, end: float, action_kind: string, pha_tier: int}> $cs
     * @return array{is_free: bool, level: int, cost: int, end: float, action_kind: string, pha_tier: int}|null
     */
    private function pickFreeDowngrade(array $cs, int $currentLevel): ?array
    {
        $best = null;
        foreach ($cs as $c) {
            if (!$c['is_free'] || $c['level'] >= $currentLevel) {
                continue;
            }
            // Highest-level surviving free spare wins; tie on level → lower PHA distance.
            $better = $best === null
                || $c['level'] > $best['level']
                || ($c['level'] === $best['level'] && $c['pha_tier'] < $best['pha_tier']);
            if ($better) {
                $best = $c;
            }
        }
        return $best;
    }

    /**
     * Closest paid lower (downgrade=true) or higher (downgrade=false) level
     * that survives. Tie on level distance → lower PHA distance wins.
     *
     * @param list<array{is_free: bool, level: int, cost: int, end: float, action_kind: string, pha_tier: int}> $cs
     * @return array{is_free: bool, level: int, cost: int, end: float, action_kind: string, pha_tier: int}|null
     */
    private function pickClosestPaid(array $cs, int $currentLevel, bool $downgrade): ?array
    {
        $best = null;
        foreach ($cs as $c) {
            if ($c['is_free']) {
                continue;
            }
            if ($downgrade && $c['level'] >= $currentLevel) {
                continue;
            }
            if (!$downgrade && $c['level'] <= $currentLevel) {
                continue;
            }
            $bestDist = $best === null ? PHP_INT_MAX : abs($best['level'] - $currentLevel);
            $thisDist = abs($c['level'] - $currentLevel);
            $better = $best === null
                || $thisDist < $bestDist
                || ($thisDist === $bestDist && $c['pha_tier'] < $best['pha_tier']);
            if ($better) {
                $best = $c;
            }
        }
        return $best;
    }

    /**
     * @param list<array{is_free: bool, level: int, cost: int, end: float, action_kind: string, pha_tier: int}> $cs
     * @return array{is_free: bool, level: int, cost: int, end: float, action_kind: string, pha_tier: int}|null
     */
    private function pickAtLevel(array $cs, int $level, bool $paidOnly): ?array
    {
        $best = null;
        foreach ($cs as $c) {
            if ($c['level'] !== $level) {
                continue;
            }
            if ($paidOnly && $c['is_free']) {
                continue;
            }
            if ($best === null || $c['pha_tier'] < $best['pha_tier']) {
                $best = $c;
            }
        }
        return $best;
    }

    /**
     * @param array{is_free: bool, level: int, cost: int, end: float, action_kind: string, pha_tier: int} $c
     * @return array{
     *   slot: string, level: int, cost: int, end: float,
     *   action_kind: string, pha_tier: int, rationale: string
     * }
     */
    private function finalisePick(string $slot, array $c, int $currentTier): array
    {
        return [
            'slot'        => $slot,
            'level'       => $c['level'],
            'cost'        => $c['cost'],
            'end'         => $c['end'],
            'action_kind' => $c['action_kind'],
            'pha_tier'   => $c['pha_tier'],
            'rationale'   => $this->rationale($slot, $c['pha_tier'], $currentTier),
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

    private function rationale(string $slot, int $pickTier, int $currentTier): string
    {
        $perfect = $pickTier === PhaMatchService::TIER_PERFECT;
        $better  = $pickTier < $currentTier;

        return match ($slot) {
            'free_downgrade' => $better
                ? 'Free spare — closer PHA match with the track'
                : 'Free spare — keeps your PHA shape',
            'downgrade'      => $better
                ? 'Cheaper paid spare — closer PHA match'
                : 'Cheaper paid spare — keeps your PHA shape',
            'sidegrade'      => $perfect
                ? 'Fresh part at current level — already PHA-aligned'
                : 'Fresh part at current level — same PHA shape',
            'upgrade'        => $better
                ? 'Upgrade — closer PHA match with the track'
                : 'Upgrade — keeps your PHA shape',
            default          => 'Survives the race within budget',
        };
    }
}
