<?php

declare(strict_types=1);

namespace App\Engineer;

/**
 * @phpstan-type Candidate array{id: int, name: string, rating: float, oa: int, age: int, salary: int, fee: int,
 *   offers: int, retiring: bool, favourites: int}
 * @phpstan-type Out array{retiring: int, cap: int, age: int, offers: int, salary_average: int,
 *   salary_plan: int, switch_cost: int}
 */
final readonly class DriverHiringAdvisor
{
    public const float SWITCH_GAIN = 5.0;
    public const int EXTEND_AT = 5;
    public const int URGENT_AT = 2;
    public const int MAX_AGE = 35;
    public const int OFFER_COST = 500_000;
    public const int CONTRACT_RACES = 17;
    public const float EXTENSION_FEE = 2.04;
    public const float EXTENSION_SALARY = 1.12;
    public const float TIE_BAND = 1.0;
    public const int SHORTLIST = 3;
    public const float MIN_RATING = 50.0;

    /**
     * @param list<array<string, mixed>> $rated market rows carrying their recruitment rating
     * @param list<int> $calendarTrackIds
     * @return list<Candidate>
     */
    public static function candidates(array $rated, array $calendarTrackIds): array
    {
        $candidates = [];
        foreach ($rated as $row) {
            $id = $row['ID'] ?? null;
            $rating = $row['rating'] ?? null;
            $oa = $row['OA'] ?? null;
            $age = $row['AGE'] ?? null;
            $salary = $row['SAL'] ?? null;
            if (!is_numeric($id) || (int) $id <= 0) {
                continue;
            }
            if (!is_numeric($rating) || !is_numeric($oa) || !is_numeric($age) || !is_numeric($salary)) {
                continue;
            }
            if ((float) $rating < self::MIN_RATING) {
                continue;
            }
            $fee = $row['FEE'] ?? 0;
            $offers = $row['OFF'] ?? 0;
            $retiring = $row['RET'] ?? 0;
            $favourites = is_array($row['FAV'] ?? null)
                ? array_unique(array_map('intval', array_filter($row['FAV'], 'is_numeric')))
                : [];
            $name = $row['NAME'] ?? '';

            $candidates[] = [
                'id' => (int) $id,
                'name' => is_string($name) ? $name : '',
                'rating' => (float) $rating,
                'oa' => (int) $oa,
                'age' => (int) $age,
                'salary' => (int) $salary,
                'fee' => is_numeric($fee) ? (int) $fee : 0,
                'offers' => is_numeric($offers) ? (int) $offers : 0,
                'retiring' => is_numeric($retiring) && $retiring > 0,
                'favourites' => count(array_intersect($favourites, $calendarTrackIds)),
            ];
        }

        return $candidates;
    }

    /**
     * Each candidate is held out by the first red line it crosses, and only that one is counted.
     *
     * @param list<Candidate> $candidates
     * @return array{open: list<Candidate>, out: Out}
     */
    public static function redLines(array $candidates, ?int $cap, ?int $groupAverage): array
    {
        $out = ['retiring' => 0, 'cap' => 0, 'age' => 0, 'offers' => 0, 'salary_average' => 0,
            'salary_plan' => 0, 'switch_cost' => 0];
        $open = [];
        foreach ($candidates as $candidate) {
            $line = match (true) {
                $candidate['retiring'] => 'retiring',
                $cap !== null && $candidate['oa'] > $cap => 'cap',
                $candidate['age'] >= self::MAX_AGE => 'age',
                $candidate['offers'] > 0 => 'offers',
                $groupAverage !== null && $candidate['salary'] > $groupAverage => 'salary_average',
                default => null,
            };
            if ($line === null) {
                $open[] = $candidate;
            } else {
                $out[$line]++;
            }
        }

        return ['open' => $open, 'out' => $out];
    }

    /**
     * Best rating first, cut into buckets that each start at the first unplaced driver and hold every driver
     * within TIE_BAND of that driver's rating; inside a bucket more favourite tracks, then a lower salary, win.
     * Anchoring each bucket on its first driver keeps the order well defined, which a pairwise "within the band"
     * comparator would not be.
     *
     * @param list<Candidate> $open
     * @return list<Candidate>
     */
    public static function rank(array $open): array
    {
        usort($open, static fn (array $a, array $b): int => $b['rating'] <=> $a['rating'] ?: $a['id'] <=> $b['id']);

        $ranked = [];
        $count = count($open);
        $start = 0;
        while ($start < $count) {
            $top = $open[$start]['rating'];
            $end = $start + 1;
            while ($end < $count && $top - $open[$end]['rating'] <= self::TIE_BAND) {
                $end++;
            }
            $bucket = array_slice($open, $start, $end - $start);
            usort($bucket, static fn (array $a, array $b): int => $b['favourites'] <=> $a['favourites']
                ?: $a['salary'] <=> $b['salary']
                ?: $a['id'] <=> $b['id']);
            array_push($ranked, ...$bucket);
            $start = $end;
        }

        return $ranked;
    }
}
