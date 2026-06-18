<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\UserRepository;
use App\Repository\TokenRepository;
use App\Repository\PendingRegistrationRepository;
use App\Security\EmailCrypto;
use DateTimeImmutable;
use PDOException;

class AuthService
{
    /**
     * Returned as the pending user id when login() is given a username that
     * doesn't exist. It keeps the post-login control flow identical to a real
     * account (the controller still routes to /verify) while guaranteeing the
     * code can never verify — findById() on a negative id always misses. This
     * closes the redirect-based username oracle: a wrong-but-real username and
     * an unknown one now land on the same page.
     */
    public const int DECOY_PENDING_USER_ID = -1;

    /**
     * Registration counterpart of DECOY_PENDING_USER_ID. Returned when someone
     * registers an email that already has a verified account: the response is
     * indistinguishable from a real new registration (success + a pending id
     * that routes to /verify), but no row is created and no code is sent. A
     * find() on a negative id always misses, so it can never verify. This closes
     * the email-existence oracle on the registration form.
     */
    public const int DECOY_PENDING_REGISTRATION_ID = -1;

    public function __construct(
        private readonly UserRepository $users,
        private readonly TokenRepository $tokens,
        private readonly PendingRegistrationRepository $pending,
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
     * Handles New User Registration.
     *
     * Creates a *pending* registration — never a `users` row — and emails a
     * code. The account only materialises in `users` when verifyRegistration()
     * promotes it, so an unverified attempt can never squat a username/email or
     * block a real person.
     *
     * @return array{success: true, registration_id: int}|array{success: false, error: string}
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

        // Username availability is surfaced deliberately: it's standard UX (you
        // must let the user pick another), only verified accounts are checked,
        // and it leaks nothing private. Email existence is the sensitive bit and
        // is handled below, not here.
        if ($this->users->findByUsername($username)) {
            return ['success' => false, 'error' => 'Username is already taken.'];
        }

        // Never reveal whether an email already has an account. If it does,
        // return the exact shape of a successful registration (a pending id that
        // routes to /verify) but create nothing and send nothing — the decoy id
        // can't verify. An observer can't distinguish "email taken" from "new".
        if ($this->users->findByEmail($email)) {
            $this->securityLog->event('register_existing_email', ['ip' => $ip]);
            return ['success' => true, 'registration_id' => self::DECOY_PENDING_REGISTRATION_ID];
        }

        $this->pending->deleteExpired((new DateTimeImmutable())->format('Y-m-d H:i:s'));

        [$code, $hmac, $expiresAt] = $this->freshCode();
        $registrationId = $this->pending->create($username, $email, $hmac, $expiresAt);

        // Seed the per-registration code cap so resends share one budget.
        $this->limiter->increment('register_code_' . $registrationId, 3600);
        $this->mailer->sendVerificationCode($email, $code);

        return ['success' => true, 'registration_id' => $registrationId];
    }

    /**
     * Verify a pending registration's code and promote it into a real account.
     *
     * The promotion INSERT is the authority that resolves any race: if two
     * people held a pending row for the same username (or email), whoever
     * verifies first wins, and the loser hits the UNIQUE constraint and is told
     * the name is taken. This is the correct fairness rule — the name goes to
     * whoever proves email control first, not whoever clicked register first.
     *
     * @return array{success: true, user_id: int}|array{success: false, error: string}
     */
    public function verifyRegistration(int $registrationId, string $code, bool $remember = false): array
    {
        $pending = $this->pending->find($registrationId);
        if (!$pending) {
            return ['success' => false, 'error' => 'Invalid code or expired.'];
        }

        if (new DateTimeImmutable() > new DateTimeImmutable($pending['expires_at'])) {
            $this->pending->delete($registrationId);
            return ['success' => false, 'error' => 'Invalid code or expired.'];
        }

        if ((int) $pending['attempts'] >= $this->maxAttempts) {
            $this->pending->delete($registrationId);
            return ['success' => false, 'error' => 'Invalid code or expired.'];
        }

        $this->pending->incrementAttempts($registrationId);

        if (
            !hash_equals(
                (string) $pending['code_hmac'],
                hash_hmac('sha256', $code, $this->appSecret)
            )
        ) {
            $this->securityLog->event('register_code_failed', ['registration_id' => $registrationId]);
            return ['success' => false, 'error' => 'Invalid code or expired.'];
        }

        $email = $this->crypto->decrypt((string) $pending['email_encrypted']);

        try {
            $user = $this->users->create((string) $pending['username'], $email);
        } catch (PDOException) {
            // Lost the race for this username/email (or a duplicate slipped in):
            // the UNIQUE constraint is the authority. Discard the pending row.
            $this->pending->delete($registrationId);
            $this->securityLog->event('register_promote_conflict', ['registration_id' => $registrationId]);
            return ['success' => false, 'error' => 'That username or email is already registered.'];
        }

        if ($user === null) {
            return ['success' => false, 'error' => 'Could not complete registration. Please try again.'];
        }

        $userId = (int) $user['id'];
        $this->users->markVerified($userId);
        // The promoted email is now owned by a real account; drop any sibling
        // pending rows for it so they don't linger until TTL.
        $this->pending->deleteByEmailHash((string) $pending['email_hash']);
        $this->mailer->sendWelcomeEmail($email, (string) $user['username']);

        $fresh = $this->users->findById($userId) ?? $user;
        $this->establishVerifiedSession($fresh, $remember);

        $this->securityLog->event('registration_completed', ['user_id' => $userId]);

        return ['success' => true, 'user_id' => $userId];
    }

