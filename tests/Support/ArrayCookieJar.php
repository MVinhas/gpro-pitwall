<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\CookieJar;

/** In-memory CookieJar for tests — no real headers involved. */
final class ArrayCookieJar implements CookieJar
{
    /** @var array<string,string> */
    public array $store = [];

    public function get(string $name): ?string
    {
        return $this->store[$name] ?? null;
    }

    public function set(string $name, string $value, array $options): void
    {
        $this->store[$name] = $value;
    }

    public function clear(string $name, string $path): void
    {
        unset($this->store[$name]);
    }
}
