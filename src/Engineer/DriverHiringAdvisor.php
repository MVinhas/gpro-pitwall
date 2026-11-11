<?php

declare(strict_types=1);

namespace App\Engineer;

/**
 * @phpstan-type Candidate array{id: int, name: string, rating: float, oa: int, age: int, salary: int, fee: int,
 *   offers: int, retiring: bool, favourites: int}
 * @phpstan-type Out array{retiring: int, cap: int, age: int, offers: int, salary_average: int,
 *   salary_plan: int, switch_cost: int}
 * @phpstan-import-type BudgetRow from SeasonBudget
 * @phpstan-type HiringMoney array{rows: list<BudgetRow>, cushion: int, target: int, envelope: int, spare: int}
 * @phpstan-type Pick array{id: int, name: string, rating: float, gain: ?float, oa: int, age: int, salary: int,
 *   fee: int, favourites: int, one_off: int, fits: 'plan'|'cuts'|'unknown', cut: int}
 * @phpstan-type Current array{name: string, rating: ?float, oa: int, age: int, salary: int, races_left: int,
 *   retiring: bool, renew_fee: ?int, renew_salary: ?int, extension_blocked: bool}
 * @phpstan-type Extension array{fee: int, salary: int, exact: bool, fits: bool}
 * @phpstan-type Reason 'no_driver'|'leaving_cap'|'leaving_retiring'|'better'|'extension_over_budget'|'contract'
 *   |'extension_budget_short'|'over_cap'|'no_extension'|'retiring_later'|'none'
 * @phpstan-type Verdict 'keep'|'extend'|'replace'|'hire'
 * @phpstan-type DriverAdvice array{verdict: Verdict, reason: Reason, urgent: bool,
 *   current: ?Current, over_cap: bool, cap: ?int, group_average: ?int, extension: ?Extension,
 *   shortlist: list<Pick>, out: Out, top_out: ?string, ranked: bool, market_known: bool, finances_known: bool}
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
     * @param ?Current $current null when no driver is under contract
     * @param ?list<array<string, mixed>> $rated market rows carrying their rating; null when the market or the
     *   baseline is missing
     * @param list<int> $calendarTrackIds track ids of the season's remaining races
     * @param ?HiringMoney $money
     * @return DriverAdvice
     */
    public static function advise(
        ?array $current,
        ?array $rated,
        bool $marketKnown,
        array $calendarTrackIds,
        ?int $cap,
        ?int $groupAverage,
        ?array $money,
        ?int $cash,
        bool $promote,
        int $seasonRacesLeft,
    ): array {
        $out = self::noneOut();
        $shortlist = [];
        if ($rated !== null) {
            ['shortlist' => $shortlist, 'out' => $out] = self::shortlist(
                $rated,
                $calendarTrackIds,
                $current,
                $cap,
                $groupAverage,
                $money,
                $cash,
                $promote,
            );
        }
        $extension = self::extension($current, $cap, $money, $cash);
        [$verdict, $reason] = self::verdict($current, $cap, $extension, $shortlist, $seasonRacesLeft);
        // A driver who could stay is only worth swapping for a better one; one who is leaving needs anyone.
        if ($reason === 'better') {
            $shortlist = array_values(array_filter($shortlist, static fn (array $pick): bool => $pick['gain'] > 0));
        }

        $topOut = null;
        foreach ($out as $key => $count) {
            if ($count > 0 && ($topOut === null || $count > $out[$topOut])) {
                $topOut = $key;
            }
        }

        return [
            'verdict' => $verdict,
            'reason' => $reason,
            'urgent' => $verdict === 'extend' && $current !== null && $current['races_left'] <= self::URGENT_AT,
            'current' => $current,
            'over_cap' => $current !== null && $cap !== null && $current['oa'] > $cap,
            'cap' => $cap,
            'group_average' => $groupAverage,
            'extension' => $extension,
            'shortlist' => $shortlist,
            'out' => $out,
            'top_out' => $topOut,
            'ranked' => $rated !== null,
            'market_known' => $marketKnown,
            'finances_known' => $money !== null,
        ];
    }

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
        $out = self::noneOut();
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
            while ($end < $count && round($top - $open[$end]['rating'], 1) <= self::TIE_BAND) {
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

    /**
     * What signing the candidate costs and whether the season plan can pay for it. The salary change must fit the
     * whole envelope under either objective; the one-off on top may eat into the envelope only for Promote,
     * otherwise it must stay within the spare money. Without a plan only the cash on hand guards the one-off.
     *
     * @param Candidate $candidate
     * @param ?HiringMoney $money null when there is no season plan
     * @return Pick|'salary_plan'|'switch_cost'
     */
    public static function price(
        array $candidate,
        int $currentSalary,
        int $currentRacesLeft,
        ?array $money,
        ?int $cash,
        bool $promote,
        ?float $currentRating,
    ): array|string {
        $oneOff = self::OFFER_COST + $candidate['fee'] + intdiv($currentSalary * $currentRacesLeft, 2);
        $fits = 'unknown';
        $cut = 0;
        if ($money === null) {
            if ($cash !== null && $oneOff > $cash) {
                return 'switch_cost';
            }
        } else {
            $salaryChange = $candidate['salary'] - $currentSalary;
            if (self::drop($money, $salaryChange, 0) > $money['envelope']) {
                return 'salary_plan';
            }
            $total = self::drop($money, $salaryChange, $oneOff);
            if ($total > ($promote ? $money['envelope'] : $money['spare'])) {
                return 'switch_cost';
            }
            $fits = $total <= $money['spare'] ? 'plan' : 'cuts';
            $cut = max(0, $total - $money['spare']);
        }

        return [
            'id' => $candidate['id'],
            'name' => $candidate['name'],
            'rating' => $candidate['rating'],
            'gain' => $currentRating === null ? null : round($candidate['rating'] - $currentRating, 1),
            'oa' => $candidate['oa'],
            'age' => $candidate['age'],
            'salary' => $candidate['salary'],
            'fee' => $candidate['fee'],
            'favourites' => $candidate['favourites'],
            'one_off' => $oneOff,
            'fits' => $fits,
            'cut' => $cut,
        ];
    }

    /**
     * How far a cost lowers the plan's headroom: the one-off from every row, the salary change once per race paid
     * from row $fromRow on. Negative when the cost raises it.
     *
     * @param HiringMoney $money
     */
    public static function drop(array $money, int $salaryChange, int $oneOff, int $fromRow = 0): int
    {
        $lowered = [];
        foreach ($money['rows'] as $i => $row) {
            $row['balance'] -= $oneOff + $salaryChange * max(0, $i - $fromRow + 1);
            $lowered[] = $row;
        }

        return CtrPlanner::headroom($money['rows'], $money['cushion'], $money['target'])
            - CtrPlanner::headroom($lowered, $money['cushion'], $money['target']);
    }

    /**
     * Rules apply in order, first match wins. An extension exists only for a driver it can apply to (see
     * extension()), so "extension !== null" stands for "within the cap, not retiring, not blocked".
     *
     * @param ?Current $current
     * @param ?Extension $extension
     * @param list<Pick> $shortlist
     * @return array{Verdict, Reason}
     */
    private static function verdict(
        ?array $current,
        ?int $cap,
        ?array $extension,
        array $shortlist,
        int $seasonRacesLeft,
    ): array {
        if ($current === null) {
            return ['hire', 'no_driver'];
        }
        $racesLeft = $current['races_left'];
        $overCap = $cap !== null && $current['oa'] > $cap;
        $ending = $racesLeft <= self::EXTEND_AT;
        $gains = array_filter(array_column($shortlist, 'gain'), static fn (?float $g): bool => $g !== null);
        $gain = $gains === [] ? null : max($gains);

        if ($overCap && $ending) {
            return ['replace', 'leaving_cap'];
        }
        if ($current['retiring'] && min($racesLeft, $seasonRacesLeft) <= self::EXTEND_AT) {
            return ['replace', 'leaving_retiring'];
        }
        if ($gain !== null && $gain >= self::SWITCH_GAIN) {
            return ['replace', 'better'];
        }
        if ($ending && $extension !== null) {
            if ($extension['fits']) {
                return ['extend', 'contract'];
            }

            return $shortlist === [] ? ['extend', 'extension_budget_short'] : ['replace', 'extension_over_budget'];
        }
        if ($overCap) {
            return ['keep', 'over_cap'];
        }
        if ($ending && $current['extension_blocked']) {
            return ['keep', 'no_extension'];
        }

        return $current['retiring'] ? ['keep', 'retiring_later'] : ['keep', 'none'];
    }

    /**
     * The best SHORTLIST candidates the money allows; a candidate the money rules hold out is counted in `out`.
     *
     * @param list<array<string, mixed>> $rated
     * @param list<int> $calendarTrackIds
     * @param ?Current $current
     * @param ?HiringMoney $money
     * @return array{shortlist: list<Pick>, out: Out}
     */
    private static function shortlist(
        array $rated,
        array $calendarTrackIds,
        ?array $current,
        ?int $cap,
        ?int $groupAverage,
        ?array $money,
        ?int $cash,
        bool $promote,
    ): array {
        ['open' => $open, 'out' => $out] = self::redLines(
            self::candidates($rated, $calendarTrackIds),
            $cap,
            $groupAverage,
        );
        $shortlist = [];
        foreach (self::rank($open) as $candidate) {
            if (count($shortlist) === self::SHORTLIST) {
                break;
            }
            $pick = self::price(
                $candidate,
                $current['salary'] ?? 0,
                $current['races_left'] ?? 0,
                $money,
                $cash,
                $promote,
                $current['rating'] ?? null,
            );
            if (is_string($pick)) {
                $out[$pick]++;
            } else {
                $shortlist[] = $pick;
            }
        }

        return ['shortlist' => $shortlist, 'out' => $out];
    }

    /**
     * What renewing the current driver costs. Null for no driver, a driver over the cap, a retiring driver, or a
     * contract GPRO will not extend. GPRO's own renewal offer wins over the usual multiples of today's salary.
     *
     * @param ?Current $current
     * @param ?HiringMoney $money
     * @return ?Extension
     */
    private static function extension(?array $current, ?int $cap, ?array $money, ?int $cash): ?array
    {
        if (
            $current === null
            || ($cap !== null && $current['oa'] > $cap)
            || $current['retiring']
            || $current['extension_blocked']
        ) {
            return null;
        }
        $fee = $current['renew_fee'];
        $salary = $current['renew_salary'];
        if ($fee === null || $fee <= 0 || $salary === null || $salary <= 0) {
            $exact = false;
            $fee = (int) round($current['salary'] * self::EXTENSION_FEE);
            $salary = (int) round($current['salary'] * self::EXTENSION_SALARY);
        } else {
            $exact = true;
        }
        $fits = $money === null
            ? $cash === null || $fee <= $cash
            : self::drop($money, $salary - $current['salary'], $fee, $current['races_left']) <= $money['envelope'];

        return ['fee' => $fee, 'salary' => $salary, 'exact' => $exact, 'fits' => $fits];
    }

    /** @return Out */
    private static function noneOut(): array
    {
        return ['retiring' => 0, 'cap' => 0, 'age' => 0, 'offers' => 0, 'salary_average' => 0,
            'salary_plan' => 0, 'switch_cost' => 0];
    }
}
