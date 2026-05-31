<?php

declare(strict_types=1);

namespace App\Http;

class Request
{
    /**
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @param array<string, mixed> $server
     */
    public function __construct(
        private array $get,
        private array $post,
        private array $files,
        private array $server
    ) {
    }

    public static function createFromGlobals(): self
    {
        return new self($_GET, $_POST, $_FILES, $_SERVER);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->get[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    /** @return array<string, mixed>|null */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        if (is_array($file)) {
            return $file;
        }

        return null;
    }

    public function getMethod(): string
    {
        return (string)($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    public function getPath(): string
    {
        $uri = (string)($this->server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);

        if ($this->getMethod() === 'POST' && ($path === '/' || $path === '/index.php')) {
            $action = $this->post('action');
            if ($action && is_string($action) && preg_match('#^[a-zA-Z0-9/_-]+$#', $action)) {
                return '/' . ltrim($action, '/');
            }
        }

        return (string)$path;
    }
}
