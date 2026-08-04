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
    public const string COOKIE_NAME = 'gpro_remember';
    private const string COOKIE_PATH = '/';

    /**
     * How long a just-rotated validator stays acceptable, so parallel requests
     * from the same page aren't mistaken for a replay. Seconds, not minutes: it
     * only has to cover one page's in-flight fetches.
     */
    private const int ROTATION_GRACE_SECONDS = 30;

    public function __construct(
        private readonly PersistentTokenRepository $tokens,
        private readonly CookieJar $cookies,
        private readonly bool $secure = true,
        private readonly int $lifetimeSeconds = 60 * 60 * 24 * 30,
        private readonly ?SecurityLogger $securityLog = null,
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

        $presented = hash('sha256', $validator);

        if (!hash_equals((string) $row['validator_hash'], $presented)) {
            // A page issues several requests at once (navigation plus its
            // fragment/warmup fetches), all carrying the same cookie. Whichever
            // lands first rotates the validator, so the others arrive holding
            // the one it just replaced — identical on the wire to a replay.
            // Honour the superseded validator briefly so that race isn't
            // punished as theft; genuine replay still fails, just outside the
            // window. Nothing is rotated here — the winning request already did
            // it, and its Set-Cookie is what the browser keeps.
            if ($this->withinRotationGrace($row, $presented)) {
                return (int) $row['user_id'];
            }

            // Known selector but wrong validator → treat as theft and revoke.
            $this->tokens->delete((int) $row['id']);
            $this->cookies->clear(self::COOKIE_NAME, self::COOKIE_PATH);
            $this->securityLog?->event('token_theft_detected', [
                'selector' => $selector,
                'user_id'  => (int) $row['user_id'],
            ]);
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

    /**
     * Whether the presented validator is the one this token rotated away from,
     * within the grace window. Deliberately narrow: it accepts exactly one
     * superseded validator, for a few seconds, and never extends the token.
     *
     * @param array<string, mixed> $row
     */
    private function withinRotationGrace(array $row, string $presented): bool
    {
        $previous = (string) ($row['previous_validator_hash'] ?? '');
        $rotatedAt = (string) ($row['rotated_at'] ?? '');
        if ($previous === '' || $rotatedAt === '') {
            return false;
        }

        if (!hash_equals($previous, $presented)) {
            return false;
        }

        $age = (new DateTimeImmutable())->getTimestamp()
            - (new DateTimeImmutable($rotatedAt))->getTimestamp();

        return $age >= 0 && $age <= self::ROTATION_GRACE_SECONDS;
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
