<?php

declare(strict_types=1);

namespace App\Engineer;

use App\Service\CarWearService;

/**
 * @phpstan-type Round array{race: int, track_name: ?string, base: ?array<string, float>}
 * @phpstan-type BudgetRow array{race: int, track_name: ?string, replaced: list<array{part: string, cost: int}>,
 *   repairs: int, running: int, income: int, balance: int, wear_known: bool}
 * @phpstan-type Forecast array{rows: list<BudgetRow>, partial: list<string>}
 */
final readonly class SeasonBudget
{
    public function __construct(private CarWearService $wear)
    {
    }

    /**
     * Race-by-race balance. A part that would reach $limit during a race is replaced before it
     * (start 0, price charged), the same swap rule as CtrCeiling. Training wear lands on the first
     * round only; a round with unknown track wear carries part wear forward and charges nothing.
     *
     * @param list<Round> $rounds remaining races, next race first
     * @param array<string, array{level: int, start: int|float, training?: int|float}> $parts
     * @param array<string, ?int> $prices
     * @return Forecast
     */
    public function forecast(
        array $rounds,
        array $parts,
        array $prices,
        float $driverFactor,
        int $ctr,
        int $limit,
        int $cash,
        int $committed,
        int $income,
    ): array {
        $wear = [];
        foreach ($parts as $label => $part) {
            $wear[$label] = (float) $part['start'];
        }

        $rows = [];
        $partial = [];
        $balance = $cash;

        foreach ($rounds as $i => $round) {
            $replaced = [];
            $repairs = 0;
            $known = $round['base'] !== null;

            if ($round['base'] === null) {
                $partial[($round['track_name'] ?? 'race ' . $round['race']) . ' wear unknown'] = true;
            } else {
                foreach ($parts as $label => $part) {
                    $training = $i === 0 ? (float) ($part['training'] ?? 0) : 0.0;
                    $raceWear = round(
                        $this->wear->raceWear($round['base'][$label] ?? 0.0, (int) $part['level'], $driverFactor, $ctr),
                        1,
                    );
                    $end = $wear[$label] + $training + $raceWear;

                    if ($end >= $limit) {
                        $cost = $prices[$label] ?? 0;
                        if (($prices[$label] ?? null) === null) {
                            $partial[$label . ' price unknown'] = true;
                        }
                        $replaced[] = ['part' => (string) $label, 'cost' => $cost];
                        $repairs += $cost;
                        $end = $training + $raceWear;
                    }

                    $wear[$label] = $end;
                }
            }

            $balance = $balance - $committed + $income - $repairs;
            $rows[] = [
                'race' => $round['race'],
                'track_name' => $round['track_name'],
                'replaced' => $replaced,
                'repairs' => $repairs,
                'running' => $committed,
                'income' => $income,
                'balance' => $balance,
                'wear_known' => $known,
            ];
        }

        return ['rows' => $rows, 'partial' => array_map('strval', array_keys($partial))];
    }
}
