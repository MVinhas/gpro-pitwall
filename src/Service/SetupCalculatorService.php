<?php
namespace App\Service;

use App\Database\Database;
use PDO;

class SetupCalculatorService
{
    // Driver Factors from 'Tables.csv' (Rows 17-18)
    // These act as divisors (e.g., Effect = Stat / Factor)
    private const DRIVER_FACTORS = [
        'concentration' => 212,
        'talent' => 181,
        'experience' => 179,
        'technical_insight' => 100, // 'CT' column in Excel usually maps to TI
        'weight' => 0 // Weight usually has a different formula, checking logic below
    ];

    private array $coefficients = [];

    public function __construct(private PDO $db)
    {
        $this->loadCoefficients();
    }

    /**
     * Loads the mathematical coefficients from the database (seeded from 'Tables.csv')
     */
    private function loadCoefficients(): void
    {
        $stmt = $this->db->query("SELECT * FROM car_part_coefficients");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            // Organize by Part Name (e.g., 'Wings') and Type ('level' or 'wear')
            $this->coefficients[$row['part_name']][$row['type']] = $row;
        }
    }

    /**
     * Calculates the ideal setup for a specific track, car, and driver.
     * * @param array $track      Row from 'tracks' table
     * @param array $carLevels  ['chassis' => 1, 'engine' => 1, ...]
     * @param array $driver     Pilot stats ['concentration' => 100, ...]
     * @param array $weather    ['temp' => 25, 'humidity' => 0] (Placeholder for now)
     */
    public function calculateSetup(array $track, array $carLevels, array $driver, array $weather = []): array
    {
        $setup = [
            'f_wing' => 0, 'r_wing' => 0, 'engine' => 0, 'brakes' => 0, 'gear' => 0, 'susp' => 0
        ];

        // 1. Base Track Values
        // Note: Excel logic often combines Base Wings into F_Wing and R_Wing
        $baseWings = $track['base_wings'] ?? 0;
        
        // 2. Calculate Points for each setup component
        // Formula: TrackBase + (CarPartLevel * Coeff) + (DriverStat / DriverFactor)
        
        // --- WINGS (Front & Rear) ---
        // Logic: Base + (ChassisLvl * ChaCoeff) + (WingLvl * WingCoeff) ...
        $setup['f_wing'] = $baseWings 
            + ($this->getCoeff('Wings', 'cha') * ($carLevels['chassis'] ?? 0))
            + ($this->getCoeff('Wings', 'f_wing') * ($carLevels['f_wing'] ?? 0))
            + ($this->getCoeff('Wings', 'r_wing') * ($carLevels['r_wing'] ?? 0))
            + ($this->getCoeff('Wings', 'und') * ($carLevels['underbody'] ?? 0))
            + $this->getCoeff('Wings', 'constant');

        $setup['r_wing'] = $setup['f_wing']; // Usually symmetric base, adjusted by weather/split later

        // --- ENGINE ---
        $setup['engine'] = ($track['base_engine'] ?? 0)
            + ($this->getCoeff('Engine', 'eng') * ($carLevels['engine'] ?? 0))
            + ($this->getCoeff('Engine', 'cool') * ($carLevels['cooling'] ?? 0))
            + ($this->getCoeff('Engine', 'elec') * ($carLevels['electronics'] ?? 0))
            + $this->getCoeff('Engine', 'constant');

        // --- BRAKES ---
        $setup['brakes'] = ($track['base_brakes'] ?? 0)
            + ($this->getCoeff('Brakes', 'cha') * ($carLevels['chassis'] ?? 0))
            + ($this->getCoeff('Brakes', 'brake') * ($carLevels['brakes'] ?? 0))
            + ($this->getCoeff('Brakes', 'elec') * ($carLevels['electronics'] ?? 0))
            + $this->getCoeff('Brakes', 'constant');

        // --- GEAR ---
        $setup['gear'] = ($track['base_gear'] ?? 0)
            + ($this->getCoeff('Gearbox', 'gear') * ($carLevels['gearbox'] ?? 0))
            + ($this->getCoeff('Gearbox', 'elec') * ($carLevels['electronics'] ?? 0))
            + $this->getCoeff('Gearbox', 'constant');

        // --- SUSPENSION ---
        $setup['susp'] = ($track['base_suspension'] ?? 0)
            + ($this->getCoeff('Suspension', 'cha') * ($carLevels['chassis'] ?? 0))
            + ($this->getCoeff('Suspension', 'und') * ($carLevels['underbody'] ?? 0))
            + ($this->getCoeff('Suspension', 'side') * ($carLevels['sidepod'] ?? 0))
            + ($this->getCoeff('Suspension', 'susp') * ($carLevels['suspension'] ?? 0))
            + $this->getCoeff('Suspension', 'constant');

        // 3. Apply Driver Modifiers
        // Note: GPRO formulas usually SUBTRACT the driver factor result from the setup
        // (e.g. Higher talent -> Lower setup points needed?) - Checking Excel logic...
        // Standard logic: Setup += Stat / Factor.
        
        // Driver Offset Calculation
        // ( Simplified based on 'Setup&WS' logic )
        $setup['f_wing'] += ($driver['talent'] ?? 0) / self::DRIVER_FACTORS['talent'];
        $setup['r_wing'] += ($driver['talent'] ?? 0) / self::DRIVER_FACTORS['talent'];
        
        // ... (Additional driver logic would go here) ...

        // Rounding
        return array_map(fn($val) => round($val, 2), $setup);
    }

    private function getCoeff(string $partName, string $column): float
    {
        return (float)($this->coefficients[$partName]['level'][$column] ?? 0);
    }
}