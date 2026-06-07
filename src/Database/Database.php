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
                self::configure(self::$instance);
            } catch (PDOException $e) {
                die("Database connection failed: " . $e->getMessage());
            }
        }

        return self::$instance;
    }

    /**
     * Apply the concurrency/integrity PRAGMAs every connection needs.
     *
     * WAL lets readers run while a single writer holds the write lock, so a
     * read-heavy workload (100+ concurrent users) doesn't serialize on the DB.
     * busy_timeout makes the rare write contention wait-and-retry instead of
     * throwing SQLITE_BUSY immediately. synchronous=NORMAL is the safe pairing
     * with WAL (durable across app crashes; only a power loss can lose the last
     * commit) and is markedly faster than FULL.
     *
     * In-memory databases (`:memory:`) don't support WAL — querying the result
     * lets us skip it gracefully so tests on `sqlite::memory:` still work.
     */
    public static function configure(PDO $pdo): void
    {
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');

        $statement = $pdo->query('PRAGMA journal_mode = WAL');
        $journalMode = $statement === false ? null : $statement->fetchColumn();
        if (is_string($journalMode) && strtolower($journalMode) === 'wal') {
            $pdo->exec('PRAGMA synchronous = NORMAL');
        }
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
