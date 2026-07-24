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

    /**
     * Whether the caller is an AJAX/fetch request expecting a JSON answer rather
     * than an HTML page. Used at the auth and CSRF boundaries so an expired
     * session yields a small JSON 401/403 (which the frontend turns into a login
     * redirect) instead of an HTML 302/403 that fetch().json() would choke on and
     * mislabel as a "Network error".
     *
     * The frontend fetch callers set X-Requested-With explicitly; the Accept
     * check is a defensive fallback for any programmatic JSON client.
     *
     * @param array<string, mixed> $server
     */
    public static function wantsJson(array $server): bool
    {
        if (strtolower((string) ($server['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
            return true;
        }

        return str_contains(strtolower((string) ($server['HTTP_ACCEPT'] ?? '')), 'application/json');
    }
}
