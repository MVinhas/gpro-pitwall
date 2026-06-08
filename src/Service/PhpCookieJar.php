<?php

declare(strict_types=1);

namespace App\Service;

final class PhpCookieJar implements CookieJar
{
    public function get(string $name): ?string
    {
        $value = $_COOKIE[$name] ?? null;
        return is_string($value) ? $value : null;
    }

    /**
     * @param array{expires?:int,path?:string,secure?:bool,httponly?:bool,samesite?:'Lax'|'Strict'|'None'} $options
     */
    public function set(string $name, string $value, array $options): void
    {
        setcookie($name, $value, $options);
        $_COOKIE[$name] = $value;
    }

    public function clear(string $name, string $path): void
    {
        setcookie($name, '', ['expires' => time() - 42000, 'path' => $path]);
        unset($_COOKIE[$name]);
    }
}
