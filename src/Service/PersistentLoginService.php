<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PersistentTokenRepository;
use DateTimeImmutable;

/**
 * "Keep me signed in" persistent login (selector + validator scheme).
 *
 * Security model:
 *   - Cookie value is `selector:validator`, both from random_bytes.
 *   - Only hash('sha256', validator) is stored; the raw validator never hits disk.
 *   - On restore the validator is compared in constant time, then ROTATED — a
 *     replayed (stolen-then-reused) cookie fails the next time and a forged
 *     validator on a known selector revokes the token (theft detection).
 *   - The window is rolling: every successful restore pushes expiry forward.
 *
 * The session itself stays short-lived; this token is the longer recovery layer.
 */
final class PersistentLoginService
{
    public const COOKIE_NAME = 'gpro_remember';
    private const COOKIE_PATH = '/';

    public function __construct(
        private readonly PersistentTokenRepository $tokens,
        private readonly CookieJar $cookies,
        private readonly bool $secure = true,
        private readonly int $lifetimeSeconds = 60 * 60 * 24 * 30,
    ) {
    }

    public function issue(int $userId): void
    {
        // Opportunistic GC at the rare login moment, not on every request.
        $this->tokens->deleteExpired((new DateTimeImmutable())->format('Y-m-d H:i:s'));

        $selector  = bin2hex(random_bytes(9));
        $validator = bin2hex(random_bytes(32));

        $this->tokens->create(
            $userId,
            $selector,
            hash('sha256', $validator),
            $this->expiry(),
        );

        $this->writeCookie($selector, $validator);
    }

    /**
     * Validate the remember cookie and, on success, rotate it and return the
     * user id. Returns null (and leaves no session) on any failure.
     */
    public function restore(): ?int
    {
        $raw = $this->cookies->get(self::COOKIE_NAME);
        if ($raw === null || !str_contains($raw, ':')) {
            return null;
        }

        [$selector, $validator] = explode(':', $raw, 2);
        if ($selector === '' || $validator === '') {
            return null;
        }

        $row = $this->tokens->findBySelector($selector);
        if ($row === null) {
            $this->cookies->clear(self::COOKIE_NAME, self::COOKIE_PATH);
            return null;
        }

        // Known selector but wrong validator → treat as theft and revoke.
        if (!hash_equals((string) $row['validator_hash'], hash('sha256', $validator))) {
            $this->tokens->delete((int) $row['id']);
            $this->cookies->clear(self::COOKIE_NAME, self::COOKIE_PATH);
            return null;
        }

        if (new DateTimeImmutable() > new DateTimeImmutable((string) $row['expires_at'])) {
            $this->tokens->delete((int) $row['id']);
            $this->cookies->clear(self::COOKIE_NAME, self::COOKIE_PATH);
            return null;
        }

        // Rotate: new validator, fresh window, reissue cookie.
        $newValidator = bin2hex(random_bytes(32));
        $this->tokens->rotate((int) $row['id'], hash('sha256', $newValidator), $this->expiry());
        $this->writeCookie($selector, $newValidator);

        return (int) $row['user_id'];
    }

    public function clearForUser(int $userId): void
    {
        $this->tokens->deleteAllForUser($userId);
        $this->cookies->clear(self::COOKIE_NAME, self::COOKIE_PATH);
    }

    private function writeCookie(string $selector, string $validator): void
    {
        $this->cookies->set(self::COOKIE_NAME, $selector . ':' . $validator, [
            'expires'  => time() + $this->lifetimeSeconds,
            'path'     => self::COOKIE_PATH,
            'secure'   => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function expiry(): string
    {
        return (new DateTimeImmutable("+{$this->lifetimeSeconds} seconds"))->format('Y-m-d H:i:s');
    }
}
