<?php

declare(strict_types=1);

namespace App\Cache\Adapter;

use App\Cache\CacheInterface;
use RuntimeException;

/**
 * File-backed cache — the zero-infra default. Each entry is a serialized
 * ['expires' => int, 'value' => mixed] payload written atomically under a
 * single directory. Works on any host without APCu/Redis.
 *
 * A file is named after the hash of its key, so a key cannot be read back from
 * the directory. The key's leading namespaces (the release, then the user) are
 * kept readable in front of the hash: that is what lets one user's entries be
 * removed when they delete their account, and a past release's be swept.
 */
final class FilesystemCache implements CacheInterface
{
    /** An opaque hashed namespace: the release (v + 8 hex) or a user (u + 16 hex). */
    private const string NAMESPACE_SEGMENT = '/^[a-z][0-9a-f]{8,16}$/';
    private const int MAX_NAMESPACE_DEPTH = 2;
    private const int SWEEP_INTERVAL_SECONDS = 86400;

    private readonly string $dir;

    /**
     * @param string|null $liveNamespace the running release's namespace; files
     *                                   outside it are swept once a day
     */
    public function __construct(string $directory, ?string $liveNamespace = null)
    {
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException("Cache directory is not writable: {$directory}");
        }

        $this->dir = rtrim($directory, '/');

        if ($liveNamespace !== null) {
            $this->sweepOnceADay($liveNamespace);
        }
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

    public function deleteNamespace(string $namespace): bool
    {
        $segments = explode(':', $namespace);
        if (count($segments) > self::MAX_NAMESPACE_DEPTH) {
            return false;
        }
        foreach ($segments as $segment) {
            if (preg_match(self::NAMESPACE_SEGMENT, $segment) !== 1) {
                return false;
            }
        }

        // "-" ends the namespaces of an entry filed directly under this one,
        // "." continues into a namespace inside it.
        $prefix = $this->dir . '/' . implode('.', $segments);
        $ok = true;
        foreach ([...(glob($prefix . '-*.cache') ?: []), ...(glob($prefix . '.*.cache') ?: [])] as $file) {
            if (!@unlink($file)) {
                $ok = false;
            }
        }

        return $ok;
    }

    private function path(string $key): string
    {
        $namespaces = [];
        $segments = explode(':', $key);
        array_pop($segments);
        foreach (array_slice($segments, 0, self::MAX_NAMESPACE_DEPTH) as $segment) {
            if (preg_match(self::NAMESPACE_SEGMENT, $segment) !== 1) {
                break;
            }
            $namespaces[] = $segment;
        }
        $tag = $namespaces === [] ? '' : implode('.', $namespaces) . '-';

        return $this->dir . '/' . $tag . hash('sha256', $key) . '.cache';
    }

    /**
     * A release reads only its own namespace, so every older file is dead
     * weight that would otherwise stay on disk for good, raw payloads included.
     * Files younger than a day are left: mid-deploy the previous release is
     * still serving from them.
     */
    private function sweepOnceADay(string $liveNamespace): void
    {
        $marker = $this->dir . '/.swept';
        $now = time();
        $last = @filemtime($marker);
        if ($last !== false && $last > $now - self::SWEEP_INTERVAL_SECONDS) {
            return;
        }
        // Stamped first so concurrent requests do not all sweep.
        @touch($marker);

        foreach (glob($this->dir . '/*.cache') ?: [] as $file) {
            $name = basename($file);
            if (str_starts_with($name, $liveNamespace . '-') || str_starts_with($name, $liveNamespace . '.')) {
                continue;
            }
            $modified = @filemtime($file);
            if ($modified !== false && $modified < $now - self::SWEEP_INTERVAL_SECONDS) {
                @unlink($file);
            }
        }
    }
}
