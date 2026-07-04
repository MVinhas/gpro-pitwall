<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Cache\Adapter\FilesystemCache;
use App\Controller\HealthController;
use App\Repository\UserRepository;
use App\Security\Authorize;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HealthController::class)]
final class HealthControllerTest extends TestCase
{
    private string $cacheDir;
    private FilesystemCache $cache;
    private PDO $db;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/gpro_health_' . bin2hex(random_bytes(6));
        $this->cache = new FilesystemCache($this->cacheDir);
        $this->db = new PDO('sqlite::memory:');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->cacheDir);
    }

    private function anonymousAuthorize(): Authorize
    {
        return new Authorize($this->createStub(UserRepository::class));
    }

    /** @return array{status: string, checks: array<string, array<string, mixed>>} */
    private function runCheck(HealthController $controller): array
    {
        ob_start();
        $controller->check();
        $body = ob_get_clean();

        return json_decode((string) $body, true);
    }

    public function testAnonymousResponseOmitsDetailWhenHealthy(): void
    {
        $controller = new HealthController($this->db, $this->cache, $this->anonymousAuthorize(), false);

        $result = $this->runCheck($controller);

        $this->assertSame('ok', $result['status']);
        $this->assertTrue($result['checks']['db']['ok']);
        $this->assertArrayNotHasKey('detail', $result['checks']['db']);
        $this->assertArrayNotHasKey('detail', $result['checks']['cache']);
    }

    public function testDevModeIncludesDetail(): void
    {
        $controller = new HealthController($this->db, $this->cache, $this->anonymousAuthorize(), true);

        $result = $this->runCheck($controller);

        $this->assertArrayHasKey('detail', $result['checks']['db']);
        $this->assertArrayHasKey('detail', $result['checks']['cache']);
    }

    public function testAdminSessionIncludesDetail(): void
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 1, 'is_admin' => 1]);
        $authorize = new Authorize($users);

        $_SESSION['user_id'] = 1;
        try {
            $controller = new HealthController($this->db, $this->cache, $authorize, false);
            $result = $this->runCheck($controller);
        } finally {
            unset($_SESSION['user_id']);
        }

        $this->assertArrayHasKey('detail', $result['checks']['db']);
    }

    public function testAnonymousResponseOmitsDetailOnRealDbFailure(): void
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('DROP TABLE IF EXISTS nonexistent'); // valid, doesn't break anything
        // Force a genuine PDOException by querying a table that doesn't exist.
        $db = new class extends PDO {
            public function __construct()
            {
                parent::__construct('sqlite::memory:');
                $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement
            {
                throw new \PDOException('SQLSTATE[HY000]: no such table: definitely_not_a_real_table');
            }
        };

        $controller = new HealthController($db, $this->cache, $this->anonymousAuthorize(), false);
        $result = $this->runCheck($controller);

        $this->assertFalse($result['checks']['db']['ok']);
        $this->assertArrayNotHasKey('detail', $result['checks']['db']);
        $this->assertSame('degraded', $result['status']);
    }
}
