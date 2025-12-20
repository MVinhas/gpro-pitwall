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
        private readonly int $codeTtlSeconds = 600,
        private readonly int $maxAttempts = 5
    ) {
    }

    /**
     * Handles New User Registration
     */
    public function register(string $username, string $email, string $captchaToken, string $ip): array
    {
        if (!$this->captcha->verify($captchaToken, $ip)) {
            return ['success' => false, 'error' => 'Security check failed. Please refresh and try again.'];
        }

        $username = trim($username);
        $email = strtolower(trim($email));

        if (strlen($username) < 3 || strlen($username) > 20) {
            return ['success' => false, 'error' => 'Username must be 3-20 characters.'];
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
    public function login(string $username, string $ip): array
    {
        $username = trim($username);

        // 1. IP Rate Limit
        $key = 'login_ip_' . md5($ip);
        if ($this->limiter->increment($key, 3600) > 10) {
            return ['success' => false, 'error' => 'Too many attempts. Try again later.'];
        }

        // 2. Find User
        $user = $this->users->findByUsername($username);
        if (!$user) {
            return ['success' => true, 'user_id' => 0];
        }

        // 3. Generate & Send Code
        $this->generateAndSendCode($user);

        return ['success' => true, 'user_id' => $user['id']];
    }

    /**
     * @param array<string, mixed> $user
     */
    private function generateAndSendCode(array $user): void
    {
        $this->tokens->deleteExpired((new DateTimeImmutable())->format('Y-m-d H:i:s'));

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hmac = hash_hmac('sha256', $code, $this->appSecret);
        $expiresAt = (new DateTimeImmutable("+{$this->codeTtlSeconds} seconds"))
            ->format('Y-m-d H:i:s');

        $this->tokens->store((int)$user['id'], $hmac, $expiresAt);

        $email = $this->getDecryptedEmail((int)$user['id']);

        $this->mailer->sendVerificationCode($email, $code);
    }


    /**
     * Verify Code and Finalize Login/Reg
     */
    public function verifyCode(int $userId, string $code): bool
    {
        $user = $this->users->findById($userId);
        if (!$user) {
            return false;
        }

        $token = $this->tokens->findLatestByUserId($userId);
        if (!$token) {
            return false;
        }

        // Check Expiry
        if (new DateTimeImmutable() > new DateTimeImmutable($token['expires_at'])) {
            return false;
        }

        // Check Attempts
        if ($token['attempts'] >= $this->maxAttempts) {
            $this->tokens->delete((int)$token['id']);
            return false;
        }

        $this->tokens->incrementAttempts((int)$token['id']);

        // Verify Hash
        if (!hash_equals($token['code_hmac'], hash_hmac('sha256', $code, $this->appSecret))) {
            return false;
        }

        // Success!

        // If first time verifying (Registration flow completion)
        if (empty($user['verified_at'])) {
            $this->users->markVerified((int)$user['id']);
            $email = $this->getDecryptedEmail((int)$user['id']);
            $this->mailer->sendWelcomeEmail($email, $user['username']);
        }

        // Start Session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['is_premium'] = (bool)$user['is_premium'];

        // Cleanup
        $this->tokens->delete((int)$token['id']);

        return true;
    }

    public function logout(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Clear all session variables
        $_SESSION = [];

        // Destroy the session cookie if it exists
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 42000,
                    'path' => $params["path"],
                    'domain' => $params["domain"],
                    'secure' => $params["secure"],
                    'httponly' => $params["httponly"]
                ]
            );
        }

        // Destroy the session data on disk
        session_destroy();

        // Regenerate a fresh session ID for safety
        session_regenerate_id(true);
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
