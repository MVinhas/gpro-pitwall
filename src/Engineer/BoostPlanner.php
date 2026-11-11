<?php

declare(strict_types=1);

namespace App\Engineer;

use App\Service\CarWearService;
use App\Service\WearAdvisorService;

/**
 * How many boost stints the next race is worth. GPRO documents no boost rules, so the model is Micael's account
 * of the game: a stint is three laps driven as if at CTR 100, and a manager has three of them. A driver stuck
 * behind a car banks none of the pace while the game still charges the wear, so blocking cuts the gain and never
 * the cost. KEEP is a tunable estimate, not a GPRO rule: the overtakes per attempt in the telemetry corpus of
 * 2026-11-11, by the track's overtaking rating.
 *
 * @phpstan-import-type Round from SeasonBudget
 * @phpstan-import-type Forecast from SeasonBudget
 * @phpstan-type Parts array<string, array{level: int, start: int|float, track_base: int|float, training?: int|float}>
 * @phpstan-type BoostBlock array{stints: int, why: 'part'|'unpriced'|'cash'|'value', part: ?string, gain: int,
 *   cost: int}
 * @phpstan-type BoostChoice array{stints: int, places: float, gain: int, cost: int, blocked: ?BoostBlock}
 * @phpstan-type Refusal array{why: 'part'|'unpriced'|'cash', part: ?string, cost: int}
 */
