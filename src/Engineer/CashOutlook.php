<?php

declare(strict_types=1);

namespace App\Engineer;

use App\Calibration\LedgerCategories;

final readonly class CashOutlook
{
    /**
     * @param array<string, mixed>|null $weekendState a user_weekend_state row, JSON columns undecoded
     * @param list<array<string, mixed>> $seasonLedger user_economy_ledger rows for the season
     * @return array{known: bool, committed: ?int, income: ?int, free_cash: ?int, end_committed: ?int}
     */
    public static function from(?array $weekendState, array $seasonLedger, int $cash, int $racesLeft): array
    {
        $unknown = [
            'known' => false,
            'committed' => null,
            'income' => null,
            'free_cash' => null,
            'end_committed' => null,
        ];

        if ($weekendState === null || ($weekendState['source'] ?? null) !== 'snapshot' || $seasonLedger === []) {
            return $unknown;
        }

        $driver = self::salary($weekendState['driver_json'] ?? null);
        $staff = self::salary($weekendState['staff_json'] ?? null);
        $td = ($weekendState['td_json'] ?? null) === null ? 0 : self::salary($weekendState['td_json']);
        if ($driver === null || $staff === null || $td === null) {
            return $unknown;
        }

        $byRace = [];
        foreach ($seasonLedger as $row) {
            $byRace[(int) $row['race']][] = $row;
        }
        $latest = $byRace[max(array_keys($byRace))];

        $committed = $driver + $td + $staff
            - self::sum($latest, 'Facilities')
            - self::sum($latest, 'Tyres');

        $prizeTotal = 0;
        foreach ($byRace as $lines) {
            $prizeTotal += self::sum($lines, 'Prize money');
        }
        $income = self::sum($latest, 'Sponsors') + (int) round($prizeTotal / count($byRace));

        $free = $cash + ($income - $committed) * $racesLeft;

        return [
            'known' => true,
            'committed' => $committed,
            'income' => $income,
            'free_cash' => $free,
            'end_committed' => $free,
        ];
    }

    private static function salary(mixed $json): ?int
    {
        if (!is_string($json)) {
            return null;
        }
        $data = json_decode($json, true);
        $salary = is_array($data) ? ($data['salary'] ?? null) : null;

        return is_int($salary) || is_float($salary) ? (int) $salary : null;
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private static function sum(array $lines, string $category): int
    {
        $total = 0;
        foreach ($lines as $line) {
            if (LedgerCategories::categorise((string) $line['description']) === $category) {
                $total += (int) $line['amount'];
            }
        }

        return $total;
    }
}
