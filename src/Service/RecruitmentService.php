<?php

declare(strict_types=1);

namespace App\Service;

class RecruitmentService
{
    public function __construct(
        private array $pilotRecruitmentFactors,
        private readonly array $csvMap,
        private array $caps,
        private readonly IdealPilotService $idealPilotService
    ) {
    }

    public function analyze(string $filePath, string $separator, string $targetDivision, bool $filterOffers): array
    {
        $idealData = $this->idealPilotService->getIdealPilot($targetDivision);

        if (empty($idealData['stats']) || $idealData['count'] === 0) {
            throw new \Exception("No baseline data found for division: {$targetDivision}");
        }

        /** @var array<string, int|float> $idealStats */
        $idealStats = $idealData['stats'];
        $candidates = [];

        if (($handle = fopen($filePath, 'r')) !== false) {
            // Handle optional BOM or metadata lines (e.g. sep=,)
            $header = fgetcsv($handle, 1000, $separator);
            if ($header && str_starts_with($header[0] ?? '', 'sep=')) {
                $header = fgetcsv($handle, 1000, $separator);
            }

            if (!$header) {
                fclose($handle);
                throw new \Exception("Could not read CSV header.");
            }

            // Normalize headers: remove whitespace and BOM
            $header = array_map(fn($h): string => trim((string) $h, "\xEF\xBB\xBF\" \t\n\r\0\x0B"), $header);

            while (($row = fgetcsv($handle, 1000, $separator)) !== false) {
                if (count($row) !== count($header)) {
                    continue;
                }

                $raw = array_combine($header, $row);

                $driver = $this->normalizeDriverData($raw);

                if ($this->isEligible($driver, $targetDivision, $filterOffers)) {
                    $driver['rating'] = $this->calculateRating($driver, $idealStats);

                    // Filter out low ratings to reduce noise
                    if ($driver['rating'] >= 50) {
                        $candidates[] = $driver;
                    }
                }
            }

            fclose($handle);
        }

        return $candidates;
    }

    public function sortAndPaginate(array $results, string $sortCol, string $sortOrder, int $page, int $limit): array
    {
        // 1. Sort
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

        // 2. Paginate
        $totalItems = count($results);
        $totalPages = (int)ceil($totalItems / $limit);

        // Ensure page is valid
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
     */
    private function normalizeDriverData(array $raw): array
    {
        $d = [];
        foreach ($raw as $key => $val) {
            $strVal = (string)$val;
            // Remove thousand separators/spaces for numeric fields
            if (preg_match('/^\d+$/', $strVal)) {
                $d[$key] = (int)$strVal;
            } else {
                // Try to clean numeric string (e.g. "1,000")
                $cleaned = preg_replace('/[," ]/', '', $strVal);
                $d[$key] = is_numeric($cleaned) ? (int)$cleaned : $strVal;
            }
        }

        // Explicitly handle string fields
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
        // Overall Ability (OA) check
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

            // Dynamic Check: Only calculate if we have a defined factor for this attribute.
            // This replaces the hardcoded "in_array" skip list.
            if (!isset($this->pilotRecruitmentFactors[$factorKey])) {
                continue;
            }

            $actual = (float)($driver[$csvKey] ?? 0);
            $target = (float)($ideal[$schemaKey] ?? 0);
            $factor = (float)$this->pilotRecruitmentFactors[$factorKey];

            // Standard "Higher is Better" logic
            if ($actual < $target) {
                $diff = $target - $actual;
                $penalty += $diff * $factor;
            }
        }

        // --- Explicit Logic for Special Attributes ---

        // 1. Weight (Lower is Better)
        // The generic loop above fails for weight because actual(80) > target(50) doesn't trigger penalty.
        if (isset($driver['WEI'])) {
            $weight = (int)$driver['WEI'];
            // Ideal weight is typically very low (e.g. 0 or 51 depending on ideal data source)
            $targetWeight = (float)($ideal['Weight'] ?? 0);

            if ($weight > $targetWeight) {
                // Use absolute value of factor in case it's stored negatively in config
                $wFactor = abs((float)($this->pilotRecruitmentFactors['weight'] ?? 0.0));
                $penalty += ($weight - $targetWeight) * $wFactor;
            }
        }

        // 2. Age Penalty
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

        // 3. Financial Penalty
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
