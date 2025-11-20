<?php
// gpro-driver-analyzer/src/Database/DatabaseSeeder.php

namespace App\Database;

use PDO;
use PDOException;

class DatabaseSeeder
{
    public function __construct(
        private PDO $db,
        private array $statsSchema,
        private array $divisions,
        private array $tracks,
        private string $defaultQ1Risk
    ) {}

    public function migrate(): void
    {
        $this->createPilotsTable();
        $this->createMetadataTable();
        $this->createTrackRisksTable(); // User preferences (Keep this)
        
        // NEW: Game Data Tables
        $this->createTracksTable();           // Static Game Data (From Excel)
        $this->createTrainingsTable();        // Training Gains (From Excel)
        $this->createCarPartCoefficientsTable(); // Setup Math (From Excel Tables)
        $this->createGameConstantsTable();    // Tyre Factors etc (From Excel ReverseCalc)
        
        $this->seedTrainings();               // Insert the static training data
        $this->seedCarPartCoefficients();     // Insert the static setup math
        $this->seedGameConstants();           // Insert tyre/brand factors
    }

    private function createPilotsTable(): void
    {
        $columns = 'id INTEGER PRIMARY KEY AUTOINCREMENT, division TEXT NOT NULL';
        foreach ($this->statsSchema as $column_name) {
            $columns .= ", {$column_name} INTEGER NOT NULL";
        }
        $sql = "CREATE TABLE IF NOT EXISTS pilots ({$columns})";
        $this->db->exec($sql);
    }

    private function createMetadataTable(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS division_metadata (
                division TEXT PRIMARY KEY,
                last_retrieved_season INTEGER NOT NULL DEFAULT 0,
                last_retrieved_race INTEGER NOT NULL DEFAULT 1
            )
        ";
        $this->db->exec($sql);
        // (Keep existing logic for seeding initial metadata)
         $stmt = $this->db->prepare("INSERT OR IGNORE INTO division_metadata (division, last_retrieved_season, last_retrieved_race) VALUES (:div, 0, 1)");
        foreach ($this->divisions as $division) {
            $stmt->execute([':div' => $division]);
        }
    }

    private function createTrackRisksTable(): void
    {
         $q1_columns = '';
        $default_risk_sql = $this->db->quote($this->defaultQ1Risk);
        foreach ($this->divisions as $div) {
            $column_name = 'q1_' . strtolower($div);
            $q1_columns .= ", {$column_name} TEXT NOT NULL DEFAULT {$default_risk_sql}";
        }
        $sql = "
            CREATE TABLE IF NOT EXISTS track_risks (
                track_name TEXT PRIMARY KEY,
                overtaking_risk INTEGER NOT NULL DEFAULT 50,
                defense_risk INTEGER NOT NULL DEFAULT 50
                {$q1_columns}
            )
        ";
        $this->db->exec($sql);
    }


