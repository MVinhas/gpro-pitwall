<?php
namespace App\Http;

class Request
{
    public function __construct(
        private array $get,
        private array $post,
        private array $files
    ) {}

    public static function createFromGlobals(): self
    {
        return new self($_GET, $_POST, $_FILES);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->get[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function getMethod(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }
}