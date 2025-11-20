<?php
namespace App\Service;

class RecruitmentService
{
    public function __construct(
        private array $pilotRecruitmentFactors,
        private array $csvMap,
        private array $caps, 
        private IdealPilotService $idealPilotService
    ) {}

    public function analyze(string $filePath, string $separator, string $targetDivision, bool $filterOffers): array
    {
        $idealData = $this->idealPilotService->getIdealPilot($targetDivision);
        if (empty($idealData['stats']) || $idealData['count'] === 0) {
            throw new \Exception("No baseline data found for division: {$targetDivision}");
        }

        $idealStats = $idealData['stats'];
        $candidates = [];
        
        if (($handle = fopen($filePath, 'r')) !== false) {
            $header = fgetcsv($handle, 1000, $separator);
            if ($header && str_starts_with($header[0] ?? '', 'sep=')) {
                $header = fgetcsv($handle, 1000, $separator);
            }
            
            if (!$header) {
                fclose($handle);
                throw new \Exception("Could not read CSV header.");
            }

            $header = array_map(fn($h) => trim($h, '"'), $header);

            while (($row = fgetcsv($handle, 1000, $separator)) !== false) {
                if (count($row) !== count($header)) continue;
                
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

        // Sort by rating descending initially
        usort($candidates, fn($a, $b) => $b['rating'] <=> $a['rating']);

        // FIX: Limit to top 200 results
        return array_slice($candidates, 0, 200);
    }

    private function normalizeDriverData(array $raw): array
    {
        $d = [];
        foreach ($raw as $key => $val) {
            $d[$key] = (int)preg_replace('/[," ]/', '', $val);
        }
        
        $name = trim($raw['NAME'] ?? 'Unknown', '"');
        $d['NAME'] = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $d['ID'] = $raw['ID'] ?? 0; 
        return $d;
    }

    private function isEligible(array $d, string $div, bool $filterOffers): bool
    {
        if (($d['RET'] ?? 0) > 0) return false;
        if ($filterOffers && ($d['OFF'] ?? 0) > 0) return false;
        
        $cap = $this->caps[$div] ?? 999;
        if (($d['OA'] ?? 999) > $cap) return false;

        return true;
    }

    private function calculateRating(array $driver, array $ideal): float
    {
        $penalty = 0.0;

        foreach ($this->csvMap as $csvKey => $schemaKey) {
            if (in_array($csvKey, ['OFF', 'RET', 'AGE', 'WEI', 'FAV'])) continue;

            $actual = (float)($driver[$csvKey] ?? 0);
            $target = (float)($ideal[$schemaKey] ?? 0);
            $factorKey = strtolower($schemaKey);
            $factor = $this->pilotRecruitmentFactors[$factorKey] ?? 0.0;

            if ($actual < $target) {
                $diff = $target - $actual;
                $penalty += $diff * $factor;
            }
        }

        $age = (int)($driver['AGE'] ?? 0);
        //27- the lowest, the better
        //28 is break even
        //29+ is incrementally worse
        if ($age <= 28) {
            $maxBonus = -2.0;  // bonus at age 18
            $penalty += $maxBonus * ((28 - $age) / 10);
        }
        // Age 29–34 → now smoothly approaches 3.4 at age 34
        elseif ($age <= 34) {
            // Linear interpolation from 0.2 (age 29) to 3.4 (age 34)
            $startPenalty = 0.2;  // at 29
            $endPenalty   = 3.4;  // at 34
            $penalty += $startPenalty + ($endPenalty - $startPenalty) * (($age - 29) / 5);
        }
        else {
            $penalty += 3.4 + (($age - 35) * 1);
        }
        $fee = (int)($driver['FEE'] ?? 0);
        if ($fee > 300000) $penalty += (($fee - 300000) / 100000) * 0.5;

        $salary = (int)($driver['SAL'] ?? 0);
        if ($salary > 500000) $penalty += (($salary - 500000) / 100000) * 0.5;

        $rating = 100.0 - $penalty;
        return max(0, round($rating, 1));
    }
}