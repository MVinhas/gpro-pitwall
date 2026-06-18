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
        $this->db->exec("
            CREATE TABLE pending_registrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL,
                email_encrypted TEXT NOT NULL,
                email_hash TEXT NOT NULL,
                code_hmac TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $this->mailDir  = sys_get_temp_dir() . '/gpro-test-mail-' . bin2hex(random_bytes(4));
        $this->cacheDir = sys_get_temp_dir() . '/gpro-test-cache-' . bin2hex(random_bytes(4));

        $emailCrypto    = new EmailCrypto($appSecret);
        $apiTokenCrypto = new ApiTokenCrypto($appSecret);
        $userRepo       = new UserRepository($this->db, $emailCrypto, $apiTokenCrypto);
        $tokenRepo      = new TokenRepository($this->db);
        $pendingRepo    = new \App\Repository\PendingRegistrationRepository($this->db, $emailCrypto);
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
            $userRepo, $tokenRepo, $pendingRepo, $mailer, $limiter, $captcha,
            $emailCrypto, $appSecret, $sync, $persistentLogin,
            new \App\Service\SecurityLogger(static fn(string $l) => null),
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
        $registrationId = $result['registration_id'];
        $this->assertGreaterThan(0, $registrationId);

        $code = $this->codeFromLatestEmail();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        // Registration creates NO users row — only a disposable pending row.
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $this->assertSame(1, $this->pendingCount());

        $res = $this->auth->verifyRegistration($registrationId, $code);
        $this->assertTrue($res['success']);
        $userId = $res['user_id'];
        $this->assertGreaterThan(0, $userId);

        // Session is populated as side effect of successful verification.
        $this->assertSame($userId, $_SESSION['user_id'] ?? null);
        $this->assertSame('alice', $_SESSION['username'] ?? null);
        $this->assertSame('needs_token', $_SESSION['sync_status'] ?? null);

        // The account now exists in users and is verified.
        $row = $this->db->query('SELECT verified_at FROM users WHERE id = ' . $userId)
            ?->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertNotNull($row['verified_at']);

        // The pending row is consumed on promotion (one-shot).
        $this->assertSame(0, $this->pendingCount());

        // A freshly verified session is "fresh" (step-up gate passes).
        $this->assertTrue($_SESSION['auth_fresh'] ?? false);
    }

    public function testVerifyWithoutRememberIssuesNoPersistentToken(): void
    {
        $result = $this->auth->register('noremember', 'nr@example.invalid', '', '127.0.0.1');
        $this->assertTrue(
            $this->auth->verifyRegistration($result['registration_id'], $this->codeFromLatestEmail())['success']
        );

        $count = (int) $this->db->query('SELECT COUNT(*) FROM persistent_tokens')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testVerifyWithRememberIssuesPersistentToken(): void
    {
        $result = $this->auth->register('remember', 'rm@example.invalid', '', '127.0.0.1');
        $res = $this->auth->verifyRegistration(
            $result['registration_id'],
            $this->codeFromLatestEmail(),
            remember: true
        );
        $this->assertTrue($res['success']);

        $row = $this->db->query('SELECT user_id, validator_hash FROM persistent_tokens')
            ?->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame($res['user_id'], (int) $row['user_id']);
        $this->assertNotEmpty($row['validator_hash']);
    }

    public function testLogoutClearsPersistentTokens(): void
    {
        $result = $this->auth->register('logout', 'lo@example.invalid', '', '127.0.0.1');
        $this->auth->verifyRegistration($result['registration_id'], $this->codeFromLatestEmail(), remember: true);
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM persistent_tokens')->fetchColumn());

        $this->auth->logout();

        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM persistent_tokens')->fetchColumn());
    }

    public function testWrongCodeIsRejectedAndDoesNotCreateAccount(): void
    {
        $result = $this->auth->register('bob', 'bob@example.invalid', '', '127.0.0.1');

        $this->assertFalse($this->auth->verifyRegistration($result['registration_id'], '000000')['success']);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
        // No account materialises from a failed verification.
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testCodeIsRejectedOnceMaxAttemptsReached(): void
    {
        $result = $this->auth->register('carol', 'carol@example.invalid', '', '127.0.0.1');
        $registrationId = $result['registration_id'];
        $code = $this->codeFromLatestEmail();

        // 5 wrong attempts exhaust the budget and discard the pending row; the
        // 6th fails even with the correct code.
        for ($i = 0; $i < 5; $i++) {
            $this->auth->verifyRegistration($registrationId, '111111');
        }

        $this->assertFalse($this->auth->verifyRegistration($registrationId, $code)['success']);
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testRegisteringAnExistingEmailRevealsNothingAndSendsNoCode(): void
    {
        // First account fully created and verified.
        $first = $this->auth->register('dave', 'dave@example.invalid', '', '127.0.0.1');
        $this->assertTrue(
            $this->auth->verifyRegistration($first['registration_id'], $this->codeFromLatestEmail())['success']
        );

        $pendingBefore = $this->pendingCount();
        $mailsBefore   = count(glob($this->mailDir . '/*.eml') ?: []);

        // Re-registering that email under a different username must be
        // indistinguishable from a brand-new registration (no enumeration), yet
        // create no pending row and send no new email — the decoy id can't verify.
        $second = $this->auth->register('dave2', 'dave@example.invalid', '', '127.0.0.1');
        $this->assertTrue($second['success']);
        $this->assertSame(AuthService::DECOY_PENDING_REGISTRATION_ID, $second['registration_id']);

        $this->assertSame($pendingBefore, $this->pendingCount(), 'no pending row may be created');
        $this->assertSame($mailsBefore, count(glob($this->mailDir . '/*.eml') ?: []), 'no email may be sent');

        $this->assertFalse(
            $this->auth->verifyRegistration(AuthService::DECOY_PENDING_REGISTRATION_ID, '000000')['success']
        );
    }

    public function testDuplicateUsernameOfVerifiedAccountIsRejected(): void
    {
        $first = $this->auth->register('eve', 'eve1@example.invalid', '', '127.0.0.1');
        $this->assertTrue(
            $this->auth->verifyRegistration($first['registration_id'], $this->codeFromLatestEmail())['success']
        );

        $second = $this->auth->register('eve', 'eve2@example.invalid', '', '127.0.0.1');
        $this->assertFalse($second['success']);
        $this->assertStringContainsString('Username', $second['error']);
    }

    public function testConcurrentPendingSameUsernameIsResolvedByWhoVerifiesFirst(): void
    {
        // Two people start registering the same username while neither is
        // verified. Pending rows carry no UNIQUE constraint, so both are
        // accepted and both receive a code.
        $a = $this->auth->register('coyote', 'a@example.invalid', '', '127.0.0.1');
        $b = $this->auth->register('coyote', 'b@example.invalid', '', '127.0.0.1');
        $this->assertTrue($a['success']);
        $this->assertTrue($b['success']);
        $this->assertSame(2, $this->pendingCount());

        // Force known codes so the assertion never depends on .eml mtime ordering.
        $this->setPendingCode($a['registration_id'], '111111');
        $this->setPendingCode($b['registration_id'], '222222');

        // B proves email control first → wins the username at the authoritative INSERT.
        $this->assertTrue($this->auth->verifyRegistration($b['registration_id'], '222222')['success']);

        $_SESSION = [];

        // A verifies second → loses on the UNIQUE(username) constraint.
        $resA = $this->auth->verifyRegistration($a['registration_id'], '111111');
        $this->assertFalse($resA['success']);
        $this->assertStringContainsString('already registered', $resA['error']);

        // Exactly one real account exists, and it belongs to B (b@…).
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $this->assertSame('coyote', $this->db->query('SELECT username FROM users')->fetchColumn());
    }

    public function testUsernameWithHtmlMetacharactersIsRejected(): void
    {
        foreach (['<svg/onload>', 'a"b', "a'b", 'a&b', 'a/b', 'a\\b', 'a=b', 'a<b', 'jean-luc', 'mr.x', 'a b c'] as $bad) {
            $result = $this->auth->register($bad, 'x' . md5($bad) . '@example.invalid', '', '127.0.0.1');
            $this->assertFalse($result['success'], "Expected rejection for username: {$bad}");
            $this->assertStringContainsString('Username', $result['error']);
        }

        // No user rows and no pending rows were created for any rejected attempt.
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $this->assertSame(0, $this->pendingCount());
    }

    public function testCleanUsernamesAreAccepted(): void
    {
        foreach (['alice', 'Bob_99', 'x__y', 'ABC123'] as $ok) {
            $result = $this->auth->register($ok, 'x' . md5($ok) . '@example.invalid', '', '127.0.0.1');
            $this->assertTrue($result['success'], "Expected acceptance for username: {$ok}");
        }
    }

    public function testLoginIsCappedPerAccountRegardlessOfIp(): void
    {
        // A fully verified user. Registration uses the pending table, so it
        // writes no verification_tokens row and doesn't touch the login cap.
        $reg = $this->auth->register('mallory', 'mallory@example.invalid', '', '127.0.0.1');
        $this->assertTrue(
            $this->auth->verifyRegistration($reg['registration_id'], $this->codeFromLatestEmail())['success']
        );
        $this->assertSame(0, $this->codeSendCount());

        // The cap is 3 login codes/hour per account: the first three logins send,
        // the rest are suppressed even though each comes from a different IP (the
        // targeting is the username, not the source address).
        $this->auth->login('mallory', '', '10.0.0.1');
        $this->auth->login('mallory', '', '10.0.0.2');
        $this->auth->login('mallory', '', '10.0.0.3');
        $this->auth->login('mallory', '', '10.0.0.4');

        // verification_tokens rows are the reliable send counter (the .eml
        // filename is second-resolution and would collide within a single test).
        $this->assertSame(3, $this->codeSendCount());
    }

    public function testLoginWithUnknownUsernameReturnsDecoyPendingState(): void
    {
        // No such user. The response must look like a hit (success + non-zero
        // pending id) so the controller still routes to /verify — closing the
        // redirect-based enumeration oracle — but no code is ever sent.
        $result = $this->auth->login('ghost', '', '127.0.0.1');

        $this->assertTrue($result['success']);
        $this->assertSame(AuthService::DECOY_PENDING_USER_ID, $result['user_id']);
        $this->assertNotSame(0, $result['user_id'], 'decoy id must be truthy so /verify renders');
        $this->assertSame(0, $this->codeSendCount(), 'no code may be sent for an unknown username');

        // The decoy id can never verify, regardless of the code supplied.
        $this->assertFalse($this->auth->verifyCode($result['user_id'], '000000'));
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testLoginRejectedWhenCaptchaFails(): void
    {
        // A captcha that always fails (non-empty secret, so isDev bypass is off
        // and an empty token is rejected).
        $auth = $this->authWithFailingCaptcha();

        $result = $auth->login('whoever', '', '127.0.0.1');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Security check', $result['error']);
        // No code stored — the send is downstream of the captcha gate.
        $this->assertSame(0, $this->codeSendCount());
    }

    public function testResendRegistrationCodeRidesTheSamePerRegistrationCap(): void
    {
        // register() seeds the cap (count = 1). Resending twice reaches the cap
        // of 3; the third resend is suppressed.
        $reg = $this->auth->register('rena', 'rena@example.invalid', '', '127.0.0.1');
        $registrationId = $reg['registration_id'];

        $this->assertTrue($this->auth->resendRegistrationCode($registrationId));   // 2
        $this->assertTrue($this->auth->resendRegistrationCode($registrationId));   // 3
        $this->assertFalse($this->auth->resendRegistrationCode($registrationId));  // capped
    }

    public function testResendRegistrationCodeForUnknownIdIsSilentNoOp(): void
    {
        $this->assertFalse($this->auth->resendRegistrationCode(999999));
        $this->assertFalse($this->auth->resendRegistrationCode(AuthService::DECOY_PENDING_REGISTRATION_ID));
    }

    private function codeSendCount(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM verification_tokens')->fetchColumn();
    }

    private function pendingCount(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM pending_registrations')->fetchColumn();
    }

    /**
     * Overwrite a pending row's code with a known plaintext so verification is
     * deterministic without scraping second-resolution .eml filenames.
     */
    private function setPendingCode(int $registrationId, string $code): void
    {
        $appSecret = 'integration-test-secret-do-not-use-in-prod';
        $stmt = $this->db->prepare('UPDATE pending_registrations SET code_hmac = :h WHERE id = :id');
        $stmt->execute([
            'h'  => hash_hmac('sha256', $code, $appSecret),
            'id' => $registrationId,
        ]);
    }

    private function authWithFailingCaptcha(): AuthService
    {
        $appSecret = 'integration-test-secret-do-not-use-in-prod';
        $emailCrypto    = new EmailCrypto($appSecret);
        $apiTokenCrypto = new ApiTokenCrypto($appSecret);
        $userRepo       = new UserRepository($this->db, $emailCrypto, $apiTokenCrypto);
        $tokenRepo      = new TokenRepository($this->db);
        $pendingRepo    = new \App\Repository\PendingRegistrationRepository($this->db, $emailCrypto);
        $cache          = new FilesystemCache($this->cacheDir);
        $limiter        = new RateLimiterService($cache);
        // Non-empty secret + not-dev → verify() rejects the empty token without
        // ever reaching Google (token === '' short-circuits to false).
        $captcha        = new ReCaptchaService(secretKey: 'x', isDev: false);
        $mailer         = new EmailService([
            'host' => 'localhost', 'port' => 25,
            'from' => 'test@example.invalid', 'from_name' => 'GPRO Test',
            'is_dev' => true, 'mail_dir' => $this->mailDir,
        ]);
        $apiClient = new \App\Service\GproApiClient(
            new \App\Service\GproApiFetcher(['base_url' => 'http://127.0.0.1:9']),
            $cache,
        );
        $sync = new GproSyncService($apiClient, $userRepo, $cache);
        $persistentLogin = new \App\Service\PersistentLoginService(
            new \App\Repository\PersistentTokenRepository($this->db),
            new \App\Tests\Support\ArrayCookieJar(),
            secure: false,
        );

        return new AuthService(
            $userRepo, $tokenRepo, $pendingRepo, $mailer, $limiter, $captcha,
            $emailCrypto, $appSecret, $sync, $persistentLogin,
            new \App\Service\SecurityLogger(static fn(string $l) => null),
        );
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