    private function createTracksTable(): void
    {
        // This table mirrors the 'GPRO Version 6 - Tracks' sheet
        $sql = "
            CREATE TABLE IF NOT EXISTS tracks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT UNIQUE NOT NULL,
                laps INTEGER,
                distance REAL,
                avg_speed REAL,
                corners INTEGER,
                pit_time REAL,
                
                -- Base Setup Values
                base_wings INTEGER,
                base_engine INTEGER,
                base_brakes INTEGER,
                base_gear INTEGER,
                base_suspension INTEGER,
                
                -- Track Factors
                fuel_consumption TEXT, -- 'High', 'Low' etc
                tyre_wear TEXT,        -- 'Medium', 'Very High' etc
                wing_split REAL,
                fuel_per_lap REAL,     -- Dry
                fuel_per_lap_wet REAL,
                tyre_wear_factor REAL,
                
                -- Parts Wear Factors (Cha, Eng, FW...)
                wear_chassis INTEGER,
                wear_engine INTEGER,
                wear_fwing INTEGER,
                wear_rwing INTEGER,
                wear_underbody INTEGER,
                wear_sidepod INTEGER,
                wear_cooling INTEGER,
                wear_gearbox INTEGER,
                wear_brakes INTEGER,
                wear_suspension INTEGER,
                wear_electronics INTEGER
            )
        ";
        $this->db->exec($sql);
    }

    private function createTrainingsTable(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS trainings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT UNIQUE NOT NULL,
                cost INTEGER NOT NULL,
                gain_concentration REAL DEFAULT 0,
                gain_talent REAL DEFAULT 0,
                gain_aggressiveness REAL DEFAULT 0,
                gain_experience REAL DEFAULT 0,
                gain_technical_insight REAL DEFAULT 0,
                gain_stamina REAL DEFAULT 0,
                gain_charisma REAL DEFAULT 0,
                gain_motivation REAL DEFAULT 0,
                gain_weight REAL DEFAULT 0
            )
        ";
        $this->db->exec($sql);
    }

    private function createCarPartCoefficientsTable(): void
    {
        // From 'Tables' sheet
        $sql = "
            CREATE TABLE IF NOT EXISTS car_part_coefficients (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                part_name TEXT NOT NULL, -- 'Wings', 'Engine', etc
                type TEXT NOT NULL,      -- 'level' or 'wear'
                cha REAL DEFAULT 0,
                eng REAL DEFAULT 0,
                f_wing REAL DEFAULT 0,
                r_wing REAL DEFAULT 0,
                und REAL DEFAULT 0,
                side REAL DEFAULT 0,
                cool REAL DEFAULT 0,
                gear REAL DEFAULT 0,
                brake REAL DEFAULT 0,
                susp REAL DEFAULT 0,
                elec REAL DEFAULT 0,
                constant REAL DEFAULT 0
            )
        ";
        $this->db->exec($sql);
    }

    private function createGameConstantsTable(): void
    {
        // Generic table for key-value factors from 'ReverseCalc'
        $sql = "
            CREATE TABLE IF NOT EXISTS game_constants (
                category TEXT NOT NULL, -- 'tyre_compound', 'tyre_brand', 'track_wear_level'
                name TEXT NOT NULL,     -- 'Extra Soft', 'Yokomama', 'Very high'
                value REAL NOT NULL,    -- 0.998, 2.0, 4.0
                PRIMARY KEY (category, name)
            )
        ";
        $this->db->exec($sql);
    }

    private function seedTrainings(): void
    {
        // Data extracted from 'Driver Training Planner - Internal'
        $trainings = [
            ['Fitness',   700000, 0,  0, 0,    0, 0,  2,  0, -7.4, -1],
            ['Yoga',      700000, 5,  0, -2,   0, 0, -2,  0,  7.2,  0],
            ['PR',        500000, -3, 0, 0,    0, 0,  0,  6,  0,    0],
            ['Technical', 600000, 0,  0, 0,    0, 5,  0,  0, -24.5, 0],
            ['Psycho',    400000, 0,  0, 0,    0, 0,  0,  0,  16.7, 0],
            ['Ninja',     550000, 1,  0, 4.6,  0, 0,  0,  0,  0,    0],
        ];

        $stmt = $this->db->prepare("INSERT OR IGNORE INTO trainings (name, cost, gain_concentration, gain_talent, gain_aggressiveness, gain_experience, gain_technical_insight, gain_stamina, gain_charisma, gain_motivation, gain_weight) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($trainings as $t) {
            $stmt->execute($t);
        }
    }

    private function seedCarPartCoefficients(): void
    {
        // Data from 'Tables' sheet (Rows 1-5 for Level)
        // part_name, type, cha, eng, f_wing, r_wing, und, side, cool, gear, brake, susp, elec, constant
        $coeffs = [
            ['Wings',      'level', -19.74, 0, 30.03, 30.03, -15.07, 0, 0, 0, 0, 0, 0, 151.50],
            ['Engine',     'level', 0, 16.04, 0, 0, 0, 0, 4.9, 0, 0, 0, 3.34, 145.68],
            ['Brakes',     'level', 6.04, 0, 0, 0, 0, 0, 0, 0, -29.14, 0, 6.11, -101.94],
            ['Gearbox',    'level', 0, 0, 0, 0, 0, 0, 0, -41, 0, 0, 9.00, -192.00],
            ['Suspension', 'level', -15.27, 0, 0, 0, -10.72, 6.03, 0, 0, 0, 31.0, 0, 66.24],
        ];

        $stmt = $this->db->prepare("INSERT OR IGNORE INTO car_part_coefficients (part_name, type, cha, eng, f_wing, r_wing, und, side, cool, gear, brake, susp, elec, constant) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($coeffs as $c) {
            $stmt->execute($c);
        }
    }

    private function seedGameConstants(): void
    {
        // Data from 'ReverseCalc' sheet
        $constants = [
            // Tyre Compounds Factors
            ['tyre_compound', 'Extra Soft', 0.99816375],
            ['tyre_compound', 'Soft',       0.99706484],
            ['tyre_compound', 'Medium',     0.99638035],
            ['tyre_compound', 'Hard',       0.99586253],
            ['tyre_compound', 'Rain',       0.99608785],
            
            // Tyre Brand IDs (Assuming these act as IDs or specific small modifiers)
            ['tyre_brand', 'Pipirelli',  1],
            ['tyre_brand', 'Avonn',      1], // Assuming Avonn is 1 based on typical GPRO data not shown but inferred
            ['tyre_brand', 'Yokomama',   2],
            ['tyre_brand', 'Dunnolop',   4],
            ['tyre_brand', 'Badyear',    7],
            ['tyre_brand', 'Michelini',  5],
            ['tyre_brand', 'Bridgerock', 6],
            ['tyre_brand', 'Hancock',    8], // Guessing 8 if needed
            
            // Track Wear Levels (IDs/Factors)
            ['track_wear_level', 'Very high', 4],
            ['track_wear_level', 'High',      3],
            ['track_wear_level', 'Medium',    2],
            ['track_wear_level', 'Low',       1],
            ['track_wear_level', 'Very low',  0],
        ];

        $stmt = $this->db->prepare("INSERT OR IGNORE INTO game_constants (category, name, value) VALUES (?, ?, ?)");
        foreach ($constants as $c) {
            $stmt->execute($c);
        }
    }
}