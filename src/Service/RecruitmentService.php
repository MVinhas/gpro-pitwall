<?php

declare(strict_types=1);

namespace App\Service;

class RecruitmentService
{
    /** Score threshold below which a driver isn't worth showing. */
    private const float MIN_RATING = 50.0;

    /** @var array<string, string> Market field => UI label. */
    public const array RANGE_FILTER_FIELDS = [
        'OA'  => 'Overall Ability',
        'CON' => 'Concentration',
        'TAL' => 'Talent',
        'AGG' => 'Aggressiveness',
        'EXP' => 'Experience',
        'TEI' => 'Technical Insight',
        'STA' => 'Stamina',
        'CHA' => 'Charisma',
        'MOT' => 'Motivation',
        'WEI' => 'Weight',
        'AGE' => 'Age',
        'SAL' => 'Salary',
        'FEE' => 'Fee',
    ];

    /**
     * @param array<string, string> $csvMap
     * @param array<string, int>    $caps
     */
    public function __construct(
        private readonly array $csvMap,
        private array $caps,
        private readonly IdealPilotService $idealPilotService
    ) {
    }

    /**
     * @param list<array<string, mixed>> $market driver rows from `GetMarketFile.asp` JSON
     * @return list<array<string, mixed>>
     */
    public function analyze(array $market, string $targetDivision, bool $filterOffers): array
    {
        $idealData = $this->idealPilotService->getIdealPilot($targetDivision);

        if (empty($idealData['stats']) || $idealData['count'] === 0) {
            throw new \Exception("No baseline data found for division: {$targetDivision}");
        }

        /** @var array<string, int|float> $idealStats */
        $idealStats = $idealData['stats'];
        $candidates = [];

        foreach ($market as $raw) {
            $driver = $this->normalizeDriverData($raw);
            if (!$this->isEligible($driver, $targetDivision, $filterOffers)) {
                continue;
            }
            $rating = $this->calculateRating($driver, $idealStats);
            // Cull below 50 so the result set stays bounded — a full
            // unfiltered market (4-5k drivers) blows the 128 MB heap
            // when held in the session for sort + paginate.
            if ($rating < self::MIN_RATING) {
                continue;
            }
            $driver['rating'] = $rating;
            $candidates[] = $driver;
        }

        return $candidates;
    }

    /**
     * Keeps only supported, non-negative numeric range bounds.
     *
     * @param array<string, mixed> $rawFilters keyed as min_FIELD/max_FIELD
     * @return array<string, array{min?: int|float, max?: int|float}>
     */
    public function normalizeRangeFilters(array $rawFilters): array
    {
        $filters = [];

        foreach (self::RANGE_FILTER_FIELDS as $field => $_label) {
            $range = [];

            foreach (['min', 'max'] as $bound) {
                $raw = $rawFilters[$bound . '_' . $field] ?? null;
                if (
                    is_bool($raw)
                    || !is_scalar($raw)
                    || (is_float($raw) && !is_finite($raw))
                ) {
                    continue;
                }

                $value = trim((string) $raw);
                if ($value === '' || !is_numeric($value)) {
                    continue;
                }

                $number = (float) $value;
                if (!is_finite($number) || $number < 0) {
                    continue;
                }

                $range[$bound] = floor($number) === $number ? (int) $number : $number;
            }

            if (
                isset($range['min'], $range['max'])
                && $range['min'] > $range['max']
            ) {
                continue;
            }

            if ($range !== []) {
                $filters[$field] = $range;
            }
        }

        return $filters;
    }

