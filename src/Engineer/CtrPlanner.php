<?php

declare(strict_types=1);

namespace App\Engineer;

use App\Service\WearAdvisorService;
use Closure;
use InvalidArgumentException;

/**
 * @phpstan-import-type BudgetRow from SeasonBudget
 * @phpstan-import-type Forecast from SeasonBudget
 * @phpstan-import-type Upgrade from UpgradeRanker
 * @phpstan-import-type CtrNext from CtrValue
 * @phpstan-type CtrValueFigures array{gain: int, cost: int, places: float, pace_known: bool, next: ?CtrNext}
 * @phpstan-type Plan array{
 *   v: 2, ctr: int, ctr_kind: 'highest'|'value'|'safety'|'energy', mode: 'nurse'|'push'|'short', safety: int,
 *   swaps: list<array{part: string, cost: int}>, refused_swap_cost: ?int,
 *   target: int, target_kind: 'cushion'|'start'|'start_out_of_reach'|'reserve'|'reserve_out_of_reach',
 *   cushion: int, season_start: ?int,
 *   envelope: int, shortfall: ?int, rows: list<BudgetRow>, partial: list<string>, race: int, infeasible?: bool,
 *   energy_cap: int, energy_estimate: bool, energy_start: ?int, value: ?CtrValueFigures,
 *   upgrades?: list<Upgrade>}
 */