final readonly class BoostPlanner
{
    public const int MAX_STINTS = 3;
    public const int LAPS_PER_STINT = 3;
    public const array KEEP = [
        'Very Easy' => 1.0,
        'Easy' => 1.0,
        'Normal' => 0.55,
        'Hard' => 0.40,
        'Very Hard' => 0.30,
    ];

    private const int BOOST_CTR = 100;

    public function __construct(private CarWearService $wear, private SeasonBudget $budget)
    {
    }

    public static function keep(?string $overtaking): float
    {
        return self::KEEP[$overtaking ?? ''] ?? self::KEEP['Normal'];
    }

    public static function hardToPass(?string $overtaking): bool
    {
        return $overtaking === 'Hard' || $overtaking === 'Very Hard';
    }

    /**
     * The places one stint buys at this track, after blocking.
     */
    public static function placesPerStint(
        int $ctr,
        float $secondsPerPointPerLap,
        float $secondsPerPlace,
        ?string $overtaking,
    ): float {
        if ($secondsPerPointPerLap <= 0.0 || $secondsPerPlace <= 0.0) {
            return 0.0;
        }

        $seconds = self::LAPS_PER_STINT * max(0, self::BOOST_CTR - $ctr) * $secondsPerPointPerLap;

        return $seconds * self::keep($overtaking) / $secondsPerPlace;
    }

    /**
     * The stints to run under a plan. A count is ruled out when it takes a part to the limit this race, buys a
     * part with no known price, or adds spend to a race whose balance then misses the plan's cushion (the last
     * race: its end target). Its cost is the repairs it adds to the season forecast, so wear that buys no part
     * before the season ends is free. $mostThatPass takes the highest count left; otherwise the free counts are
     * taken and a paid one only when its gain clears its cost by CtrValue::MARGIN.
     *
     * @param list<Round> $rounds remaining races, next race first
     * @param Parts $parts
     * @param array<string, ?int> $prices
     * @param list<int> $balances the plan's race-by-race balances, in the order of $rounds
     * @return BoostChoice
     */
    public function plan(
        bool $mostThatPass,
        array $rounds,
        array $parts,
        array $prices,
        float $driverFactor,
        int $ctr,
        bool $carLocked,
        int $laps,
        array $balances,
        int $cushion,
        int $target,
        float $placesPerStint,
        int $gainPerStint,
    ): array {
        $limit = WearAdvisorService::THRESHOLD_SWAP;
        $forecast = fn (array $with): array => $this->budget->forecast(
            $rounds,
            $with,
            $prices,
            $driverFactor,
            $ctr,
            $limit,
            0,
            0,
            0,
            $carLocked,
        );
        $plain = $forecast($parts);
        $plainRepairs = array_column($plain['rows'], 'repairs');
        $last = array_key_last($plain['rows']);

        $cost = [0 => 0];
        $refused = [];
        for ($n = 1; $n <= self::MAX_STINTS; $n++) {
            $part = $this->limitingPart($parts, $driverFactor, $ctr, $laps, $n, $carLocked);
            if ($part !== null) {
                $refused[$n] = ['why' => 'part', 'part' => $part, 'cost' => 0];
                continue;
            }

            $boosted = $forecast($this->withBoostWear($parts, $driverFactor, $ctr, $laps, $n));
            $unpriced = array_values(
                array_diff(self::unpriced($boosted['partial']), self::unpriced($plain['partial'])),
            );
            if ($unpriced !== []) {
                $refused[$n] = ['why' => 'unpriced', 'part' => $unpriced[0], 'cost' => 0];
                continue;
            }

            $added = 0;
            $short = 0;
            foreach ($boosted['rows'] as $i => $row) {
                $added += $row['repairs'] - ($plainRepairs[$i] ?? 0);
                $floor = $i === $last ? $target : $cushion;
                if ($added > 0 && isset($balances[$i]) && $balances[$i] - $added < $floor) {
                    $short = max($short, $added);
                }
            }
            if ($short > 0) {
                $refused[$n] = ['why' => 'cash', 'part' => null, 'cost' => $short];
                continue;
            }

            $cost[$n] = $added;
        }

        return self::choose($cost, $refused, $mostThatPass, $placesPerStint, $gainPerStint);
    }

    /**
     * Without a plan nothing can be priced, so only the wear limit holds a stint back.
     *
     * @param Parts $parts
     * @return BoostChoice
     */
    public function withoutPlan(array $parts, float $driverFactor, int $ctr, int $laps, float $placesPerStint): array
    {
        $cost = [0 => 0];
        $refused = [];
        for ($n = 1; $n <= self::MAX_STINTS; $n++) {
            $part = $this->limitingPart($parts, $driverFactor, $ctr, $laps, $n, true);
            if ($part === null) {
                $cost[$n] = 0;
            } else {
                $refused[$n] = ['why' => 'part', 'part' => $part, 'cost' => 0];
            }
        }

        return self::choose($cost, $refused, true, $placesPerStint, 0);
    }

    /**
     * @param non-empty-array<int, int> $cost season repairs each passing count adds, by count
     * @param array<int, Refusal> $refused
     * @return BoostChoice
     */
    private static function choose(
        array $cost,
        array $refused,
        bool $mostThatPass,
        float $placesPerStint,
        int $gainPerStint,
    ): array {
        $pick = 0;
        foreach ($cost as $n => $added) {
            if ($mostThatPass || $added <= 0) {
                $pick = $n;
            }
        }
        if (!$mostThatPass) {
            foreach ($cost as $n => $added) {
                $net = ($n - $pick) * $gainPerStint - ($added - $cost[$pick]);
                if ($n > $pick && $net > CtrValue::MARGIN) {
                    $pick = $n;
                }
            }
        }

        $next = $pick + 1;
        $blocked = null;
        if ($next <= self::MAX_STINTS) {
            $why = $refused[$next] ?? ['why' => 'value', 'part' => null, 'cost' => ($cost[$next] ?? 0) - $cost[$pick]];
            $blocked = ['stints' => $next, 'why' => $why['why'], 'part' => $why['part'], 'gain' => $gainPerStint,
                'cost' => $why['cost']];
        }

        return [
            'stints' => $pick,
            'places' => round($pick * $placesPerStint, 1),
            'gain' => $pick * $gainPerStint,
            'cost' => $cost[$pick],
            'blocked' => $blocked,
        ];
    }

    /**
     * The first part that ends this race below the limit without boost and at or past it with $stints. A part
     * past the limit already is the plan's business: swapped before the race, it carries the boost wear fresh;
     * stuck in a locked car, it smokes whatever the boost.
     *
     * @param Parts $parts
     */
    private function limitingPart(
        array $parts,
        float $driverFactor,
        int $ctr,
        int $laps,
        int $stints,
        bool $carLocked,
    ): ?string {
        $limit = WearAdvisorService::THRESHOLD_SWAP;
        foreach ($parts as $label => $part) {
            $race = (float) ($part['training'] ?? 0) + round(
                $this->wear->raceWear((float) $part['track_base'], (int) $part['level'], $driverFactor, $ctr),
                1,
            );
            $start = (float) $part['start'];
            if (WearAdvisorService::percent($start + $race) >= $limit) {
                if ($carLocked || WearAdvisorService::percent($race) >= $limit) {
                    continue;
                }
                $start = 0.0;
            }

            $extra = $this->boostWear($part, $driverFactor, $ctr, $laps, $stints);
            if (WearAdvisorService::percent($start + $race + $extra) >= $limit) {
                return (string) $label;
            }
        }

        return null;
    }

    /**
     * The boost wear rides on the first round only and on a part swapped before it, as training wear does.
     *
     * @param Parts $parts
     * @return Parts
     */
    private function withBoostWear(array $parts, float $driverFactor, int $ctr, int $laps, int $stints): array
    {
        foreach ($parts as $label => $part) {
            $parts[$label]['training'] = (float) ($part['training'] ?? 0)
                + $this->boostWear($part, $driverFactor, $ctr, $laps, $stints);
        }

        return $parts;
    }

    /**
     * @param array{level: int, start: int|float, track_base: int|float, training?: int|float} $part
     */
    private function boostWear(array $part, float $driverFactor, int $ctr, int $laps, int $stints): float
    {
        $base = (float) $part['track_base'];
        $level = (int) $part['level'];
        $atBoost = $this->wear->raceWear($base, $level, $driverFactor, self::BOOST_CTR);
        $atPlan = $this->wear->raceWear($base, $level, $driverFactor, $ctr);

        return $stints * self::LAPS_PER_STINT / max(1, $laps) * max(0.0, $atBoost - $atPlan);
    }

    /**
     * @param list<string> $partial
     * @return list<string> the parts a forecast bought without a price
     */
    private static function unpriced(array $partial): array
    {
        $parts = [];
        foreach ($partial as $entry) {
            if (str_ends_with($entry, ' price unknown')) {
                $parts[] = substr($entry, 0, -strlen(' price unknown'));
            }
        }

        return $parts;
    }
}
