<?php

declare(strict_types=1);

namespace App\Engineer;

use App\Service\WearAdvisorService;
use InvalidArgumentException;

/**
 * @phpstan-import-type BudgetRow from SeasonBudget
 * @phpstan-import-type Upgrade from UpgradeRanker
 * @phpstan-type Plan array{
 *   v: int, ctr: int, mode: 'nurse'|'push'|'short', safety: int,
 *   swaps: list<array{part: string, cost: int}>, refused_swap_cost: ?int,
 *   target: int, target_kind: 'cushion'|'start'|'start_out_of_reach', cushion: int, season_start: ?int,
 *   envelope: int, shortfall: ?int, rows: list<BudgetRow>, partial: list<string>, race: int, infeasible?: bool,
 *   upgrades?: list<Upgrade>}
 */
final readonly class CtrPlanner
{
    private const int MAX_CTR = 100;

    public function __construct(private CtrCeiling $ceiling, private SeasonBudget $budget)
    {
    }

    /**
     * The wear (%) at which a part counts as gone: Survive plays it safer than the others.
     */
    public static function limit(string $objective): int
    {
        return $objective === SeasonObjective::SURVIVE
            ? WearAdvisorService::THRESHOLD_RISKY
            : WearAdvisorService::THRESHOLD_SWAP;
    }

    /**
     * The Clear Track Risk to advise: the highest value whose season forecast keeps every race's balance at
     * or above its target: the cushion, and at the last race the end target (the season start for Hold and
     * Survive when it is above the cushion). It reads no current CTR, so the advice holds still when the
     * manager follows it.
     *
     * @param list<array{race: int, track_name: ?string, base: ?array<string, float>}> $rounds next race first
     * @param array<string, array{level: int, start: int|float, track_base: int|float, training?: int|float}> $parts
     * @param array<string, ?int> $prices
     * @return Plan
     */
    public function plan(
        string $objective,
        array $rounds,
        array $parts,
        array $prices,
        float $driverFactor,
        int $cash,
        int $committed,
        int $income,
        ?int $seasonStart,
    ): array {
        if ($rounds === []) {
            throw new InvalidArgumentException('A plan needs at least one remaining race.');
        }

        $survive = $objective === SeasonObjective::SURVIVE;
        $limit = self::limit($objective);

        $safety = $this->ceiling->for($parts, $driverFactor, $limit);
        if ($survive) {
            $safety = min($safety, $this->ceiling->for($parts, $driverFactor, WearAdvisorService::THRESHOLD_SWAP));
        }

        $forecastAt = fn (int $ctr): array => $this->budget->forecast(
            $rounds,
            $parts,
            $prices,
            $driverFactor,
            $ctr,
            $limit,
            $cash,
            $committed,
            $income,
        );

        $atSafety = $forecastAt($safety)['rows'];
        $cushion = $committed + intdiv(array_sum(array_column($atSafety, 'repairs')), count($atSafety));

        $target = $cushion;
        $targetKind = 'cushion';
        if ($objective !== SeasonObjective::PROMOTE && $seasonStart !== null && $seasonStart > $cushion) {
            $target = $seasonStart;
            $targetKind = 'start';
            if ($survive && self::headroom($forecastAt(0)['rows'], $cushion, $target) < 0) {
                $target = $cushion;
                $targetKind = 'start_out_of_reach';
            }
        }

        $forced = $this->ceiling->pastLimitAtZero($parts, $driverFactor, $limit);

        $chosen = null;
        $ctr = 0;
        for ($c = $survive ? $safety : self::MAX_CTR; $c >= 0; $c--) {
            $forecast = $forecastAt($c);
            if ($this->passes($forecast, $c, $safety, $forced, $prices, $cushion, $target)) {
                $chosen = $forecast;
                $ctr = $c;
                break;
            }
        }

        if ($chosen === null) {
            $chosen = $forecastAt(0);

            return $this->result(
                $ctr,
                'short',
                $safety,
                [],
                null,
                $target,
                $targetKind,
                $cushion,
                $seasonStart,
                0,
                max(0, -self::headroom($chosen['rows'], $cushion, $target)),
                $chosen,
                $rounds,
            );
        }

        $mode = $ctr > $safety ? 'push' : 'nurse';
        $swaps = $this->unforced($chosen['rows'][0]['replaced'], $forced);

        $refused = null;
        if ($mode === 'nurse' && $safety < self::MAX_CTR && !$survive) {
            $above = $this->unforced($forecastAt($safety + 1)['rows'][0]['replaced'], $forced);
            $sum = array_sum(array_column($above, 'cost'));
            $refused = $sum > 0 ? $sum : null;
        }

        return $this->result(
            $ctr,
            $mode,
            $safety,
            $swaps,
            $refused,
            $target,
            $targetKind,
            $cushion,
            $seasonStart,
            max(0, self::headroom($chosen['rows'], $cushion, $target)),
            null,
            $chosen,
            $rounds,
        );
    }

    /**
     * A CTR passes when every row meets its own target. Above the safety ceiling it must also be a real push:
     * an unforced swap in the next race, every one of them priced, so the budget actually pays for it. CTR 0
     * is never ruled out for a fresh part that would reach the limit in one race: no lower CTR avoids it.
     *
     * @param array{rows: list<BudgetRow>, partial: list<string>, infeasible: bool} $forecast
     * @param list<string> $forced
     * @param array<string, ?int> $prices
     */
    private function passes(
        array $forecast,
        int $ctr,
        int $safety,
        array $forced,
        array $prices,
        int $cushion,
        int $endTarget,
    ): bool {
        if (($forecast['infeasible'] && $ctr > 0) || self::headroom($forecast['rows'], $cushion, $endTarget) < 0) {
            return false;
        }
        if ($ctr <= $safety) {
            return true;
        }

        $swaps = $this->unforced($forecast['rows'][0]['replaced'], $forced);
        foreach ($swaps as $swap) {
            if (($prices[$swap['part']] ?? null) === null) {
                return false;
            }
        }

        return $swaps !== [];
    }

    /**
     * The smallest margin of any row over its target: the cushion, or the end target on the last row.
     * Negative when some row misses its target.
     *
     * @param list<BudgetRow> $rows
     */
    public static function headroom(array $rows, int $cushion, int $endTarget): int
    {
        $last = array_key_last($rows);
        $headroom = PHP_INT_MAX;
        foreach ($rows as $i => $row) {
            $headroom = min($headroom, $row['balance'] - ($i === $last ? $endTarget : $cushion));
        }

        return $headroom;
    }

    /**
     * @param list<array{part: string, cost: int}> $replaced
     * @param list<string> $forced
     * @return list<array{part: string, cost: int}>
     */
    private function unforced(array $replaced, array $forced): array
    {
        return array_values(array_filter(
            $replaced,
            static fn (array $swap): bool => !in_array($swap['part'], $forced, true),
        ));
    }

    /**
     * @param 'nurse'|'push'|'short' $mode
     * @param list<array{part: string, cost: int}> $swaps
     * @param 'cushion'|'start'|'start_out_of_reach' $targetKind
     * @param array{rows: list<BudgetRow>, partial: list<string>, infeasible: bool} $forecast
     * @param non-empty-list<array{race: int, track_name: ?string, base: ?array<string, float>}> $rounds
     * @return Plan
     */
    private function result(
        int $ctr,
        string $mode,
        int $safety,
        array $swaps,
        ?int $refused,
        int $target,
        string $targetKind,
        int $cushion,
        ?int $seasonStart,
        int $envelope,
        ?int $shortfall,
        array $forecast,
        array $rounds,
    ): array {
        return [
            'v' => 1,
            'ctr' => $ctr,
            'mode' => $mode,
            'safety' => $safety,
            'swaps' => $swaps,
            'refused_swap_cost' => $refused,
            'target' => $target,
            'target_kind' => $targetKind,
            'cushion' => $cushion,
            'season_start' => $seasonStart,
            'envelope' => $envelope,
            'shortfall' => $shortfall,
            'rows' => $forecast['rows'],
            'partial' => $forecast['partial'],
            'race' => $rounds[0]['race'],
            'infeasible' => $forecast['infeasible'],
        ];
    }
}
