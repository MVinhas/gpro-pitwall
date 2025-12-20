<?php

// gpro-driver-analyzer/src/Database/Database.php

namespace App\Database;

use PDO;
use PDOException;

/**
 * A singleton PDO connection wrapper.
 */
class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            try {
                // Get the DB file path from the environment variables
                $db_file = $_ENV['DB_FILE'] ?? 'gpro_pilots.sqlite';

                // Use realpath to get the absolute path to the project root
                $project_root = realpath(__DIR__ . '/../../');
                $db_path = $project_root . '/' . $db_file;

                if (!file_exists($db_path)) {
                    // This is a check for later.
                    // For now, we assume the file exists as you've provided it.
                }

                self::$instance = new PDO("sqlite:" . $db_path);
                self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                // We can't run the app without the DB
                die("Database connection failed: " . $e->getMessage());
            }
        }

        return self::$instance;
    }

    /**
     * Private constructor to prevent direct instantiation.
     */
    private function __construct()
    {
    }

    /**
     * Private clone method to prevent cloning.
     */
    private function __clone()
    {
    }
}
