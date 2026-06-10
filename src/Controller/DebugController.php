<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\UserRepository;
use App\Security\Authorize;
use App\Cache\CacheInterface;
use Twig\Environment;

class DebugController
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly CacheInterface $cache,
        private readonly Environment $twig,
        private readonly string $dbPath,
        private readonly Authorize $authorize,
    ) {
    }

    public function index(): void
    {
        $user = $this->authorize->requireAdmin();

        $memLimit = ini_get('memory_limit');
        $systemInfo = [
            'php_version' => PHP_VERSION,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'memory_usage' => $this->formatBytes(memory_get_usage(true)),
            'memory_limit' => ($memLimit === '-1') ? null : $memLimit,
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'max_execution_time' => ini_get('max_execution_time') . 's',
            'extensions' => [
                'pdo_sqlite' => extension_loaded('pdo_sqlite'),
                'curl' => extension_loaded('curl'),
                'mbstring' => extension_loaded('mbstring'),
                'json' => extension_loaded('json'),
                'redis' => extension_loaded('redis'),
                'apcu' => extension_loaded('apcu'),
            ]
        ];

        $dbStats = [
            'path' => $this->dbPath,
            'size' => file_exists($this->dbPath) ? $this->formatBytes(filesize($this->dbPath) ?: 0) : 'Not Found',
            'writable' => is_writable($this->dbPath) || is_writable(dirname($this->dbPath)),
        ];

        $userStats = [
            'total' => $this->userRepo->countAll(),
            'active' => $this->userRepo->countActiveSince(30),
            'with_token' => $this->userRepo->countWithApiToken(),
        ];

        $maskedEnv = $this->getMaskedEnv();

        echo $this->twig->render('admin/debug.twig', [
            'system' => $systemInfo,
            'db_stats' => $dbStats,
            'user_stats' => $userStats,
            'env_vars' => $maskedEnv,
            'api_limit' => $_SESSION['api_limit'] ?? 'Unknown',
            'cache_driver' => $_ENV['CACHE_DRIVER'] ?? 'unknown',
            'flash' => $_SESSION['flash'] ?? null,
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'is_logged_in' => true,
            'user' => $user
        ]);
        unset($_SESSION['flash']);
    }

    public function flushCache(): void
    {
        $this->authorize->requireAdmin();

        try {
            if ($this->cache->clear()) {
                $msg = "Cache cleared successfully.";
            } else {
                $msg = "Cache clear command sent, but result was false (check driver logs).";
            }

            unset(
                $_SESSION['recruitment_results'],
                $_SESSION['training_results'],
                $_SESSION['strategy_results'],
                $_SESSION['wear_results']
            );
            $_SESSION['flash'] = $msg . " Session Data flushed.";
        } catch (\Exception $exception) {
            $_SESSION['flash'] = "Error flushing cache: " . $exception->getMessage();
        }

        header('Location: /debug');
        exit;
    }

    /**
     * @return array<string, mixed>
     */
    private function getMaskedEnv(): array
    {
        $data = $_ENV;
        $keysToMask = ['SECRET', 'KEY', 'PASS', 'TOKEN', 'AUTH'];

        foreach ($data as $key => $value) {
            foreach ($keysToMask as $term) {
                if (str_contains(strtoupper((string)$key), $term)) {
                    $data[$key] = '******** (Masked)';
                    break;
                }
            }
        }

        ksort($data);
        return $data;
    }

    private function formatBytes(int|float $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = (int) floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
