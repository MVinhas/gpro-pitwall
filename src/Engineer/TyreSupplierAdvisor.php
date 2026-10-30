<?php

declare(strict_types=1);

namespace App\Engineer;

use App\Support\TrackName;

/**
 * @phpstan-import-type Plan from CtrPlanner
 * @phpstan-type TrackConditions array{
 *   tracks: array<string, array{races: int, wet: float, temp_races: int, temp: ?float}>,
 *   all: array{races: int, wet: ?float, temp: ?float}
 * }
 * @phpstan-type RaceConditions array{wet: float, temp: ?float, wear: float}
 * @phpstan-type TyreVerdict 'pick'|'close'|'out_pipirelli'|'out_price'|'out_dry'|'out_budget'|null
 * @phpstan-type TyreRow array{
 *   name: string, cost: int, season_cost: int, total: float,
 *   parts: array{performance: float, temperature: float, durability: float, warmup: float},
 *   verdict: TyreVerdict
 * }
 * @phpstan-type TyreAdvice array{
 *   pick: string, cost: int, best: string, reason: 'best'|'band'|'budget_short', wet_edge: bool,
 *   talent: ?int, baseline: ?int, finances_known: bool, rows: list<TyreRow>
 * }
 */
final readonly class TyreSupplierAdvisor
{
    public const float W_PERFORMANCE = 0.55;
    public const float W_TEMPERATURE = 0.20;
    public const float W_DURABILITY = 0.15;
    public const float W_WARMUP = 0.10;
    public const float WET_EDGE = 1.5;
    public const float BAND = 0.9;
    public const float TEMP_SPAN = 20.0;
    public const float DEFAULT_WET_SHARE = 0.4;
    public const int MIN_WET_RACES = 8;
    public const int MIN_TEMP_RACES = 3;
    public const float WEAR_MIN = 0.5;
    public const float WEAR_MAX = 1.5;
    public const int PRO_PRICE_LINE = 2;

    private const float NEUTRAL = 0.5;

    private const array RED_LINE_DIVISIONS = ['Pro', 'Master', 'Elite'];

    /**
     * One entry per calendar slot, in order. A track with enough races in the corpus uses its own wet rate and
     * temperature, otherwise the corpus-wide figures; wear is the track's tyre wear against the median of the
     * calendar's known tracks, clamped. A track with no data counts as 1.
     *
     * @param list<?string> $trackNames
     * @param TrackConditions $corpus
     * @param array<string, float> $tyreWear keyed by base track name
     * @return list<RaceConditions>
     */
    public static function races(array $trackNames, array $corpus, array $tyreWear): array
    {
        $bases = array_map(
            static fn (?string $name): ?string => $name === null ? null : TrackName::base($name),
            $trackNames,
        );
        $known = [];
        foreach ($bases as $base) {
            if ($base !== null && isset($tyreWear[$base])) {
                $known[] = $tyreWear[$base];
            }
        }
        $median = self::median($known);

        $races = [];
        foreach ($bases as $base) {
            $track = $base === null ? null : ($corpus['tracks'][$base] ?? null);
            $wet = $track !== null && $track['races'] >= self::MIN_WET_RACES
                ? $track['wet']
                : ($corpus['all']['wet'] ?? self::DEFAULT_WET_SHARE);
            $temp = $track !== null && $track['temp_races'] >= self::MIN_TEMP_RACES && $track['temp'] !== null
                ? $track['temp']
                : $corpus['all']['temp'];
            $wear = $base !== null && isset($tyreWear[$base]) && $median > 0.0
                ? min(self::WEAR_MAX, max(self::WEAR_MIN, $tyreWear[$base] / $median))
                : 1.0;
            $races[] = ['wet' => $wet, 'temp' => $temp, 'wear' => $wear];
        }

        return $races;
    }

    /**
     * The one supplier to sign for the season, or null with no usable supplier or no races. Red lines, then the
     * dry floor (waived by the wet edge), then the whole-season money check, remove suppliers; of the rest, the
     * cheapest within BAND of the best score wins. When nothing is left the cheapest supplier the red lines
     * allow is named instead.
     *
     * @param list<array<string, mixed>> $suppliers the feed's suppliers
     * @param list<RaceConditions> $races
     * @param Plan|null $plan only rows[].balance, cushion and target are read
     * @return TyreAdvice|null
     */
    public static function advise(
        array $suppliers,
        string $division,
        array $races,
        ?int $talent,
        ?int $baselineTalent,
        ?array $plan,
        int $currentTyreCost,
    ): ?array {
        $kept = self::wellFormed($suppliers);
        if ($kept === [] || $races === []) {
            return null;
        }

        $edge = $talent !== null && $baselineTalent !== null && $talent >= $baselineTalent;
        $scaled = self::scale($kept);
        $rows = [];
        foreach ($kept as $i => $supplier) {
            $rows[$i] = self::score($supplier, $scaled[$i], $races, $edge);
        }

        $verdicts = self::redLines($kept, $division);
        if (!$edge) {
            foreach (self::belowDryFloor($kept) as $i) {
                $verdicts[$i] ??= 'out_dry';
            }
        }
        $budgetFailed = false;
        if ($plan !== null) {
            foreach ($kept as $i => $supplier) {
                if (($verdicts[$i] ?? null) === null && !self::affordable($plan, $supplier['cost'], $currentTyreCost)) {
                    $verdicts[$i] = 'out_budget';
                    $budgetFailed = true;
                }
            }
        }

        $open = array_values(array_filter(
            array_keys($kept),
            static fn (int $i): bool => ($verdicts[$i] ?? null) === null,
        ));
        if ($open !== []) {
            $best = $open[0];
            foreach ($open as $i) {
                if ($rows[$i]['total'] > $rows[$best]['total']) {
                    $best = $i;
                }
            }
            $threshold = self::BAND * $rows[$best]['total'];
            $candidates = array_values(array_filter(
                $open,
                static fn (int $i): bool => $rows[$i]['total'] >= $threshold,
            ));
            usort($candidates, static fn (int $a, int $b): int => $kept[$a]['cost'] <=> $kept[$b]['cost']
                ?: $rows[$b]['total'] <=> $rows[$a]['total']);
            $pick = $candidates[0];
            foreach ($candidates as $i) {
                $verdicts[$i] = $i === $pick ? 'pick' : 'close';
            }
            $reason = $pick === $best ? 'best' : 'band';
        } else {
            $allowed = array_values(array_filter(
                array_keys($kept),
                static fn (int $i): bool => !in_array($verdicts[$i] ?? null, ['out_pipirelli', 'out_price'], true),
            ));
            $pool = $allowed !== [] ? $allowed : array_keys($kept);
            usort($pool, static fn (int $a, int $b): int => $kept[$a]['cost'] <=> $kept[$b]['cost']
                ?: $rows[$b]['total'] <=> $rows[$a]['total']);
            $pick = $best = $pool[0];
            $verdicts[$pick] = 'pick';
            $reason = 'budget_short';
        }

        $out = [];
        foreach ($kept as $i => $supplier) {
            $out[] = [
                'name' => $supplier['name'],
                'cost' => $supplier['cost'],
                'season_cost' => $supplier['cost'] * SeasonObjective::RACES,
                'total' => $rows[$i]['total'],
                'parts' => $rows[$i]['parts'],
                'verdict' => $verdicts[$i] ?? null,
            ];
        }
        usort($out, static fn (array $a, array $b): int => self::isOut($a['verdict']) <=> self::isOut($b['verdict'])
            ?: $b['total'] <=> $a['total']);

        return [
            'pick' => $kept[$pick]['name'],
            'cost' => $kept[$pick]['cost'],
            'best' => $kept[$best]['name'],
            'reason' => $reason,
            'wet_edge' => $edge,
            'talent' => $talent,
            'baseline' => $baselineTalent,
            'finances_known' => $plan !== null,
            'rows' => $out,
        ];
    }

    /**
     * @param list<array<string, mixed>> $suppliers
     * @return list<array{name: string, cost: int, peakTemperature: float, dryPerformance: float,
     *   rainPerformance: float, durability: float, warmupDistance: float}>
     */
    private static function wellFormed(array $suppliers): array
    {
        $kept = [];
        foreach ($suppliers as $supplier) {
            $name = $supplier['name'] ?? null;
            $cost = $supplier['cost'] ?? null;
            if (!is_string($name) || $name === '' || !is_numeric($cost) || (int) $cost <= 0) {
                continue;
            }
            $peak = $supplier['peakTemperature'] ?? null;
            $dry = $supplier['dryPerformance'] ?? null;
            $rain = $supplier['rainPerformance'] ?? null;
            $durability = $supplier['durability'] ?? null;
            $warmup = $supplier['warmupDistance'] ?? null;
            $numeric = array_filter([$peak, $dry, $rain, $durability, $warmup], 'is_numeric');
            if (count($numeric) !== 5) {
                continue;
            }
            $kept[] = [
                'name' => $name,
                'cost' => (int) $cost,
                'peakTemperature' => (float) $peak,
                'dryPerformance' => (float) $dry,
                'rainPerformance' => (float) $rain,
                'durability' => (float) $durability,
                'warmupDistance' => (float) $warmup,
            ];
        }

        return $kept;
    }

    /**
     * Each attribute scaled 0 to 1 across the suppliers; one all of them share counts as 0.5 and warm-up
     * distance is inverted, as fewer bars warm up faster.
     *
     * @param list<array{peakTemperature: float, dryPerformance: float, rainPerformance: float, durability: float,
     *   warmupDistance: float}> $kept
     * @return list<array{dry: float, rain: float, durability: float, warmup: float}>
     */
    private static function scale(array $kept): array
    {
        $scale = static function (string $attribute, bool $invert = false) use ($kept): array {
            $min = INF;
            $max = -INF;
            $column = array_column($kept, $attribute);
            foreach ($column as $v) {
                $min = min($min, $v);
                $max = max($max, $v);
            }

            return array_map(static function (float $v) use ($min, $max, $invert): float {
                if ($max === $min) {
                    return self::NEUTRAL;
                }
                $unit = ($v - $min) / ($max - $min);

                return $invert ? 1.0 - $unit : $unit;
            }, $column);
        };
        $dry = $scale('dryPerformance');
        $rain = $scale('rainPerformance');
        $durability = $scale('durability');
        $warmup = $scale('warmupDistance', true);

        $scaled = [];
        foreach (array_keys($kept) as $i) {
            $scaled[] = [
                'dry' => $dry[$i],
                'rain' => $rain[$i],
                'durability' => $durability[$i],
                'warmup' => $warmup[$i],
            ];
        }

        return $scaled;
    }

    /**
     * @param array{peakTemperature: float} $supplier
     * @param array{dry: float, rain: float, durability: float, warmup: float} $scaled
     * @param list<RaceConditions> $races
     * @return array{total: float, parts: array{performance: float, temperature: float, durability: float,
     *   warmup: float}}
     */
    private static function score(array $supplier, array $scaled, array $races, bool $edge): array
    {
        $parts = ['performance' => 0.0, 'temperature' => 0.0, 'durability' => 0.0, 'warmup' => 0.0];
        $total = 0.0;
        foreach ($races as $race) {
            $wet = $edge ? min(1.0, $race['wet'] * self::WET_EDGE) : $race['wet'];
            $performance = (1.0 - $wet) * $scaled['dry'] + $wet * $scaled['rain'];
            $fit = $race['temp'] === null
                ? self::NEUTRAL
                : max(0.0, 1.0 - abs($supplier['peakTemperature'] - $race['temp']) / self::TEMP_SPAN);
            $durability = $scaled['durability'] * $race['wear'];

            $parts['performance'] += $performance;
            $parts['temperature'] += $fit;
            $parts['durability'] += $durability;
            $parts['warmup'] += $scaled['warmup'];
            $total += self::W_PERFORMANCE * $performance + self::W_TEMPERATURE * $fit
                + self::W_DURABILITY * $durability + self::W_WARMUP * $scaled['warmup'];
        }

        return ['total' => $total, 'parts' => $parts];
    }

    /**
     * @param list<array{name: string, cost: int}> $kept
     * @return array<int, 'out_pipirelli'|'out_price'>
     */
    private static function redLines(array $kept, string $division): array
    {
        $verdicts = [];
        if (!in_array($division, self::RED_LINE_DIVISIONS, true)) {
            return $verdicts;
        }
        foreach ($kept as $i => $supplier) {
            if ($supplier['name'] === 'Pipirelli') {
                $verdicts[$i] = 'out_pipirelli';
            }
        }
        if ($division === 'Pro') {
            $byCost = array_keys($kept);
            usort($byCost, static fn (int $a, int $b): int => $kept[$b]['cost'] <=> $kept[$a]['cost']);
            foreach (array_slice($byCost, 0, self::PRO_PRICE_LINE) as $i) {
                $verdicts[$i] ??= 'out_price';
            }
        }

        return $verdicts;
    }

    /**
     * The suppliers at or below the dry-performance value at position floor(n / 3), counted from 1 after sorting
     * ascending; with fewer than three suppliers there is no bottom third.
     *
     * @param list<array{dryPerformance: float}> $kept
     * @return list<int>
     */
    private static function belowDryFloor(array $kept): array
    {
        $position = intdiv(count($kept), 3);
        if ($position < 1) {
            return [];
        }
        $dry = array_column($kept, 'dryPerformance');
        sort($dry);
        $cutoff = $dry[$position - 1];

        return array_keys(array_filter($kept, static fn (array $s): bool => $s['dryPerformance'] <= $cutoff));
    }

    /**
     * Whether the plan's race-by-race balances survive paying this supplier's price over the current one, from
     * the first race on: the plan is not rebuilt per supplier.
     *
     * @param Plan $plan
     */
    private static function affordable(array $plan, int $cost, int $currentTyreCost): bool
    {
        $extra = $cost - $currentTyreCost;
        $lowered = [];
        foreach ($plan['rows'] as $i => $row) {
            $row['balance'] -= $extra * ($i + 1);
            $lowered[] = $row;
        }

        return CtrPlanner::headroom($lowered, $plan['cushion'], $plan['target']) >= 0;
    }

    private static function isOut(?string $verdict): int
    {
        return $verdict !== null && str_starts_with($verdict, 'out_') ? 1 : 0;
    }

    /**
     * @param list<float> $values
     */
    private static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
