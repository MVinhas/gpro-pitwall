<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\UserRepository;
use App\Repository\TokenRepository;
use App\Security\EmailCrypto;
use DateTimeImmutable;

class AuthService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TokenRepository $tokens,
        private readonly EmailService $mailer,
        private readonly RateLimiterService $limiter,
        private readonly ReCaptchaService $captcha,
        private readonly EmailCrypto $crypto,
        private readonly string $appSecret,
        private readonly GproSyncService $syncService,
        private readonly PersistentLoginService $persistentLogin,
        private readonly SecurityLogger $securityLog,
        private readonly int $codeTtlSeconds = 600,
        private readonly int $maxAttempts = 5,
        private readonly int $syncMinIntervalSeconds = 600,
        private readonly int $maxCodesPerUserPerHour = 3
    ) {
    }

    /**
     * Handles New User Registration
     *
     * @return array{success: true, user_id: int}|array{success: false, error: string}
     */
    public function register(
        string $username,
        string $email,
        string $captchaToken,
        string $ip
    ): array {
        if (!$this->captcha->verify($captchaToken, $ip)) {
            return [
                'success' => false,
                'error' => 'Security check failed. Please refresh and try again.',
            ];
        }

        $username = trim($username);
        $email = strtolower(trim($email));

        if (strlen($username) < 3 || strlen($username) > 20) {
            return ['success' => false, 'error' => 'Username must be 3–20 characters.'];
        }

        // Whitelist mirrors the register form's client pattern (letters, digits,
        // underscore). Excludes every HTML/JS metacharacter and avoids Unicode
        // homoglyph/bidi spoofing, so stored XSS can't depend on a missed escape
        // downstream. Server is the authority; the client pattern is only UX.
        if (preg_match('/^[A-Za-z0-9_]+$/', $username) !== 1) {
            return ['success' => false, 'error' => 'Username may only contain letters, numbers, and underscores.'];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Invalid email address.'];
        }

        if ($this->users->findByUsername($username)) {
            return ['success' => false, 'error' => 'Username is already taken.'];
        }

        if ($this->users->findByEmail($email)) {
            return ['success' => false, 'error' => 'Email is already registered.'];
        }

        $user = $this->users->create($username, $email);
        $this->generateAndSendCode($user);

        return ['success' => true, 'user_id' => $user['id']];
    }

    /**
     * Handles Existing User Login
     */
    /** @return array{success: true, user_id: int}|array{success: false, error: string} */
    public function login(string $username, string $captchaToken, string $ip): array
    {
        $username = trim($username);

        // Captcha first: makes blind username-guessing non-automatable, so an
        // attacker can't script the login form to ping whichever real users
        // happen to match their guesses.
        if (!$this->captcha->verify($captchaToken, $ip)) {
            return [
                'success' => false,
                'error' => 'Security check failed. Please refresh and try again.',
            ];
        }

        $key = 'login_ip_' . md5($ip);
        if ($this->limiter->increment($key, 3600) > 10) {
            $this->securityLog->event('login_rate_limited', ['ip' => $ip]);
            return [
                'success' => false,
                'error' => 'Too many attempts. Try again later.',
            ];
        }

        $user = $this->users->findByUsername($username);
        if (!$user) {
            // Log internally for detection, but keep the response identical to a
            // hit so the client can't enumerate usernames.
            $this->securityLog->event('login_unknown_username', ['username' => $username, 'ip' => $ip]);
            return ['success' => true, 'user_id' => 0];
        }

        $this->generateAndSendCode($user);

        return ['success' => true, 'user_id' => $user['id']];
    }

    /**
     * Re-send a code for a login/registration already in progress (the user is
     * sitting on /verify). Bounded by the same per-account cap as the first
     * send, so the resend link can't become a flood vector. The caller only
     * ever knows a pending user id from its own session, so there's no
     * enumeration surface here.
     */
    public function resendCode(int $userId): bool
    {
        $user = $this->users->findById($userId);
        if (!$user) {
            return false;
        }

        return $this->generateAndSendCode($user);
    }

    /**
     * Verify Code and Finalize Login
     */
    public function verifyCode(int $userId, string $code, bool $remember = false): bool
    {
        $user = $this->users->findById($userId);
        if (!$user) {
            return false;
        }

        $token = $this->tokens->findLatestByUserId($userId);
        if (!$token) {
            return false;
        }

        if (new DateTimeImmutable() > new DateTimeImmutable($token['expires_at'])) {
            return false;
        }

        if ($token['attempts'] >= $this->maxAttempts) {
            $this->tokens->delete((int) $token['id']);
            return false;
        }

        $this->tokens->incrementAttempts((int) $token['id']);

        if (
            !hash_equals(
                $token['code_hmac'],
                hash_hmac('sha256', $code, $this->appSecret)
            )
        ) {
            $this->securityLog->event('verify_code_failed', ['user_id' => $userId]);
            return false;
        }

        // First-time verification
        if (empty($user['verified_at'])) {
            $this->users->markVerified((int) $user['id']);
            $email = $this->getDecryptedEmail((int) $user['id']);
            $this->mailer->sendWelcomeEmail($email, $user['username']);
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        session_regenerate_id(true);

        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['sync_status'] = 'idle';
        // A code was just entered: this session is freshly authenticated, which
        // the step-up gate (requireFreshAuth) relies on.
        $_SESSION['auth_fresh'] = true;

        if ($remember) {
            $this->persistentLogin->issue((int) $user['id']);
        }

        if (empty($user['api_token'])) {
            $_SESSION['sync_status'] = 'needs_token';
        } elseif ($this->recentlySynced($user)) {
            // A sync within the interval already warmed the cache — don't spend
            // 8 more API calls just because the user re-logged in.
            $_SESSION['sync_status'] = 'idle';
        } else {
            // trySyncForUser persists DB status and never throws; it returns the
            // outcome, which drives the verify page's immediate feedback.
            $_SESSION['sync_status'] = $this->syncService->trySyncForUser($user, true);
        }

        $this->tokens->delete((int) $token['id']);

        $this->securityLog->event('login_succeeded', [
            'user_id'  => (int) $user['id'],
            'remember' => $remember ? '1' : '0',
        ]);

        return true;
    }

    /**
     * Send a one-time code for step-up re-authentication (no new login session
     * is created — the user is already logged in via a remembered token).
     */
    public function sendReauthCode(int $userId): bool
    {
        $user = $this->users->findById($userId);
        if (!$user) {
            return false;
        }

        $this->generateAndSendCode($user);
        return true;
    }

    /**
     * Validate a step-up code. On success the current session is promoted to
     * "fresh" so the step-up gate lets the pending sensitive action through.
     * Reuses the same one-time verification_tokens used at login.
     */
    public function verifyReauth(int $userId, string $code): bool
    {
        $token = $this->tokens->findLatestByUserId($userId);
        if (!$token) {
            return false;
        }

        if (new DateTimeImmutable() > new DateTimeImmutable($token['expires_at'])) {
            return false;
        }

        if ($token['attempts'] >= $this->maxAttempts) {
            $this->tokens->delete((int) $token['id']);
            return false;
        }

        $this->tokens->incrementAttempts((int) $token['id']);

        if (
            !hash_equals(
                $token['code_hmac'],
                hash_hmac('sha256', $code, $this->appSecret)
            )
        ) {
            return false;
        }

        $this->tokens->delete((int) $token['id']);
        $_SESSION['auth_fresh'] = true;

        return true;
    }

    public function logout(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
        if ($userId > 0) {
            // Kill the persistent token + cookie so a logged-out browser can't
            // be silently signed back in on the next request.
            $this->persistentLogin->clearForUser($userId);
        }

        $_SESSION = [];

        $sessionName = session_name();
        if (ini_get('session.use_cookies') && $sessionName !== false) {
            $params = session_get_cookie_params();
            setcookie(
                $sessionName,
                '',
                [
                    'expires'  => time() - 42000,
                    'path'     => $params['path'],
                    'domain'   => $params['domain'],
                    'secure'   => $params['secure'],
                    'httponly' => $params['httponly'],
                ]
            );
        }

        session_destroy();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * Generates a one-time code and emails it — unless this account has already
     * hit its per-hour code cap. The cap is keyed on the resolved user id (not
     * IP, not the submitted username), so a real user can't be emailed more
     * than $maxCodesPerUserPerHour times an hour no matter how many IPs an
     * attacker rotates through while guessing usernames. Returns false when the
     * cap suppressed the send; callers keep their response generic regardless.
     *
     * @param array<string, mixed> $user
     */
    private function generateAndSendCode(array $user): bool
    {
        $capKey = 'login_code_user_' . (int) $user['id'];
        if ($this->limiter->increment($capKey, 3600) > $this->maxCodesPerUserPerHour) {
            $this->securityLog->event('login_code_capped', ['user_id' => (int) $user['id']]);
            return false;
        }

        $this->tokens->deleteExpired(
            (new DateTimeImmutable())->format('Y-m-d H:i:s')
        );

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hmac = hash_hmac('sha256', $code, $this->appSecret);

        $expiresAt = (new DateTimeImmutable(
            "+{$this->codeTtlSeconds} seconds"
        ))->format('Y-m-d H:i:s');

        $this->tokens->store((int) $user['id'], $hmac, $expiresAt);

        $email = $this->getDecryptedEmail((int) $user['id']);
        $this->mailer->sendVerificationCode($email, $code);

        return true;
    }

    /**
     * True if the user synced within syncMinIntervalSeconds — so a login-time
     * auto-sync would just re-spend API calls on already-warm data.
     *
     * @param array<string, mixed> $user
     */
    private function recentlySynced(array $user): bool
    {
        $last = $user['last_synced_at'] ?? null;
        if (!is_string($last) || $last === '') {
            return false;
        }

        try {
            // markSynced() stores SQLite datetime('now'), which is UTC — parse
            // it as UTC so the comparison against time() (UTC epoch) is correct.
            $lastTs = new DateTimeImmutable($last, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return false;
        }

        return (time() - $lastTs->getTimestamp()) < $this->syncMinIntervalSeconds;
    }

    private function getDecryptedEmail(int $userId): string
    {
        $encryptedEmail = $this->users->findEncryptedEmailById($userId);
        if (!$encryptedEmail) {
            throw new \RuntimeException('User email missing');
        }

        return $this->crypto->decrypt($encryptedEmail);
    }
}