    /**
     * Applies inclusive ranges with AND semantics.
     *
     * @param list<array<string, mixed>> $drivers
     * @param array<string, array{min?: int|float, max?: int|float}> $ranges
     * @return list<array<string, mixed>>
     */
    public function filterByRanges(array $drivers, array $ranges): array
    {
        if ($ranges === []) {
            return $drivers;
        }

        return array_values(array_filter(
            $drivers,
            static function (array $driver) use ($ranges): bool {
                foreach ($ranges as $field => $range) {
                    $value = $driver[$field] ?? null;
                    if (!is_numeric($value)) {
                        return false;
                    }

                    $number = (float) $value;
                    if (
                        !is_finite($number)
                        || (isset($range['min']) && $number < $range['min'])
                        || (isset($range['max']) && $number > $range['max'])
                    ) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    /**
     * Race trackIds for the current and next season, pulled from a cached
     * Calendar response. Staff-day events (eventType 'SD') reuse trackId 1,
     * so we keep only races (eventType 'R'). Returns empty sets when the
     * calendar isn't available — callers degrade gracefully.
     *
     * @param array<string, mixed> $calendar
     * @return array{current: list<int>, next: list<int>}
     */
    public function seasonRaceTrackIds(array $calendar): array
    {
        return [
            'current' => $this->raceTrackIds($calendar['events'] ?? []),
            'next'    => $this->raceTrackIds($calendar['nextSeasonEvents'] ?? []),
        ];
    }

    /**
     * Tags each driver row with how many of their favourite tracks fall in
     * the current/next season, plus the matching track names for the tooltip.
     * Mutates and returns the rows. Cheap — meant for one page of results,
     * not the whole market.
     *
     * @param list<array<string, mixed>> $drivers
     * @param array{current: list<int>, next: list<int>} $seasonTracks
     * @param array<int, string> $trackNames trackId => display name
     * @return list<array<string, mixed>>
     */
    public function tagFavouriteTracks(array $drivers, array $seasonTracks, array $trackNames): array
    {
        foreach ($drivers as &$driver) {
            $fav = array_map('intval', is_array($driver['FAV'] ?? null) ? $driver['FAV'] : []);

            foreach (['current', 'next'] as $season) {
                $matchIds = array_values(array_intersect($fav, $seasonTracks[$season]));
                $driver['FAV_' . $season] = count($matchIds);
                $driver['FAV_' . $season . '_names'] = array_values(array_filter(
                    array_map(static fn(int $id): string => $trackNames[$id] ?? '', $matchIds),
                ));
            }
        }
        unset($driver);

        return $drivers;
    }

    /**
     * @param mixed $events
     * @return list<int>
     */
    private function raceTrackIds(mixed $events): array
    {
        if (!is_array($events)) {
            return [];
        }

        $ids = [];
        foreach ($events as $event) {
            if (is_array($event) && ($event['eventType'] ?? '') === 'R') {
                $ids[] = (int) ($event['trackId'] ?? 0);
            }
        }

        return array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
    }

    /**
     * @param list<array<string, mixed>> $results
     * @return array{
     *   data: list<array<string, mixed>>,
     *   pagination: array{current: int, total_pages: int, total_items: int}
     * }
     */
    public function sortAndPaginate(array $results, string $sortCol, string $sortOrder, int $page, int $limit): array
    {

        usort($results, function ($a, $b) use ($sortCol, $sortOrder) {
            $valA = $a[$sortCol] ?? 0;
            $valB = $b[$sortCol] ?? 0;

            if ($valA === $valB) {
                return 0;
            }

            if (is_numeric($valA) && is_numeric($valB)) {
                $cmp = ($valA < $valB) ? -1 : 1;
            } else {
                $cmp = strcasecmp((string)$valA, (string)$valB);
            }

            return ($sortOrder === 'desc') ? -$cmp : $cmp;
        });


        $totalItems = count($results);
        $totalPages = (int)ceil($totalItems / $limit);


        $page = max(1, min($page, $totalPages > 0 ? $totalPages : 1));
        $offset = ($page - 1) * $limit;

        return [
            'data' => array_slice($results, $offset, $limit),
            'pagination' => [
                'current' => $page,
                'total_pages' => $totalPages,
                'total_items' => $totalItems
            ]
        ];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function normalizeDriverData(array $raw): array
    {
        $d = [];
        foreach ($raw as $key => $val) {
            // The JSON market payload carries scalars (NAME/ID/OA/CON/...)
            // alongside arrays (FAV is a list of track ids). Keep arrays as
            // arrays; only the scalar fields participate in numeric coercion.
            if (is_array($val)) {
                $d[$key] = $val;
                continue;
            }
            $strVal = (string)$val;

            if (preg_match('/^\d+$/', $strVal)) {
                $d[$key] = (int)$strVal;
            } else {
                $cleaned = preg_replace('/[," ]/', '', $strVal);
                $d[$key] = is_numeric($cleaned) ? (int)$cleaned : $strVal;
            }
        }


        $name = trim((string)($raw['NAME'] ?? 'Unknown'), '"');
        $d['NAME'] = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $d['ID'] = (int)($raw['ID'] ?? 0);

        return $d;
    }

    /**
     * @param array<string, mixed> $d
     */
    private function isEligible(array $d, string $div, bool $filterOffers): bool
    {
        if (($d['RET'] ?? 0) > 0) {
            return false;
        }

        if ($filterOffers && ($d['OFF'] ?? 0) > 0) {
            return false;
        }

        $cap = $this->caps[$div] ?? 999;

        if (($d['OA'] ?? 999) > $cap) {
            return false;
        }

        return true;
    }

    /**
     * Scores a candidate from 0..100 against the division's ideal pilot.
     *
     * Rules:
     *   - Start at 100.
     *   - Each attribute (con, tal, agg, exp, ti, sta, cha, mot): meeting or
     *     exceeding the ideal costs nothing; for every unit BELOW the ideal,
     *     subtract 0.1.
     *   - Age: every year OLDER than the ideal subtracts 2; every year
     *     YOUNGER adds 0.5.
     *   - Weight: every kg HEAVIER than the ideal subtracts 0.5; every kg
     *     LIGHTER adds 0.125.
     *   - Salary / fee don't count.
     *   - Clamp to [0, 100].
     *
     * @param array<string, mixed> $driver
     * @param array<string, int|float> $ideal
     */
    private function calculateRating(array $driver, array $ideal): float
    {
        $score = 100.0;

        foreach ($this->csvMap as $csvKey => $schemaKey) {
            if (in_array($csvKey, ['WEI', 'AGE', 'FAV', 'OFF'], true)) {
                continue;
            }
            if (!isset($ideal[$schemaKey])) {
                continue;
            }
            $actual = (float) ($driver[$csvKey] ?? 0);
            $target = (float) $ideal[$schemaKey];
            if ($actual < $target) {
                $score -= ($target - $actual) * 0.1;
            }
        }

        $idealAge = (int) ($ideal['Age'] ?? 0);
        if ($idealAge > 0) {
            $age = (int) ($driver['AGE'] ?? 0);
            $delta = $age - $idealAge;
            if ($delta > 0) {
                $score -= $delta * 2.0;
            } elseif ($delta < 0) {
                $score += (-$delta) * 0.5;
            }
        }

        $idealWeight = (int) ($ideal['Weight (Kg)'] ?? 0);
        if ($idealWeight > 0) {
            $weight = (int) ($driver['WEI'] ?? 0);
            $delta = $weight - $idealWeight;
            if ($delta > 0) {
                $score -= $delta * 0.5;
            } elseif ($delta < 0) {
                $score += (-$delta) * 0.125;
            }
        }

        return round(max(0.0, min(100.0, $score)), 1);
    }
}
