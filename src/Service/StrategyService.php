<?php
namespace App\Service;

use PDO;

class StrategyService
{
    public function __construct(private PDO $db) {}

    /**
     * Calculates fuel needed for a stint.
     */
    public function calculateFuel(int $trackId, int $laps, string $weather = 'Dry'): float
    {
        $stmt = $this->db->prepare("SELECT fuel_per_lap, fuel_per_lap_wet FROM tracks WHERE id = :id");
        $stmt->execute([':id' => $trackId]);
        $track = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$track) return 0.0;

        $fpl = ($weather === 'Wet') ? $track['fuel_per_lap_wet'] : $track['fuel_per_lap'];
        
        // Simple: Laps * FPL
        // Advanced: Add Engine/Electronics modifiers if available in DB coefficients
        return round($laps * $fpl, 2);
    }

    /**
     * Estimates tyre wear % for a stint.
     */
    public function calculateTyreWear(int $trackId, int $laps, string $compound, float $temp, array $driver): float
    {
        $stmt = $this->db->prepare("SELECT tyre_wear_factor FROM tracks WHERE id = :id");
        $stmt->execute([':id' => $trackId]);
        $track = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$track) return 0.0;

        // Base distances (km) before 100% wear (Approximate GPRO constants)
        // These should ideally be in 'game_constants' table
        $compoundDistances = [
            'Extra Soft' => 60, // km
            'Soft' => 90,
            'Medium' => 130,
            'Hard' => 200,
            'Rain' => 120
        ];
        
        $baseDist = $compoundDistances[$compound] ?? 100;
        
        // Track Factor (e.g. 0.92 means higher wear, 1.0 normal)
        // In GPRO, 'Very High' wear tracks have LOWER distance factors.
        $trackFactor = $track['tyre_wear_factor'] ?: 1.0; 
        
        // Temp Factor: Hotter = More wear for Softs, Less for Hards? 
        // Simplified: +1% wear per degree over 20C
        $tempFactor = 1 + (max(0, $temp - 20) * 0.01);

        // Driver Weight/Aggressiveness Penalty
        $weight = $driver['weight'] ?? 80;
        $agg = $driver['aggressiveness'] ?? 0;
        $driverFactor = 1 + ($weight * 0.002) + ($agg * 0.001);

        // Calculation
        // Wear % = (Stint Km / (BaseDist * TrackFactor)) * Modifiers * 100
        $stintKm = $laps * 4.5; // Avg lap distance if not fetched
        // Better: Fetch track distance
        // We assume $trackId fetch included 'distance'
        
        // Re-fetch full track for distance
        $stmt = $this->db->prepare("SELECT distance FROM tracks WHERE id = :id");
        $stmt->execute([':id' => $trackId]);
        $tFull = $stmt->fetch(PDO::FETCH_ASSOC);
        $lapDist = $tFull['distance'] ?? 4.5;
        
        $stintKm = $laps * $lapDist;
        
        $maxKm = $baseDist * $trackFactor;
        
        $wearPct = ($stintKm / $maxKm) * $tempFactor * $driverFactor * 100;

        return min(100, round($wearPct, 1));
    }

    public function calculatePitTime(int $trackId, float $fuelLoad, bool $tyresChanged): float
    {
        $stmt = $this->db->prepare("SELECT pit_time FROM tracks WHERE id = :id");
        $stmt->execute([':id' => $trackId]);
        $track = $stmt->fetch(PDO::FETCH_ASSOC);

        $baseTime = $track['pit_time'] ?? 20.0;
        
        // Fueling: ~0.5s per liter (approx)
        $fuelTime = $fuelLoad * 0.5;
        
        // Tyres: ~5s constant
        $tyreTime = $tyresChanged ? 5.0 : 0.0;

        return $baseTime + $fuelTime + $tyreTime;
    }
}