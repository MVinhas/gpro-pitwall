<?php

declare(strict_types=1);

namespace App\Engineer;

final class SeasonStart
{
    /**
     * Cash the manager held before the season's first race. The earliest ledger line gives it as
     * balance_after - amount, but only when that line belongs to race 1: a mid-season join's earliest
     * line is not the season start. Otherwise the weekend state saved at race 1 stands in.
     *
     * @param list<array<string, mixed>> $seasonLedger user_economy_ledger rows
     * @param list<array<string, mixed>> $weekends user_weekend_state rows for the season
     */
    public static function cash(array $seasonLedger, array $weekends): ?int
    {
        $key = static fn (array $r): array => [(int) $r['race'], (string) $r['occurred_at'], (int) $r['id']];
        usort($seasonLedger, static fn (array $a, array $b): int => $key($a) <=> $key($b));

        $first = $seasonLedger[0] ?? null;
        if ($first !== null && (int) $first['race'] === 1) {
            return (int) $first['balance_after'] - (int) $first['amount'];
        }

        foreach ($weekends as $weekend) {
            if ((int) $weekend['race'] === 1 && $weekend['cash'] !== null) {
                return (int) $weekend['cash'];
            }
        }

        return null;
    }
}