    /**
     * Re-send the code for an in-progress registration (the user is on /verify).
     * Bounded by the same per-registration cap as the first send so the resend
     * link can't become a flood vector. A miss (unknown/decoy id) is a silent
     * no-op so the caller's response stays generic.
     */
    public function resendRegistrationCode(int $registrationId): bool
    {
        $pending = $this->pending->find($registrationId);
        if (!$pending) {
            return false;
        }

        $capKey = 'register_code_' . $registrationId;
        if ($this->limiter->increment($capKey, 3600) > $this->maxCodesPerUserPerHour) {
            $this->securityLog->event('register_code_capped', ['registration_id' => $registrationId]);
            return false;
        }

        [$code, $hmac, $expiresAt] = $this->freshCode();
        $this->pending->updateCode($registrationId, $hmac, $expiresAt);
        $this->mailer->sendVerificationCode(
            $this->crypto->decrypt((string) $pending['email_encrypted']),
            $code
        );

        return true;
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
            // hit so the client can't enumerate usernames. The decoy pending id
            // makes the *redirect* identical too (both go to /verify) — without
            // it, an unknown username bounced to /login while a real one stayed
            // on /verify, which is itself an enumeration oracle.
            $this->securityLog->event('login_unknown_username', ['username' => $username, 'ip' => $ip]);
            return ['success' => true, 'user_id' => self::DECOY_PENDING_USER_ID];
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

        // First-time verification. Post-split this is effectively a safety net
        // (registration verifies via verifyRegistration() and `users` holds only
        // verified rows) — kept so any manually-inserted or legacy unverified
        // row still gets a welcome on its first login.
        if (empty($user['verified_at'])) {
            $this->users->markVerified((int) $user['id']);
            $email = $this->getDecryptedEmail((int) $user['id']);
            $this->mailer->sendWelcomeEmail($email, $user['username']);
        }

        $this->establishVerifiedSession($user, $remember);

        $this->tokens->delete((int) $token['id']);

        $this->securityLog->event('login_succeeded', [
            'user_id'  => (int) $user['id'],
            'remember' => $remember ? '1' : '0',
        ]);

        return true;
    }

    /**
     * Promote the current PHP session to an authenticated, fresh one for $user
     * and resolve the post-login sync status. Shared by login (verifyCode) and
     * registration (verifyRegistration) so both establish identical session
     * state. Does not touch the verification artefact (token / pending row) —
     * the caller owns that.
     *
     * @param array<string, mixed> $user
     */
    private function establishVerifiedSession(array $user, bool $remember): void
    {
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

        [$code, $hmac, $expiresAt] = $this->freshCode();
        $this->tokens->store((int) $user['id'], $hmac, $expiresAt);

        $email = $this->getDecryptedEmail((int) $user['id']);
        $this->mailer->sendVerificationCode($email, $code);

        return true;
    }

    /**
     * Mint a one-time 6-digit code: returns [plaintext, hmac, expiresAt]. The
     * plaintext is emailed; only the HMAC is ever persisted.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function freshCode(): array
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hmac = hash_hmac('sha256', $code, $this->appSecret);
        $expiresAt = (new DateTimeImmutable(
            "+{$this->codeTtlSeconds} seconds"
        ))->format('Y-m-d H:i:s');

        return [$code, $hmac, $expiresAt];
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
