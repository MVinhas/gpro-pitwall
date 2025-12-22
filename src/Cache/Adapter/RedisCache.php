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
        if ($value === false) {
            return $default;
        }

        $unpacked = @unserialize($value);
        if ($unpacked === false && $value !== 'b:0;') {
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