final readonly class CtrPlanner
{
    public const float PROMOTE_RESERVE = 0.5;

    private const int MAX_CTR = 100;
    private const int PLAN_VERSION = 2;

    public function __construct(private CtrCeiling $ceiling, private SeasonBudget $budget)
    {
    }

    /**
     * The Clear Track Risk to advise, never above the driver's energy cap. A CTR fits the budget when its season
     * forecast keeps every race's balance at or above its target: the cushion, and at the last race the end target
     * (the season start for Stay when it is above the cushion at the CTR being judged). Promote takes the highest
     * value that fits; above the safety ceiling it must also be a real push. Stay weighs every CTR that fits and
     * buys no replacement it cannot price: the lowest one whose higher candidates aren't clearly better value
     * (CtrValue), or the highest one in safety mode near the relegation line. It reads no current CTR, so the advice
     * holds still when the manager follows it. With the car locked, Stay weighs only the CTRs at which every part
     * that can finish this race below the limit does (CtrCeiling::forSurvivors). The cushion is the committed cost
     * plus the average repairs of the races after this one at the CTR being judged, so each candidate carries its
     * own cushion and end target: this race's forced swaps are bought before the race, so counting them would make
     * the cushion drop once the manager follows the advice.
     * The target kind is settled once, at CTR 0, and the plan names the reserve or the start as its end target only
     * when the chosen CTR's cushion is below it.
     *
     * @param list<array{race: int, track_name: ?string, base: ?array<string, float>}> $rounds next race first
     * @param array<string, array{level: int, start: int|float, track_base: int|float, training?: int|float}> $parts
     * @param array<string, ?int> $prices
     * @param ?CtrPolicy $policy null plans with no gain and no energy cap
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
        bool $carLocked = false,
        ?CtrPolicy $policy = null,
    ): array {
        if ($rounds === []) {
            throw new InvalidArgumentException('A plan needs at least one remaining race.');
        }

        $cap = $policy === null ? self::MAX_CTR : max(0, min(self::MAX_CTR, $policy->energyCap));
        $energyEstimate = $policy !== null && $policy->energyEstimate;
        $energyStart = $policy?->energyStart;
        $policy ??= CtrPolicy::none();
        $stay = $objective === SeasonObjective::STAY;
        $limit = WearAdvisorService::THRESHOLD_SWAP;

        $safety = $this->ceiling->for($parts, $driverFactor, $limit, $carLocked);

        /** @var array<int, Forecast> $forecasts */
        $forecasts = [];
        /** @var Closure(int): Forecast $forecastAt */
        $forecastAt = function (int $ctr) use (
            &$forecasts,
            $rounds,
            $parts,
            $prices,
            $driverFactor,
            $limit,
            $cash,
            $committed,
            $income,
            $carLocked,
        ): array {
            return $forecasts[$ctr] ??= $this->budget->forecast(
                $rounds,
                $parts,
                $prices,
                $driverFactor,
                $ctr,
                $limit,
                $cash,
                $committed,
                $income,
                $carLocked,
            );
        };

        /** @var array<int, int> $cushions */
        $cushions = [];
        /** @var Closure(int): int $cushionAt */
        $cushionAt = function (int $ctr) use (&$cushions, $forecastAt, $committed): int {
            return $cushions[$ctr] ??= $committed + self::averageRepairs(array_slice($forecastAt($ctr)['rows'], 1));
        };

        $atZero = $cushionAt(0);
        $floor = null;
        $targetKind = 'cushion';
        if ($objective === SeasonObjective::PROMOTE) {
            $reserve = $seasonStart === null ? 0 : (int) ceil($seasonStart * self::PROMOTE_RESERVE);
            if ($reserve > $atZero) {
                $floor = $reserve;
                $targetKind = 'reserve';
                if (self::headroom($forecastAt(0)['rows'], $atZero, $reserve) < 0) {
                    $floor = null;
                    $targetKind = 'reserve_out_of_reach';
                }
            }
        } elseif ($seasonStart !== null && $seasonStart > $atZero) {
            $floor = $seasonStart;
            $targetKind = 'start';
            if ($stay && self::headroom($forecastAt(0)['rows'], $atZero, $seasonStart) < 0) {
                $floor = null;
                $targetKind = 'start_out_of_reach';
            }
        }
        /** @var Closure(int): int $targetAt */
        $targetAt = static fn (int $ctr): int => $floor === null ? $cushionAt($ctr) : max($floor, $cushionAt($ctr));

        $forced = $this->ceiling->pastLimitAtZero($parts, $driverFactor, $limit);

        $kind = 'highest';
        $value = null;
        if ($stay) {
            $gain = [];
            $repairs = [];
            $unpricedAtZero = self::unpriced($forecastAt(0)['partial']);
            // Locked, a part reaching the limit this race can't be swapped and the forecast charges nothing for it.
            $highest = $carLocked ? $this->ceiling->forSurvivors($parts, $driverFactor, $limit) : self::MAX_CTR;
            for ($c = 0; $c <= $highest; $c++) {
                $forecast = $forecastAt($c);
                // An unpriced replacement is charged $0, so a CTR that buys one would look free.
                $buysUnpriced = $c > 0 && array_diff(self::unpriced($forecast['partial']), $unpricedAtZero) !== [];
                if (!$buysUnpriced && self::withinBudget($forecast, $c, $cushionAt($c), $targetAt($c))) {
                    $gain[$c] = $c * $policy->gainPerPoint;
                    $repairs[$c] = array_sum(array_column($forecast['rows'], 'repairs'));
                }
            }
            $repairsAtZero = array_sum(array_column($forecastAt(0)['rows'], 'repairs'));

            // The pick and the higher CTR the reason quotes both come from the CTRs the driver's energy allows.
            $capped = array_filter($gain, static fn (int $c): bool => $c <= $cap, ARRAY_FILTER_USE_KEY);
            $choice = $capped === [] ? null : CtrValue::choose($capped, $repairs, $repairsAtZero, $policy->safety);
            if ($choice !== null) {
                $kind = $choice['kind'];
                // Energy set the pick only when it sits at the cap and the uncapped value rule would go higher.
                // Safety keeps its own kind: near the relegation line, that is the reason worth giving.
                if (
                    !$policy->safety
                    && $choice['ctr'] === $cap
                    && count($capped) < count($gain)
                    && CtrValue::choose($gain, $repairs, $repairsAtZero, false)['ctr'] > $cap
                ) {
                    $kind = 'energy';
                }
            }
            $ctr = $choice['ctr'] ?? null;
            if ($choice !== null) {
                $value = [
                    'gain' => $choice['gain'],
                    'cost' => $choice['cost'],
                    'places' => round($choice['ctr'] * $policy->placesPerPoint, 1),
                    'pace_known' => $policy->paceKnown,
                    'next' => $choice['next'],
                ];
            }
        } else {
            $highest = function (int $from) use ($forecastAt, $cushionAt, $targetAt, $safety, $forced, $prices): ?int {
                for ($c = $from; $c >= 0; $c--) {
                    if ($this->passes($forecastAt($c), $c, $safety, $forced, $prices, $cushionAt($c), $targetAt($c))) {
                        return $c;
                    }
                }

                return null;
            };
            $ctr = $highest(self::MAX_CTR);
            if ($ctr !== null && $ctr > $cap) {
                $kind = 'energy';
                $ctr = $highest($cap);
            }
        }

        if ($ctr === null) {
            $chosen = $forecastAt(0);
            $cushion = $cushionAt(0);
            $target = $targetAt(0);
            $targetKind = self::namedKind($targetKind, $floor, $cushion);

            return $this->result(
                0,
                'highest',
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
                $cap,
                $energyEstimate,
                $energyStart,
                null,
            );
        }

        $chosen = $forecastAt($ctr);
        $cushion = $cushionAt($ctr);
        $target = $targetAt($ctr);
        $targetKind = self::namedKind($targetKind, $floor, $cushion);
        $mode = $ctr > $safety ? 'push' : 'nurse';
        $swaps = $this->unforced($chosen['rows'][0]['replaced'], $forced);

        $refused = null;
        // A cap at or below the safety ceiling rules out any push, so there is no refused swap to quote.
        if ($mode === 'nurse' && $safety < $cap && !$stay) {
            $above = $this->unforced($forecastAt($safety + 1)['rows'][0]['replaced'], $forced);
            $sum = array_sum(array_column($above, 'cost'));
            $refused = $sum > 0 ? $sum : null;
        }

        return $this->result(
            $ctr,
            $kind,
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
            $cap,
            $energyEstimate,
            $energyStart,
            $value,
        );
    }

    /**
     * @param list<BudgetRow> $rows
     */
    private static function averageRepairs(array $rows): int
    {
        return $rows === [] ? 0 : intdiv(array_sum(array_column($rows, 'repairs')), count($rows));
    }

    /**
     * A reserve or start the chosen CTR's cushion already covers is not the end target worth naming: the cushion is.
     *
     * @param 'cushion'|'start'|'start_out_of_reach'|'reserve'|'reserve_out_of_reach' $kind
     * @return 'cushion'|'start'|'start_out_of_reach'|'reserve'|'reserve_out_of_reach'
     */
    private static function namedKind(string $kind, ?int $floor, int $cushion): string
    {
        return $floor !== null && $cushion >= $floor ? 'cushion' : $kind;
    }

    /**
     * The budget check alone: every row meets its own target, and a forecast that cannot be charged is only
     * accepted at CTR 0, where no lower CTR avoids it.
     *
     * @param Forecast $forecast
     */
    private static function withinBudget(array $forecast, int $ctr, int $cushion, int $endTarget): bool
    {
        return !($forecast['infeasible'] && $ctr > 0) && self::headroom($forecast['rows'], $cushion, $endTarget) >= 0;
    }

    /**
     * @param list<string> $partial
     * @return list<string>
     */
    private static function unpriced(array $partial): array
    {
        return array_values(array_filter(
            $partial,
            static fn (string $entry): bool => str_ends_with($entry, ' price unknown'),
        ));
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
        if (!self::withinBudget($forecast, $ctr, $cushion, $endTarget)) {
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
     * @param 'highest'|'value'|'safety'|'energy' $kind
     * @param 'nurse'|'push'|'short' $mode
     * @param list<array{part: string, cost: int}> $swaps
     * @param 'cushion'|'start'|'start_out_of_reach'|'reserve'|'reserve_out_of_reach' $targetKind
     * @param array{rows: list<BudgetRow>, partial: list<string>, infeasible: bool} $forecast
     * @param non-empty-list<array{race: int, track_name: ?string, base: ?array<string, float>}> $rounds
     * @param ?CtrValueFigures $value
     * @return Plan
     */
    private function result(
        int $ctr,
        string $kind,
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
        int $cap,
        bool $energyEstimate,
        ?int $energyStart,
        ?array $value,
    ): array {
        return [
            'v' => self::PLAN_VERSION,
            'ctr' => $ctr,
            'ctr_kind' => $kind,
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
            'energy_cap' => $cap,
            'energy_estimate' => $energyEstimate,
            'energy_start' => $energyStart,
            'value' => $value,
        ];
    }
}
