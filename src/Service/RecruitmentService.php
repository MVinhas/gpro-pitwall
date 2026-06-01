<?php

declare(strict_types=1);

namespace App\Service;

class RecruitmentService
{
    /** Score threshold below which a driver isn't worth showing. */
    private const float MIN_RATING = 50.0;

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
