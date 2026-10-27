<?php

declare(strict_types=1);

namespace App\Engineer;

use App\Service\WearAdvisorService;
use InvalidArgumentException;

/**
 * @phpstan-import-type BudgetRow from SeasonBudget
 * @phpstan-type Plan array{
 *   v: int, ctr: int, mode: 'nurse'|'push'|'short', safety: int,
 *   swaps: list<array{part: string, cost: int}>, refused_swap_cost: ?int,
 *   target: int, target_kind: 'cushion'|'start'|'start_out_of_reach', cushion: int, season_start: ?int,
 *   envelope: int, shortfall: ?int, rows: list<BudgetRow>, partial: list<string>, race: int}
 */
final readonly class CtrPlanner
{
    private const int MAX_CTR = 100;

    public function __construct(private CtrCeiling $ceiling, private SeasonBudget $budget)
    {
    }

    /**
     * The Clear Track Risk to advise: the highest value whose season forecast keeps every race's balance at
     * or above the target. It reads no current CTR, so the advice holds still when the manager follows it.
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
        $limit = $survive ? WearAdvisorService::THRESHOLD_RISKY : WearAdvisorService::THRESHOLD_SWAP;

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
            if ($survive && $this->minBalance($forecastAt(0)['rows']) < $target) {
                $target = $cushion;
                $targetKind = 'start_out_of_reach';
            }
        }

        $chosen = null;
        $ctr = 0;
        for ($c = $survive ? $safety : self::MAX_CTR; $c >= 0; $c--) {
            $forecast = $forecastAt($c);
            if ($this->minBalance($forecast['rows']) >= $target) {
                $chosen = $forecast;
                $ctr = $c;
                break;
            }
        }

        $forced = $this->ceiling->pastLimitAtZero($parts, $driverFactor, $limit);

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
                $target - $this->minBalance($chosen['rows']),
                $chosen,
                $rounds,
            );
        }

        $mode = $ctr > $safety ? 'push' : 'nurse';
        $swaps = $this->unforced($chosen['rows'][0]['replaced'], $forced);

        $refused = null;
        if ($mode === 'nurse' && $safety < self::MAX_CTR && !$survive) {
            $above = $this->unforced($forecastAt($safety + 1)['rows'][0]['replaced'], $forced);
            $refused = array_sum(array_column($above, 'cost'));
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
            max(0, $this->minBalance($chosen['rows']) - $target),
            null,
            $chosen,
            $rounds,
        );
    }

    /**
     * @param list<BudgetRow> $rows
     */
    private function minBalance(array $rows): int
    {
        return min(PHP_INT_MAX, ...array_column($rows, 'balance'));
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
     * @param array{rows: list<BudgetRow>, partial: list<string>} $forecast
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
        ];
    }
}
