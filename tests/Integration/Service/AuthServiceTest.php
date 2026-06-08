<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Cache\Adapter\FilesystemCache;
use App\Repository\TokenRepository;
use App\Repository\UserRepository;
use App\Security\ApiTokenCrypto;
use App\Security\EmailCrypto;
use App\Service\AuthService;
use App\Service\EmailService;
use App\Service\GproSyncService;
use App\Service\RateLimiterService;
use App\Service\ReCaptchaService;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Drives AuthService end-to-end against an in-memory SQLite + a
 * filesystem-backed EmailService writing to a per-test temp dir.
 * The verification code is recovered by scanning the .eml subject
 * — same trick the dev mail tail uses.
 */
#[CoversClass(AuthService::class)]
final class AuthServiceTest extends TestCase
{
    private PDO $db;
    private string $mailDir;
    private string $cacheDir;
    private AuthService $auth;

    protected function setUp(): void
    {
        // Sessions: AuthService writes to $_SESSION on verify; PHP's CLI session
        // handler needs a clean slate per test.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];

        $appSecret = 'integration-test-secret-do-not-use-in-prod';

        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                email_encrypted TEXT NOT NULL,
                email_hash TEXT NOT NULL UNIQUE,
                is_admin INTEGER NOT NULL DEFAULT 0,
                api_token TEXT DEFAULT NULL,
                verified_at TEXT DEFAULT NULL,
                sync_status TEXT NOT NULL DEFAULT 'idle',
                last_synced_at TEXT DEFAULT NULL,
                deleted_at TEXT DEFAULT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $this->db->exec("
            CREATE TABLE verification_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                code_hmac TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $this->db->exec("
            CREATE TABLE persistent_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                selector TEXT NOT NULL UNIQUE,
                validator_hash TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $this->mailDir  = sys_get_temp_dir() . '/gpro-test-mail-' . bin2hex(random_bytes(4));
        $this->cacheDir = sys_get_temp_dir() . '/gpro-test-cache-' . bin2hex(random_bytes(4));

        $emailCrypto    = new EmailCrypto($appSecret);
        $apiTokenCrypto = new ApiTokenCrypto($appSecret);
        $userRepo       = new UserRepository($this->db, $emailCrypto, $apiTokenCrypto);
        $tokenRepo      = new TokenRepository($this->db);
        $cache          = new FilesystemCache($this->cacheDir);
        $limiter        = new RateLimiterService($cache);
        $captcha        = new ReCaptchaService(secretKey: '', isDev: true);
        $mailer         = new EmailService([
            'host' => 'localhost', 'port' => 25,
            'from' => 'test@example.invalid', 'from_name' => 'GPRO Test',
            'is_dev' => true, 'mail_dir' => $this->mailDir,
        ]);

        // Sync service should never run during register→verify because a
        // fresh user has no api_token. We still wire a real instance with
        // an api client pointed at an unreachable port so a regression in
        // that assumption fails loudly instead of leaking real HTTP.
        $apiClient = new \App\Service\GproApiClient(
            new \App\Service\GproApiFetcher(['base_url' => 'http://127.0.0.1:9']),
            $cache,
        );
        $sync = new GproSyncService($apiClient, $userRepo, $cache);

        $persistentRepo = new \App\Repository\PersistentTokenRepository($this->db);
        $persistentLogin = new \App\Service\PersistentLoginService(
            $persistentRepo,
            new \App\Tests\Support\ArrayCookieJar(),
            secure: false,
        );

        $this->auth = new AuthService(
            $userRepo, $tokenRepo, $mailer, $limiter, $captcha,
            $emailCrypto, $appSecret, $sync, $persistentLogin,
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->mailDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->mailDir);
        foreach (glob($this->cacheDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->cacheDir);
    }

    public function testRegisterThenVerifyFlowFinalizesLogin(): void
    {
        $result = $this->auth->register('alice', 'alice@example.invalid', '', '127.0.0.1');

        $this->assertTrue($result['success']);
        $userId = $result['user_id'];
        $this->assertGreaterThan(0, $userId);

        $code = $this->codeFromLatestEmail();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $verified = $this->auth->verifyCode($userId, $code);
        $this->assertTrue($verified);

        // Session is populated as side effect of successful verification.
        $this->assertSame($userId, $_SESSION['user_id'] ?? null);
        $this->assertSame('alice', $_SESSION['username'] ?? null);
        $this->assertSame('needs_token', $_SESSION['sync_status'] ?? null);

        // verified_at is now stamped on the row.
        $row = $this->db->query('SELECT verified_at FROM users WHERE id = ' . $userId)
            ?->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertNotNull($row['verified_at']);

        // The token row used for verification is consumed (one-shot).
        $remaining = $this->db->query('SELECT COUNT(*) FROM verification_tokens')
            ?->fetchColumn();
        $this->assertSame(0, (int) $remaining);

        // A freshly verified session is "fresh" (step-up gate passes).
        $this->assertTrue($_SESSION['auth_fresh'] ?? false);
    }

    public function testVerifyWithoutRememberIssuesNoPersistentToken(): void
    {
        $result = $this->auth->register('noremember', 'nr@example.invalid', '', '127.0.0.1');
        $this->assertTrue($this->auth->verifyCode($result['user_id'], $this->codeFromLatestEmail()));

        $count = (int) $this->db->query('SELECT COUNT(*) FROM persistent_tokens')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testVerifyWithRememberIssuesPersistentToken(): void
    {
        $result = $this->auth->register('remember', 'rm@example.invalid', '', '127.0.0.1');
        $this->assertTrue(
            $this->auth->verifyCode($result['user_id'], $this->codeFromLatestEmail(), remember: true)
        );

        $row = $this->db->query('SELECT user_id, validator_hash FROM persistent_tokens')
            ?->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame($result['user_id'], (int) $row['user_id']);
        $this->assertNotEmpty($row['validator_hash']);
    }

    public function testLogoutClearsPersistentTokens(): void
    {
        $result = $this->auth->register('logout', 'lo@example.invalid', '', '127.0.0.1');
        $this->auth->verifyCode($result['user_id'], $this->codeFromLatestEmail(), remember: true);
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM persistent_tokens')->fetchColumn());

        $this->auth->logout();

        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM persistent_tokens')->fetchColumn());
    }

    public function testWrongCodeIsRejectedAndDoesNotLogIn(): void
    {
        $result = $this->auth->register('bob', 'bob@example.invalid', '', '127.0.0.1');
        $userId = $result['user_id'];

        $this->assertFalse($this->auth->verifyCode($userId, '000000'));
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testCodeIsRejectedOnceMaxAttemptsReached(): void
    {
        $result = $this->auth->register('carol', 'carol@example.invalid', '', '127.0.0.1');
        $userId = $result['user_id'];

        // 5 wrong attempts. The 6th is short-circuited by maxAttempts even if
        // we pass the correct code.
        for ($i = 0; $i < 5; $i++) {
            $this->auth->verifyCode($userId, '111111');
        }

        $code = $this->codeFromLatestEmail();
        $this->assertFalse($this->auth->verifyCode($userId, $code));
    }

    public function testDuplicateEmailIsRejected(): void
    {
        $first = $this->auth->register('dave', 'dave@example.invalid', '', '127.0.0.1');
        $this->assertTrue($first['success']);

        $second = $this->auth->register('dave2', 'dave@example.invalid', '', '127.0.0.1');
        $this->assertFalse($second['success']);
        $this->assertStringContainsString('Email', $second['error']);
    }

    public function testDuplicateUsernameIsRejected(): void
    {
        $first = $this->auth->register('eve', 'eve1@example.invalid', '', '127.0.0.1');
        $this->assertTrue($first['success']);

        $second = $this->auth->register('eve', 'eve2@example.invalid', '', '127.0.0.1');
        $this->assertFalse($second['success']);
        $this->assertStringContainsString('Username', $second['error']);
    }

    /**
     * Scan the freshly-written .eml in mailDir for the subject's leading
     * 6-digit verification code.
     */
    private function codeFromLatestEmail(): string
    {
        $files = glob($this->mailDir . '/*.eml') ?: [];
        $this->assertNotEmpty($files, 'No verification email written');

        // Newest first.
        usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));

        $content = file_get_contents($files[0]);
        $this->assertIsString($content);

        if (preg_match('/Subject: (\d{6}) /', $content, $m) !== 1) {
            $this->fail('Could not extract code from email subject');
        }
        return $m[1];
    }
}
