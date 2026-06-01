<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use App\Security\ApiTokenCrypto;

class DatabaseSeeder
{
    /**
     * @param array<string, string> $statsSchema
     * @param list<string>          $divisions
     * @param array<string, mixed>  $secrets
     */
    public function __construct(
        private readonly PDO $db,
        private readonly array $statsSchema,
        private readonly array $divisions,
        private array $secrets,
        private readonly ApiTokenCrypto $apiTokenCrypto,
    ) {
    }

    public function migrate(): void
    {

        $this->createUsersTable();
        $this->createVerificationTokensTable();
        $this->createIndexVerificationUser();

        $this->createPilotsTable();
        $this->createMetadataTable();
        $this->createTracksTable();
        $this->createTrainingsTable();
        $this->createCarPartCoefficientsTable();
        $this->createGameConstantsTable();
        $this->dropDeprecatedTables();


        $this->seedTrainings();
        $this->seedCarPartCoefficients();
        $this->seedGameConstants();


        $this->applyUserMigrations();
        $this->encryptLegacyApiTokens();
    }

    /**
     * Removes tables no longer used by the app. Runs every boot so a
     * fresh checkout converges. Idempotent — DROP TABLE IF EXISTS.
     */
    private function dropDeprecatedTables(): void
    {
        $this->db->exec('DROP TABLE IF EXISTS track_risks');
    }

    /**
     * One-shot: re-encrypt any api_token value still stored as plaintext.
     * Safe to run repeatedly — looksEncrypted() short-circuits on ciphertext.
     */
    private function encryptLegacyApiTokens(): void
    {
        $rows = $this->db
            ->query("SELECT id, api_token FROM users WHERE api_token IS NOT NULL AND api_token != ''")
            ->fetchAll(PDO::FETCH_ASSOC);

        $update = $this->db->prepare("UPDATE users SET api_token = :token WHERE id = :id");

        foreach ($rows as $row) {
            $current = (string)$row['api_token'];
            if ($this->apiTokenCrypto->looksEncrypted($current)) {
                continue;
            }

            $update->execute([
                'token' => $this->apiTokenCrypto->encrypt($current),
                'id' => (int)$row['id'],
            ]);
        }
    }

    private function createUsersTable(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                email_encrypted TEXT NOT NULL,
                email_hash TEXT NOT NULL UNIQUE,
                is_admin INTEGER NOT NULL DEFAULT 0,
                api_token TEXT DEFAULT NULL,
                verified_at TEXT DEFAULT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ";

        $this->db->exec($sql);
    }

    private function applyUserMigrations(): void
    {
        $cols = $this->db
            ->query('PRAGMA table_info(users)')
            ->fetchAll(PDO::FETCH_ASSOC);

        $existingCols = array_column($cols, 'name');

        if (!in_array('username', $existingCols, true)) {
            $this->db->exec(
                "ALTER TABLE users ADD COLUMN username TEXT DEFAULT NULL"
            );
            $this->db->exec(
                "UPDATE users SET username = 'User_' || id WHERE username IS NULL"
            );
        }

        if (in_array('is_premium', $existingCols, true)) {
            // Premium tier removed 2026-06-01. SQLite 3.35+ supports DROP COLUMN.
            $this->db->exec("ALTER TABLE users DROP COLUMN is_premium");
        }

        if (!in_array('api_token', $existingCols, true)) {
            $this->db->exec(
                "ALTER TABLE users ADD COLUMN api_token TEXT DEFAULT NULL"
            );
        }

        if (!in_array('verified_at', $existingCols, true)) {
            $this->db->exec(
                "ALTER TABLE users ADD COLUMN verified_at TEXT DEFAULT NULL"
            );

            if (in_array('is_verified', $existingCols, true)) {
                $this->db->exec(
                    "UPDATE users
                     SET verified_at = datetime('now')
                     WHERE is_verified = 1"
                );
            }
        }

        if (!in_array('is_admin', $existingCols, true)) {
            $this->db->exec(
                "ALTER TABLE users ADD COLUMN is_admin INTEGER NOT NULL DEFAULT 0"
            );
        }

        if (!in_array('email_encrypted', $existingCols, true)) {
            $this->db->exec(
                "ALTER TABLE users ADD COLUMN email_encrypted TEXT"
            );
        }

        if (!in_array('email_hash', $existingCols, true)) {
            $this->db->exec(
                "ALTER TABLE users ADD COLUMN email_hash TEXT"
            );
        }

        if (!in_array('last_synced_at', $existingCols, true)) {
            $this->db->exec(
                "ALTER TABLE users ADD COLUMN last_synced_at TEXT DEFAULT NULL"
            );
        }

        if (!in_array('sync_status', $existingCols, true)) {
            $this->db->exec(
                "ALTER TABLE users ADD COLUMN sync_status TEXT NOT NULL DEFAULT 'idle'"
            );
        }
    }

