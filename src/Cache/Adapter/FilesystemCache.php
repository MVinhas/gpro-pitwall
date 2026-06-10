<?php

declare(strict_types=1);

namespace App\Cache\Adapter;

use App\Cache\CacheInterface;
use RuntimeException;

/**
 * File-backed cache — the zero-infra default. Each entry is a serialized
 * ['expires' => int, 'value' => mixed] payload written atomically under a
 * single directory. Works on any host without APCu/Redis.
 */
final class FilesystemCache implements CacheInterface
{
    private readonly string $dir;

    public function __construct(string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException("Cache directory is not writable: {$directory}");
        }

        $this->dir = rtrim($directory, '/');
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $path = $this->path($key);
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return $default;
        }

        // Cached payloads are only scalars/arrays — never objects. Refusing to
        // instantiate classes turns a tampered/poisoned cache file into a plain
        // miss instead of a PHP object-injection gadget chain.
        $entry = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($entry) || !array_key_exists('value', $entry)) {
            return $default;
        }

        $expires = (int)($entry['expires'] ?? 0);
        if ($expires !== 0 && $expires < time()) {
            @unlink($path);
            return $default;
        }

        return $entry['value'];
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $expires = ($ttl !== null && $ttl > 0) ? time() + $ttl : 0;
        $payload = serialize(['expires' => $expires, 'value' => $value]);

        $path = $this->path($key);
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            return false;
        }

        // Atomic publish — readers never see a half-written file.
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }

    public function delete(string $key): bool
    {
        $path = $this->path($key);
        if (!file_exists($path)) {
            return true;
        }

        return @unlink($path);
    }

    public function clear(): bool
    {
        $ok = true;
        foreach (glob($this->dir . '/*.cache') ?: [] as $file) {
            if (!@unlink($file)) {
                $ok = false;
            }
        }

        return $ok;
    }

    public function has(string $key): bool
    {
        // Route through get() so an expired entry reports absent (and is reaped).
        return $this->get($key, $this) !== $this;
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . hash('sha256', $key) . '.cache';
    }
}
