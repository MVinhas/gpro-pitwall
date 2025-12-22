<?php

declare(strict_types=1);

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
                $db_file = $_ENV['DB_FILE'] ?? 'gpro_pilots.sqlite';


                $project_root = realpath(__DIR__ . '/../../');
                $db_path = $project_root . '/' . $db_file;

                self::$instance = new PDO("sqlite:" . $db_path);
                self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
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