    private function createVerificationTokensTable(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS verification_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                code_hmac TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (user_id)
                    REFERENCES users(id)
                    ON DELETE CASCADE
            )
        ";

        $this->db->exec($sql);
    }

    private function createIndexVerificationUser(): void
    {
        $sql = "
            CREATE INDEX IF NOT EXISTS idx_verification_user
            ON verification_tokens(user_id)
        ";

        $this->db->exec($sql);
    }

    private function createPilotsTable(): void
    {
        $columns = 'id INTEGER PRIMARY KEY AUTOINCREMENT, division TEXT NOT NULL';

        foreach ($this->statsSchema as $columnName) {
            $columns .= ", {$columnName} INTEGER NOT NULL";
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

        $stmt = $this->db->prepare(
            "INSERT OR IGNORE INTO division_metadata
             (division, last_retrieved_season, last_retrieved_race)
             VALUES (:div, 0, 1)"
        );

        foreach ($this->divisions as $division) {
            $stmt->execute([':div' => $division]);
        }
    }


    private function createTracksTable(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS tracks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT UNIQUE NOT NULL,
                lap_length REAL,
                laps INTEGER,
                distance REAL,
                avg_speed REAL,
                corners INTEGER,
                pit_time REAL,
                base_wings INTEGER,
                base_engine INTEGER,
                base_brakes INTEGER,
                base_gear INTEGER,
                base_suspension INTEGER,
                fuel_consumption TEXT,
                tyre_wear TEXT,
                wing_split REAL,
                fuel_per_lap REAL,
                fuel_per_lap_wet REAL,
                tyre_wear_factor REAL,
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
                wear_electronics INTEGER,
                boost_dry REAL,
                boost_wet REAL
            )
        ";

        $this->db->exec($sql);
        $this->applyTrackMigrations();
    }

    /**
     * Add columns to an already-created tracks table. Tracks data is fully
     * reseeded from data/tracks.csv, but the schema must gain the column
     * before a reseed can populate it.
     */
    private function applyTrackMigrations(): void
    {
        $cols = $this->db
            ->query('PRAGMA table_info(tracks)')
            ->fetchAll(PDO::FETCH_ASSOC);
        $existing = array_column($cols, 'name');

        foreach (['boost_dry', 'boost_wet'] as $col) {
            if (!in_array($col, $existing, true)) {
                $this->db->exec("ALTER TABLE tracks ADD COLUMN {$col} REAL");
            }
        }
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
        $sql = "
            CREATE TABLE IF NOT EXISTS car_part_coefficients (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                part_name TEXT NOT NULL,
                type TEXT NOT NULL,
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
        $sql = "
            CREATE TABLE IF NOT EXISTS game_constants (
                category TEXT NOT NULL,
                name TEXT NOT NULL,
                value REAL NOT NULL,
                PRIMARY KEY (category, name)
            )
        ";

        $this->db->exec($sql);
    }

    private function seedTrainings(): void
    {
        $trainings = $this->secrets['trainings_seed'] ?? [];

        $stmt = $this->db->prepare(
            "INSERT OR IGNORE INTO trainings (
                name, cost,
                gain_concentration, gain_talent, gain_aggressiveness,
                gain_experience, gain_technical_insight, gain_stamina,
                gain_charisma, gain_motivation, gain_weight
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        foreach ($trainings as $training) {
            $stmt->execute($training);
        }
    }

    private function seedCarPartCoefficients(): void
    {
        $coeffs = $this->secrets['car_part_coeffs_seed'] ?? [];

        $stmt = $this->db->prepare(
            "INSERT OR IGNORE INTO car_part_coefficients (
                part_name, type,
                cha, eng, f_wing, r_wing, und,
                side, cool, gear, brake, susp, elec, constant
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        foreach ($coeffs as $coeff) {
            $stmt->execute($coeff);
        }
    }

    private function seedGameConstants(): void
    {
        $stmt = $this->db->prepare(
            "INSERT OR IGNORE INTO game_constants
             (category, name, value)
             VALUES (:cat, :name, :val)"
        );

        $compounds = $this->secrets['tyre_calc']['tyre_risk_factors'] ?? [];
        foreach ($compounds as $name => $value) {
            $stmt->execute([
                ':cat' => 'tyre_compound',
                ':name' => $name,
                ':val' => $value,
            ]);
        }

        $suppliers = $this->secrets['tyre_suppliers_durabilities'] ?? [];
        foreach ($suppliers as $name => $value) {
            $stmt->execute([
                ':cat' => 'tyre_brand',
                ':name' => $name,
                ':val' => $value,
            ]);
        }

        $wearLevels = $this->secrets['tyre_calc']['track_wear_values'] ?? [];
        foreach ($wearLevels as $name => $value) {
            $stmt->execute([
                ':cat' => 'track_wear_level',
                ':name' => ucfirst(strtolower((string) $name)),
                ':val' => $value,
            ]);
        }
    }
}
