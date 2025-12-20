<?php

declare(strict_types=1);

namespace App\Security;

final class Csrf
{
    private const string SESSION_KEY = 'csrf_token';

    public function getToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public function validate(?string $token): bool
    {
        if (
            $token === null ||
            session_status() !== PHP_SESSION_ACTIVE ||
            empty($_SESSION[self::SESSION_KEY])
        ) {
            return false;
        }

        return hash_equals($_SESSION[self::SESSION_KEY], $token);
    }

    public function regenerate(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
    }
}
