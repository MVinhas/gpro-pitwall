<?php

declare(strict_types=1);

namespace App\Cache;

use App\Cache\Adapter\ApcuCache;
use App\Cache\Adapter\FilesystemCache;
use App\Cache\Adapter\NullCache;
use App\Cache\Adapter\RedisCache;
use RuntimeException;

class CacheFactory
{
    /**
     * @param array<string, mixed> $config
     */
    public static function create(array $config): CacheInterface
    {
        $driver = strtolower((string)($config['CACHE_DRIVER'] ?? 'filesystem'));

        try {
            return match ($driver) {
                'redis'      => self::createRedis($config),
                'apcu'       => self::createApcu(),
                'filesystem' => self::createFilesystem($config),
                'none'       => new NullCache(),
                // Unknown driver degrades to a *working* cache, not a no-op —
                // a misconfigured CACHE_DRIVER should never silently disable
                // caching and hammer the upstream API.
                default      => self::createFilesystem($config),
            };
        } catch (RuntimeException $runtimeException) {
            error_log(
                "Cache driver [$driver] failed to initialize: "
                .
                $runtimeException->getMessage()
                .
                ". Falling back to filesystem cache."
            );

            try {
                return self::createFilesystem($config);
            } catch (RuntimeException) {
                return new NullCache();
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function createFilesystem(array $config): CacheInterface
    {
        $dir = (string)($config['CACHE_DIR'] ?? (dirname(__DIR__, 2) . '/var/cache'));
        return new FilesystemCache($dir);
    }

    private static function createApcu(): CacheInterface
    {
        return new ApcuCache();
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function createRedis(array $config): CacheInterface
    {
        $host = (string)($config['REDIS_HOST'] ?? '127.0.0.1');
        $port = (int) ($config['REDIS_PORT'] ?? 6379);
        $pass = $config['REDIS_PASSWORD'] ?? null;

        if (empty($pass)) {
             $pass = null;
        }

        return new RedisCache($host, $port, (string)$pass);
    }
}
