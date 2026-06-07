<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Database\Database;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Locks in the concurrency contract for SQLite under load: WAL must be active
 * so readers don't block the writer, and a second writer must wait on
 * busy_timeout rather than failing instantly with SQLITE_BUSY.
 *
 * Uses a real file (not :memory:) because WAL requires an on-disk database.
 */
#[CoversClass(Database::class)]
final class DatabaseConcurrencyTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $dir = dirname(__DIR__, 2) . '/var/test';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $this->dbPath = $dir . '/concurrency-' . uniqid() . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            $file = $this->dbPath . $suffix;
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function connect(): PDO
    {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Database::configure($pdo);

        return $pdo;
    }

    public function testConfigureEnablesWalMode(): void
    {
        $pdo = $this->connect();

        $mode = $pdo->query('PRAGMA journal_mode')->fetchColumn();

        self::assertSame('wal', strtolower((string) $mode));
    }

    public function testConfigureSetsBusyTimeout(): void
    {
        $pdo = $this->connect();

        $timeout = (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn();

        self::assertSame(5000, $timeout);
    }

    public function testTwoConnectionsCanWriteWithoutLockError(): void
    {
        $writer = $this->connect();
        $writer->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');

        // A second, independent connection — the real-world concurrency case.
        $other = $this->connect();

        $writer->exec("INSERT INTO t (v) VALUES ('first')");
        $other->exec("INSERT INTO t (v) VALUES ('second')");

        $count = (int) $writer->query('SELECT COUNT(*) FROM t')->fetchColumn();

        self::assertSame(2, $count);
    }

    public function testReaderDoesNotBlockWriterUnderWal(): void
    {
        $writer = $this->connect();
        $writer->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
        $writer->exec("INSERT INTO t (v) VALUES ('seed')");

        $reader = $this->connect();
        // Hold an open read cursor while the writer commits — under the default
        // rollback journal this would surface as a locked database; under WAL
        // it must succeed.
        $cursor = $reader->query('SELECT * FROM t');
        $cursor->fetch();

        $writer->exec("INSERT INTO t (v) VALUES ('written-during-read')");

        $count = (int) $writer->query('SELECT COUNT(*) FROM t')->fetchColumn();

        self::assertSame(2, $count);
    }
}
