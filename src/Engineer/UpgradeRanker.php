<?php

declare(strict_types=1);

namespace App\Engineer;

/**
 * @phpstan-import-type Plan from CtrPlanner
 * @phpstan-import-type Round from SeasonBudget
 * @phpstan-import-type BudgetRow from SeasonBudget
 * @phpstan-type Upgrade array{part: string, from: int, to: int, price: int, net_cost: int, gain: float,
 *   affordable: bool}
 * @phpstan-type Demand array{power: int|float, handling: int|float, acceleration: int|float}
 */
readonly class UpgradeRanker
{
    /**
     * @param array<string, Demand> $contribution part label → PHA points one level adds
     */
    public function __construct(private SeasonBudget $budget, private array $contribution)
    {
    }

    /**
     * Every part's best affordable upgrade (its best one when none fits), ranked: free or cheaper ones first
     * by gain, the rest by gain per net dollar. Net cost is the new part's price plus the repairs its wear
     * adds to the season, less the repairs the plan already charges; a candidate whose forecast is infeasible
     * is dropped, as is one that adds no PHA on the tracks with a known demand. Affordable means the upgraded
     * season stays on the plan's targets with the price and $spent paid now.
     *
     * @param Plan $plan
     * @param list<Round> $rounds the remaining races the plan was built from
     * @param array<int, ?Demand> $demands PHA demand by race number; null when the track is unknown
     * @param array<string, array{level: int, start: int|float, training?: int|float}> $parts
     * @param array<string, ?int> $prices
     * @param array<string, list<array{level: int, price: int}>> $options
     * @param int $spent what the plan's optional swaps, training and testing take before the first race
     * @return list<Upgrade>
     */
    public function rank(
        array $plan,
        string $objective,
        array $rounds,
        array $demands,
        array $parts,
        array $prices,
        array $options,
        float $driverFactor,
        int $cash,
        int $committed,
        int $income,
        int $spent,
    ): array {
        // A fresh part the plan can't keep under the limit stays in every candidate's forecast too.
        if ($plan['infeasible'] ?? false) {
            return [];
        }

        $limit = CtrPlanner::limit($objective);
        $planned = array_sum(array_column($plan['rows'], 'repairs'));

        $candidates = [];
        foreach ($options as $part => $levels) {
            if (!isset($this->contribution[$part], $parts[$part])) {
                continue;
            }
            $from = $parts[$part]['level'];

            foreach ($levels as $option) {
                $gain = SeasonPha::gain($this->contribution[$part], $option['level'] - $from, $rounds, $demands);
                if ($gain <= 0) {
                    continue;
                }

                $forecast = $this->budget->forecast(
                    $rounds,
                    [$part => ['level' => $option['level'], 'start' => 0] + $parts[$part]] + $parts,
                    [$part => $option['price']] + $prices,
                    $driverFactor,
                    $plan['ctr'],
                    $limit,
                    $cash,
                    $committed,
                    $income,
                );
                if ($forecast['infeasible']) {
                    continue;
                }

                $net = $option['price'] + array_sum(array_column($forecast['rows'], 'repairs')) - $planned;
                $candidates[] = [
                    'part' => $part,
                    'from' => $from,
                    'to' => $option['level'],
                    'price' => $option['price'],
                    'net_cost' => $net,
                    'gain' => $gain,
                    'affordable' => self::paysUpFront($plan, $forecast['rows'], $option['price'] + $spent),
                ];
            }
        }

        usort($candidates, self::byScore(...));

        // Per part, a level the budget pays for beats a better-scoring one it can't.
        $best = [];
        foreach ($candidates as $candidate) {
            $kept = $best[$candidate['part']] ?? null;
            if ($kept === null || ($candidate['affordable'] && !$kept['affordable'])) {
                $best[$candidate['part']] = $candidate;
            }
        }
        $out = array_values($best);
        usort($out, self::byScore(...));

        return array_map(static function (array $upgrade): array {
            $upgrade['gain'] = round($upgrade['gain'], 1);

            return $upgrade;
        }, $out);
    }

    /**
     * Whether the upgraded season, with $now paid before the first race, still keeps every row on the plan's
     * own targets: the price is paid now, while any repair it saves only lands later.
     *
     * @param Plan $plan
     * @param list<BudgetRow> $rows
     */
    public static function paysUpFront(array $plan, array $rows, int $now): bool
    {
        return self::headroomPaying($plan, $rows, $now) >= 0;
    }

    /**
     * The plan's headroom over $rows with $now paid before the first race; negative by the shortfall.
     *
     * @param Plan $plan
     * @param list<BudgetRow> $rows
     */
    public static function headroomPaying(array $plan, array $rows, int $now): int
    {
        $paid = array_map(static function (array $row) use ($now): array {
            $row['balance'] -= $now;

            return $row;
        }, $rows);

        return CtrPlanner::headroom($paid, $plan['cushion'], $plan['target']);
    }

    /**
     * Free or cheaper first by gain, the rest by gain per net dollar; ties by part label, then level.
     *
     * @param Upgrade $a
     * @param Upgrade $b
     */
    private static function byScore(array $a, array $b): int
    {
        $aFree = $a['net_cost'] <= 0;
        $bFree = $b['net_cost'] <= 0;
        if ($aFree !== $bFree) {
            return $aFree ? -1 : 1;
        }

        $order = $aFree
            ? $b['gain'] <=> $a['gain']
            : $b['gain'] / $b['net_cost'] <=> $a['gain'] / $a['net_cost'];

        return $order ?: ($a['part'] <=> $b['part'] ?: $a['to'] <=> $b['to']);
    }
}
