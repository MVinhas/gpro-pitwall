<?php

declare(strict_types=1);

namespace App\Controller;

use App\Cache\CacheInterface;
use PDO;
use Throwable;

final readonly class HealthController
{
    public function __construct(
        private PDO $db,
        private CacheInterface $cache,
    ) {
    }

    public function check(): void
    {
        $checks = [
            'db'    => $this->checkDb(),
            'cache' => $this->checkCache(),
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

    /** @return array{ok: bool, detail: string} */
    private function checkDb(): array
    {
        try {
            $stmt = $this->db->query('SELECT 1');
            if ($stmt === false) {
                return ['ok' => false, 'detail' => 'sqlite query returned false'];
            }
            $stmt->fetch();
            return ['ok' => true, 'detail' => 'sqlite reachable'];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }

    /** @return array{ok: bool, detail: string} */
    private function checkCache(): array
    {
        $key = '__healthz__';
        $value = bin2hex(random_bytes(8));

        try {
            $this->cache->set($key, $value, 10);
            $roundtrip = $this->cache->get($key);
            $this->cache->delete($key);

            if ($roundtrip !== $value) {
                return ['ok' => false, 'detail' => 'roundtrip mismatch'];
            }

            return ['ok' => true, 'detail' => 'roundtrip ok'];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }
}
