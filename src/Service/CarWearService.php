<?php
namespace App\Service;

use PDO;

class CarWearService
{
    private array $wearCoeffs = [];

    public function __construct(private PDO $db)
    {
        $this->loadWearCoefficients();
    }

    private function loadWearCoefficients(): void
    {
        // Load the 'wear' type coefficients seeded from Tables.csv
        $stmt = $this->db->query("SELECT * FROM car_part_coefficients WHERE type = 'wear'");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->wearCoeffs[$row['part_name']] = $row;
        }
    }

    /**
     * Calculates wear estimates based on Track, Car, and Driver.
     */
    public function calculateWear(array $trackData, array $carData, array $driver, int $risk): array
    {
        // 1. Map Track Data (from API or DB)
        $trackId = $trackData['id'] ?? 0;
        
        $stmt = $this->db->prepare("SELECT * FROM tracks WHERE id = :id OR name = :name");
        $stmt->execute([
            ':id' => $trackId,
            ':name' => $trackData['name'] ?? ''
        ]);
        $trackDb = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$trackDb) {
             // Fallback if DB lookup fails (e.g. track name mismatch)
             return ['error' => "Track not found in database: " . ($trackData['name'] ?? 'Unknown ID ' . $trackId)];
        }

        $laps = (int)($trackData['laps'] ?? $trackDb['laps']);
        
        // 2. Calculate Factors
        $riskFactor = 1 + ($risk / 100);

        // Driver Factor (Conc, Tal, Exp)
        $conc = $driver['concentration'] ?? 0;
        $tal = $driver['talent'] ?? 0;
        $exp = $driver['experience'] ?? 0;
        
        // Weighted reduction (Talent usually dominant for wear)
        $driverReduction = ($tal * 0.0005) + ($exp * 0.0003) + ($conc * 0.0002);
        $driverFactor = max(0.5, 1.0 - $driverReduction);

        $results = [];
        
        // Map Label => [DB Column, API Level Key, API Wear Key, Coefficient Group]
        $partsMap = [
            'Chassis' => ['db' => 'wear_chassis', 'lvl' => 'lvlChassis', 'wear' => 'usaChassis', 'coeff' => 'Wings'],
            'Engine' => ['db' => 'wear_engine', 'lvl' => 'lvlEngine', 'wear' => 'usaEngine', 'coeff' => 'Engine'],
            'Front Wing' => ['db' => 'wear_fwing', 'lvl' => 'lvlFWing', 'wear' => 'usaFWing', 'coeff' => 'Wings'],
            'Rear Wing' => ['db' => 'wear_rwing', 'lvl' => 'lvlRWing', 'wear' => 'usaRWing', 'coeff' => 'Wings'],
            'Underbody' => ['db' => 'wear_underbody', 'lvl' => 'lvlUnderbody', 'wear' => 'usaUnderbody', 'coeff' => 'Wings'],
            'Sidepods' => ['db' => 'wear_sidepod', 'lvl' => 'lvlSidepods', 'wear' => 'usaSidepods', 'coeff' => 'Suspension'],
            'Cooling' => ['db' => 'wear_cooling', 'lvl' => 'lvlCooling', 'wear' => 'usaCooling', 'coeff' => 'Engine'],
            'Gearbox' => ['db' => 'wear_gearbox', 'lvl' => 'lvlGear', 'wear' => 'usaGear', 'coeff' => 'Gearbox'],
            'Brakes' => ['db' => 'wear_brakes', 'lvl' => 'lvlBrakes', 'wear' => 'usaBrakes', 'coeff' => 'Brakes'],
            'Suspension' => ['db' => 'wear_suspension', 'lvl' => 'lvlSusp', 'wear' => 'usaSusp', 'coeff' => 'Suspension'],
            'Electronics' => ['db' => 'wear_electronics', 'lvl' => 'lvlElectronics', 'wear' => 'usaElectronics', 'coeff' => 'Engine']
        ];

        foreach ($partsMap as $label => $map) {
            // A. Base Wear for this Track
            $baseWear = (float)($trackDb[$map['db']] ?? 0);
            
            // B. Part Level Adjustment
            $level = (int)($carData[$map['lvl']] ?? 1);
            
            // C. Coefficient (Simplification for now: Level increases wear slightly)
            $levelFactor = 1 + ($level * 0.025);

            // D. Final Calculation
            // Wear = Base * (Laps/Total) * Risk * Driver * Level
            $estWear = $baseWear * ($laps / ($trackDb['laps'] ?: 1)) * $riskFactor * $driverFactor * $levelFactor;

            // Current Wear
            $startWear = (int)($carData[$map['wear']] ?? 0);

            $results[$label] = [
                'level' => $level,
                'start' => $startWear,
                'est' => round($estWear, 1),
                'end' => min(100, $startWear + round($estWear, 1))
            ];
        }

        return [
            'track_name' => $trackDb['name'], // Normalized name from DB
            'laps' => $laps,
            'parts' => $results
        ];
    }
}