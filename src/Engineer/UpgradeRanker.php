<?php

declare(strict_types=1);

namespace App\Engineer;

/**
 * @phpstan-import-type Plan from CtrPlanner
 * @phpstan-import-type Round from SeasonBudget
 * @phpstan-type Upgrade array{part: string, from: int, to: int, price: int, net_cost: int, gain: float,
 *   affordable: bool}
 * @phpstan-type Demand array{power: int|float, handling: int|float, acceleration: int|float}
 */
readonly class UpgradeRanker
{
    private const array ATTRIBUTES = ['power', 'handling', 'acceleration'];

    /**
     * @param array<string, Demand> $contribution part label → PHA points one level adds
     */
    public function __construct(private SeasonBudget $budget, private array $contribution)
    {
    }

    /**
     * Every part's best affordable-or-not upgrade, ranked: free or cheaper ones first by gain, the rest by
     * gain per net dollar. Net cost is the new part's price plus the repairs its wear adds to the season,
     * less the repairs the plan already charges; a candidate whose forecast is infeasible is dropped, as is
     * one that adds no PHA on the tracks with a known demand.
     *
     * @param Plan $plan
     * @param list<Round> $rounds the remaining races the plan was built from
     * @param array<int, ?Demand> $demands PHA demand by race number; null when the track is unknown
     * @param array<string, array{level: int, start: int|float, training?: int|float}> $parts
     * @param array<string, ?int> $prices
     * @param array<string, list<array{level: int, price: int}>> $options
     * @param int $budget envelope left for upgrades
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
        int $budget,
    ): array {
        $limit = CtrPlanner::limit($objective);
        $planned = array_sum(array_column($plan['rows'], 'repairs'));

        $candidates = [];
        foreach ($options as $part => $levels) {
            if (!isset($this->contribution[$part], $parts[$part])) {
                continue;
            }
            $from = $parts[$part]['level'];

            foreach ($levels as $option) {
                $gain = $this->gain($this->contribution[$part], $option['level'] - $from, $rounds, $demands);
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
                    'affordable' => $net <= $budget,
                ];
            }
        }

        usort($candidates, static function (array $a, array $b): int {
            $aFree = $a['net_cost'] <= 0;
            $bFree = $b['net_cost'] <= 0;
            if ($aFree !== $bFree) {
                return $aFree ? -1 : 1;
            }

            $order = $aFree
                ? $b['gain'] <=> $a['gain']
                : $b['gain'] / $b['net_cost'] <=> $a['gain'] / $a['net_cost'];

            return $order ?: ($a['part'] <=> $b['part'] ?: $a['to'] <=> $b['to']);
        });

        $out = [];
        foreach ($candidates as $candidate) {
            if (isset($out[$candidate['part']])) {
                continue;
            }
            $candidate['gain'] = round($candidate['gain'], 1);
            $out[$candidate['part']] = $candidate;
        }

        return array_values($out);
    }

    /**
     * @param Demand $contribution
     * @param list<Round> $rounds
     * @param array<int, ?Demand> $demands
     */
    private function gain(array $contribution, int $levels, array $rounds, array $demands): float
    {
        $gain = 0.0;
        foreach ($rounds as $round) {
            $demand = $demands[$round['race']] ?? null;
            if ($demand === null) {
                continue;
            }
            $total = 0.0;
            foreach (self::ATTRIBUTES as $attribute) {
                $total += $demand[$attribute];
            }
            if ($total <= 0) {
                continue;
            }
            foreach (self::ATTRIBUTES as $attribute) {
                $gain += $contribution[$attribute] * $levels * $demand[$attribute] / $total;
            }
        }

        return $gain;
    }
}
