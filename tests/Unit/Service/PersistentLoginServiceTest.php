<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Repository\PersistentTokenRepository;
use App\Service\PersistentLoginService;
use App\Tests\Support\ArrayCookieJar;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PersistentLoginService::class)]
final class PersistentLoginServiceTest extends TestCase
{
    private PDO $db;
    private PersistentTokenRepository $repo;
    private ArrayCookieJar $jar;
    private PersistentLoginService $service;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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

        $this->repo = new PersistentTokenRepository($this->db);
        $this->jar = new ArrayCookieJar();
        $this->service = new PersistentLoginService($this->repo, $this->jar, secure: false);
    }

    public function testIssueThenRestoreRoundTrip(): void
    {
        $this->service->issue(99);

        // Cookie was written and a row exists.
        $this->assertArrayHasKey(PersistentLoginService::COOKIE_NAME, $this->jar->store);
        $this->assertSame(99, $this->service->restore());
    }

    public function testRestoreReturnsNullWhenNoCookie(): void
    {
        $this->assertNull($this->service->restore());
    }

    public function testValidatorRotatesOnEachRestore(): void
    {
        $this->service->issue(5);
        $firstCookie = $this->jar->store[PersistentLoginService::COOKIE_NAME];

        $this->assertSame(5, $this->service->restore());
        $secondCookie = $this->jar->store[PersistentLoginService::COOKIE_NAME];

        // Rotation: the validator (hence the cookie) changed after use.
        $this->assertNotSame($firstCookie, $secondCookie);

        // The OLD cookie must no longer authenticate (replay protection).
        $this->jar->store[PersistentLoginService::COOKIE_NAME] = $firstCookie;
        $this->assertNull($this->service->restore());
    }

    public function testExpiredTokenIsRejected(): void
    {
        $this->service->issue(1);
        // Force the row to be expired.
        $this->db->exec("UPDATE persistent_tokens SET expires_at = '2000-01-01 00:00:00'");

        $this->assertNull($this->service->restore());
    }

    public function testTheftWrongValidatorRevokesTheToken(): void
    {
        $this->service->issue(1);
        $cookie = $this->jar->store[PersistentLoginService::COOKIE_NAME];
        [$selector] = explode(':', $cookie, 2);

        // Attacker presents the right selector but a forged validator.
        $this->jar->store[PersistentLoginService::COOKIE_NAME] = $selector . ':forged-validator';

        $this->assertNull($this->service->restore());
        // Theft response: the row is revoked, so even the legitimate cookie dies.
        $this->assertNull($this->repo->findBySelector($selector));
    }

    public function testMalformedCookieIsIgnored(): void
    {
        $this->jar->store[PersistentLoginService::COOKIE_NAME] = 'no-colon-here';
        $this->assertNull($this->service->restore());
    }

    public function testClearForUserInvalidatesTokenAndCookie(): void
    {
        $this->service->issue(3);
        $this->service->clearForUser(3);

        $this->assertArrayNotHasKey(PersistentLoginService::COOKIE_NAME, $this->jar->store);
        $this->assertNull($this->service->restore());

        $count = (int) $this->db->query('SELECT COUNT(*) FROM persistent_tokens')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testValidatorIsNotStoredInPlaintext(): void
    {
        $this->service->issue(1);
        $cookie = $this->jar->store[PersistentLoginService::COOKIE_NAME];
        [, $validator] = explode(':', $cookie, 2);

        $stored = (string) $this->db->query('SELECT validator_hash FROM persistent_tokens')->fetchColumn();
        $this->assertNotSame($validator, $stored);
        $this->assertSame(hash('sha256', $validator), $stored);
    }
}
