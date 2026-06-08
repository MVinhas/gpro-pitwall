<?php

declare(strict_types=1);

namespace App\Support;

final class RequestContext
{
    /**
     * Whether the current request reached us over HTTPS.
     *
     * On Hetzner shared hosting TLS terminates at a front proxy, so
     * $_SERVER['HTTPS'] is often unset even though the visitor is on HTTPS.
     * Trust the proxy's X-Forwarded-Proto in that case. Used to decide the
     * Secure flag on session + remember-me cookies — a long-lived cookie must
     * never be emitted without Secure.
     *
     * @param array<string, mixed> $server
     */
    public static function isHttps(array $server): bool
    {
        if (($server['HTTPS'] ?? '') === 'on') {
            return true;
        }

        if (((int) ($server['SERVER_PORT'] ?? 0)) === 443) {
            return true;
        }

        $forwarded = strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''));
        return $forwarded === 'https';
    }
}
