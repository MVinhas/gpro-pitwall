<?php

declare(strict_types=1);

namespace App\Cache;

use App\Cache\Adapter\ApcuCache;
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
        $driver = strtolower((string)($config['CACHE_DRIVER'] ?? 'none'));

        try {
            return match ($driver) {
                'redis' => self::createRedis($config),
                'apcu'  => self::createApcu(),
                'none'  => new NullCache(),
                default => new NullCache(),
            };
        } catch (RuntimeException $runtimeException) {
            error_log(
                "Cache driver [$driver] failed to initialize: "
                .
                $runtimeException->getMessage()
                .
                ". Falling back to NullCache."
            );
            return new NullCache();
        }
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
