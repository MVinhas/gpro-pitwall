<?php

declare(strict_types=1);

namespace App\Cache\Adapter;

use App\Cache\CacheInterface;
use Redis;

class RedisCache implements CacheInterface
{
    private readonly Redis $redis;

    public function __construct(string $host, int $port, ?string $password = null)
    {
        if (!extension_loaded('redis')) {
            throw new \RuntimeException('Redis extension is not loaded.');
        }

        $this->redis = new Redis();
        // Silence connection errors to handle them gracefully in factory if needed,
        // though strictly we want to fail hard if configured to use Redis.
        if (!$this->redis->connect($host, $port)) {
            throw new \RuntimeException("Could not connect to Redis at $host:$port");
        }

        if ($password) {
            if (!$this->redis->auth($password)) {
                throw new \RuntimeException("Failed to authenticate with Redis");
            }
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->redis->get($key);
        // Redis returns FALSE on failure/miss.
        // We must check if the value stored was actually boolean false (unlikely serialization)
        // For simplicity with standard serialization:
        if ($value === false) {
            return $default;
        }

        // Assuming we store serialized data or scalar types.
        // For complex types, PHP Redis often handles serialization if configured,
        // but we'll stick to raw retrieval. If you need serialization, we wrap json_decode here.
        // For this implementation, we assume values are scalars or the client handles serialization.
        // However, standard PSR-16 implies the cache handles serialization.

        $unpacked = @unserialize($value);
        if ($unpacked === false && $value !== 'b:0;') {
             // Not serialized or failed
             return $value;
        }

        return $unpacked;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $packed = serialize($value);

        if ($ttl) {
            return $this->redis->setex($key, $ttl, $packed);
        }

        return $this->redis->set($key, $packed);
    }

    public function delete(string $key): bool
    {
        return (bool) $this->redis->del($key);
    }

    public function clear(): bool
    {
        return $this->redis->flushDB();
    }

    public function has(string $key): bool
    {
        return (bool) $this->redis->exists($key);
    }
}
