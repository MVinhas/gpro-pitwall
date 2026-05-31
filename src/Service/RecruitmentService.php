<?php

declare(strict_types=1);

namespace App\Service;

class RecruitmentService
{
    /**
     * @param array<string, float>  $pilotRecruitmentFactors
     * @param array<string, string> $csvMap
     * @param array<string, int>    $caps
     */
    public function __construct(
        private array $pilotRecruitmentFactors,
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

            if ($this->isEligible($driver, $targetDivision, $filterOffers)) {
                $driver['rating'] = $this->calculateRating($driver, $idealStats);
                if ($driver['rating'] >= 50) {
                    $candidates[] = $driver;
                }
            }
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
     * @param array<string, mixed> $driver
     * @param array<string, int|float> $ideal
     */
    private function calculateRating(array $driver, array $ideal): float
    {
        $penalty = 0.0;

        foreach ($this->csvMap as $csvKey => $schemaKey) {
            $factorKey = strtolower((string) $schemaKey);

            if (!isset($this->pilotRecruitmentFactors[$factorKey])) {
                continue;
            }

            $actual = (float)($driver[$csvKey] ?? 0);
            $target = (float)($ideal[$schemaKey] ?? 0);
            $factor = (float)$this->pilotRecruitmentFactors[$factorKey];

            if ($actual < $target) {
                $diff = $target - $actual;
                $penalty += $diff * $factor;
            }
        }





        if (isset($driver['WEI'])) {
            $weight = (int)$driver['WEI'];

            $targetWeight = (float)($ideal['Weight'] ?? 0);

            if ($weight > $targetWeight) {
                $wFactor = abs((float)($this->pilotRecruitmentFactors['weight'] ?? 0.0));
                $penalty += ($weight - $targetWeight) * $wFactor;
            }
        }


        $age = (int)($driver['AGE'] ?? 0);
        if ($age <= 28) {
            $maxBonus = -2.0;
            $penalty += $maxBonus * ((28 - $age) / 10);
        } elseif ($age <= 34) {
            $startPenalty = 0.2;
            $endPenalty   = 3.4;
            $penalty += $startPenalty + ($endPenalty - $startPenalty) * (($age - 29) / 5);
        } else {
            $penalty += 3.4 + (($age - 35) * 1);
        }


        $fee = (int)($driver['FEE'] ?? 0);
        if ($fee > 300000) {
            $penalty += (($fee - 300000) / 100000) * 0.5;
        }

        $salary = (int)($driver['SAL'] ?? 0);
        if ($salary > 500000) {
            $penalty += (($salary - 500000) / 100000) * 0.5;
        }

        $rating = 100.0 - $penalty;
        return max(0, round($rating, 1));
    }
}
