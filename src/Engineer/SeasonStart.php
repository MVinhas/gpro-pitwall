<?php

declare(strict_types=1);

namespace App\Engineer;

final class SeasonStart
{
    /**
     * Cash the manager held before the season's first race. Race 1's ledger lines chain: each line's
     * balance before (balance_after - amount) is the previous line's balance after. The one "before" no
     * line ends on is the chain head, the season start. The chain is read without ordering the lines:
     * a race's lines share one timestamp and arrive newest first, and GPRO's dates don't sort as strings.
     * Lines without a balance (the race analysis) are left out. A mid-season join has no race 1 lines,
     * and a broken chain has no single head; the weekend state saved at race 1 stands in for both.
     *
     * @param list<array<string, mixed>> $seasonLedger user_economy_ledger rows
     * @param list<array<string, mixed>> $weekends user_weekend_state rows for the season
     */
    public static function cash(array $seasonLedger, array $weekends): ?int
    {
        $open = [];
        foreach ($seasonLedger as $line) {
            if ((int) $line['race'] !== 1 || !is_numeric($line['balance_after'] ?? null)) {
                continue;
            }
            $after = (int) $line['balance_after'];
            $before = $after - (int) $line['amount'];
            $open[$before] = ($open[$before] ?? 0) + 1;
            $open[$after] = ($open[$after] ?? 0) - 1;
        }

        $heads = array_filter($open, static fn (int $count): bool => $count > 0);
        if (count($heads) === 1 && reset($heads) === 1) {
            return (int) array_key_first($heads);
        }

        foreach ($weekends as $weekend) {
            if ((int) $weekend['race'] === 1 && $weekend['cash'] !== null) {
                return (int) $weekend['cash'];
            }
        }

        return null;
    }
}
