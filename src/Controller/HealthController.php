<?php

declare(strict_types=1);

namespace App\Controller;

use App\Cache\CacheInterface;
use App\Security\Authorize;
use PDO;
use Throwable;

final readonly class HealthController
{
    public function __construct(
        private PDO $db,
        private CacheInterface $cache,
        private Authorize $authorize,
        private bool $isDev,
    ) {
    }

    public function check(): void
    {
        $showDetail = $this->isDev || $this->authorize->isAdmin();

        $checks = [
            'db'    => $this->checkDb($showDetail),
            'cache' => $this->checkCache($showDetail),
        ];

        $ok = !in_array(false, array_column($checks, 'ok'), true);

        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        http_response_code($ok ? 200 : 503);

        echo json_encode([
            'status' => $ok ? 'ok' : 'degraded',
            'checks' => $checks,
        ]);
    }

    /** @return array{ok: bool, detail?: string} */
    private function checkDb(bool $showDetail): array
    {
        try {
            $stmt = $this->db->query('SELECT 1');
            if ($stmt === false) {
                return $this->result(false, 'sqlite query returned false', $showDetail);
            }
            $stmt->fetch();
            return $this->result(true, 'sqlite reachable', $showDetail);
        } catch (Throwable $e) {
            error_log('[healthz] db check failed: ' . $e::class . ': ' . $e->getMessage());
            return $this->result(false, $e->getMessage(), $showDetail);
        }
    }

    /** @return array{ok: bool, detail?: string} */
    private function checkCache(bool $showDetail): array
    {
        $key = '__healthz__';
        $value = bin2hex(random_bytes(8));

        try {
            $this->cache->set($key, $value, 10);
            $roundtrip = $this->cache->get($key);
            $this->cache->delete($key);

            if ($roundtrip !== $value) {
                return $this->result(false, 'roundtrip mismatch', $showDetail);
            }

            return $this->result(true, 'roundtrip ok', $showDetail);
        } catch (Throwable $e) {
            error_log('[healthz] cache check failed: ' . $e::class . ': ' . $e->getMessage());
            return $this->result(false, $e->getMessage(), $showDetail);
        }
    }

    /** @return array{ok: bool, detail?: string} */
    private function result(bool $ok, string $detail, bool $showDetail): array
    {
        return $showDetail ? ['ok' => $ok, 'detail' => $detail] : ['ok' => $ok];
    }
}
