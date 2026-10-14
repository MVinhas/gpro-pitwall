<?php

declare(strict_types=1);

namespace App\Support;

use App\Service\RecruitmentService;

/**
 * The Recruitment screen's settings as URL query keys: target division, the
 * hide-offers toggle, value ranges, sort and page. Remembered per session, so
 * leaving the tab and coming back keeps the manager's filters.
 */
final class RecruitmentQuery
{
    public const string SESSION_KEY = 'recruitment_query';
    public const string DIVISION = 'division_tab';
    public const string NO_OFFERS = 'no_offers';

    /** Columns the results table can sort by. */
    public const array SORT_COLUMNS = [
        'rating', 'NAME', 'OA', 'CON', 'TAL', 'AGG', 'EXP', 'TEI', 'STA', 'CHA', 'MOT', 'WEI', 'AGE', 'SAL', 'FEE',
    ];

    private const array MONEY_FIELDS = ['SAL', 'FEE'];

    /**
     * The query the screen answers: the request's own when it names any
     * recruitment setting, else the one remembered from the last visit (the
     * nav link and the skeleton follow-up carry none). Remembers what it answers.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $session
     * @return array<string, string>
     */
    public static function resolve(array $query, array &$session): array
    {
        $own = self::pick($query);
        $named = array_intersect_key($query, array_flip(self::keys())) !== [];

        if (!$named) {
            $remembered = $session[self::SESSION_KEY] ?? null;

            return is_array($remembered) ? self::pick($remembered) : [];
        }

        $session[self::SESSION_KEY] = $own;

        return $own;
    }

    public static function sortColumn(mixed $column): string
    {
        return in_array($column, self::SORT_COLUMNS, true) ? (string) $column : 'rating';
    }

    public static function sortOrder(mixed $order): string
    {
        return $order === 'asc' ? 'asc' : 'desc';
    }

    /**
     * Every active setting except the page, in the shape links carry.
     *
     * @param array<string, array{min?: int|float, max?: int|float}> $ranges
     * @return array<string, string>
     */
    public static function params(string $division, bool $hideOffers, array $ranges, string $sort, string $order): array
    {
        $params = [self::DIVISION => $division];
        if ($hideOffers) {
            $params[self::NO_OFFERS] = '1';
        }
        foreach ($ranges as $field => $range) {
            foreach ($range as $bound => $value) {
                $params[$bound . '_' . $field] = (string) $value;
            }
        }
        $params['sort'] = $sort;
        $params['order'] = $order;

        return $params;
    }

    /**
     * @param array<string, string> $params
     * @return array<string, string>
     */
    public static function cleared(array $params): array
    {
        return array_intersect_key($params, array_flip([self::DIVISION, 'sort', 'order']));
    }

    /**
     * One chip per active filter, each carrying the params that drop it.
     *
     * @param array<string, array{min?: int|float, max?: int|float}> $ranges
     * @param array<string, string> $params
     * @return list<array{label: string, params: array<string, string>}>
     */
    public static function chips(array $ranges, bool $hideOffers, array $params): array
    {
        $chips = [];
        foreach ($ranges as $field => $range) {
            $chips[] = [
                'label'  => RecruitmentService::RANGE_FILTER_FIELDS[$field] . ' ' . self::describe($field, $range),
                'params' => array_diff_key($params, ['min_' . $field => true, 'max_' . $field => true]),
            ];
        }
        if ($hideOffers) {
            $chips[] = [
                'label'  => 'Hiding drivers with offers',
                'params' => array_diff_key($params, [self::NO_OFFERS => true]),
            ];
        }

        return $chips;
    }

    /** @return list<string> */
    private static function keys(): array
    {
        $keys = [self::DIVISION, self::NO_OFFERS, 'sort', 'order', 'page'];
        foreach (array_keys(RecruitmentService::RANGE_FILTER_FIELDS) as $field) {
            $keys[] = 'min_' . $field;
            $keys[] = 'max_' . $field;
        }

        return $keys;
    }

    /**
     * @param array<mixed> $query
     * @return array<string, string>
     */
    private static function pick(array $query): array
    {
        $keys = array_flip(self::keys());
        $picked = [];
        foreach ($query as $key => $value) {
            if (isset($keys[$key]) && is_string($value) && $value !== '') {
                $picked[(string) $key] = $value;
            }
        }

        return $picked;
    }

    /** @param array{min?: int|float, max?: int|float} $range */
    private static function describe(string $field, array $range): string
    {
        $show = static fn (int|float $v): string => in_array($field, self::MONEY_FIELDS, true)
            ? '$' . number_format($v)
            : (string) $v;

        if (isset($range['min'], $range['max'])) {
            return $show($range['min']) . '–' . $show($range['max']);
        }

        return isset($range['min']) ? '≥ ' . $show($range['min']) : '≤ ' . $show($range['max'] ?? 0);
    }
}
