<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\UserRepository;
use App\Security\Authorize;
use App\Cache\CacheInterface;
use App\Support\Env;
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

        // Session + APCu facts. A vanishing session is indistinguishable from a
        // logout at the HTTP layer, so the storage backend and its GC window
        // have to be visible: sessions kept in APCu are evicted when the shared
        // segment fills, which reads as a random logout mid-page.
        $sessionInfo = [
            'save_handler'   => ini_get('session.save_handler') ?: 'unknown',
            'save_path'      => ini_get('session.save_path') ?: '(default)',
            'gc_maxlifetime' => ini_get('session.gc_maxlifetime') . 's',
            'cookie_lifetime' => ini_get('session.cookie_lifetime') . 's',
        ];

        $apcuInfo = null;
        if (extension_loaded('apcu') && function_exists('apcu_sma_info')) {
            $sma = @apcu_sma_info(true);
            $info = @apcu_cache_info(true);
            if (is_array($sma)) {
                $free  = (float) ($sma['avail_mem'] ?? 0);
                $total = (float) (($sma['num_seg'] ?? 1) * ($sma['seg_size'] ?? 0));
                $apcuInfo = [
                    'total'      => $this->formatBytes((int) $total),
                    'free'       => $this->formatBytes((int) $free),
                    'used_pct'   => $total > 0 ? round((($total - $free) / $total) * 100, 1) : 0.0,
                    'entries'    => is_array($info) ? (int) ($info['num_entries'] ?? 0) : 0,
                    'expunges'   => is_array($info) ? (int) ($info['expunges'] ?? 0) : 0,
                ];
            }
        }

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
            'session_info' => $sessionInfo,
            'apcu_info' => $apcuInfo,
            'db_stats' => $dbStats,
            'user_stats' => $userStats,
            'env_vars' => $maskedEnv,
            'api_limit' => $_SESSION['api_limit'] ?? 'Unknown',
            'cache_driver' => Env::get('CACHE_DRIVER', 'unknown'),
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
                $_SESSION['strategy_results']
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
