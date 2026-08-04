<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Repository\PersistentTokenRepository;
use App\Service\PersistentLoginService;
use App\Service\SecurityLogger;
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
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                previous_validator_hash TEXT DEFAULT NULL,
                rotated_at TEXT DEFAULT NULL
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

        // The OLD cookie must no longer authenticate once the rotation grace
        // window has passed (replay protection).
        $this->ageRotation(120);
        $this->jar->store[PersistentLoginService::COOKIE_NAME] = $firstCookie;
        $this->assertNull($this->service->restore());
    }

    public function testSupersededValidatorStillWorksInsideGraceWindow(): void
    {
        // A page fires several requests at once; the first rotates, the rest
        // arrive holding the validator it just replaced. That must not be read
        // as theft, or the whole "keep me signed in" token gets revoked.
        $this->service->issue(7);
        $firstCookie = $this->jar->store[PersistentLoginService::COOKIE_NAME];
        [$selector] = explode(':', $firstCookie, 2);

        $this->assertSame(7, $this->service->restore());

        $this->jar->store[PersistentLoginService::COOKIE_NAME] = $firstCookie;
        $this->assertSame(7, $this->service->restore());

        // Crucially, the token survives — the racing request revoked nothing.
        $this->assertNotNull($this->repo->findBySelector($selector));
    }

    public function testGraceAcceptanceDoesNotRotateAgain(): void
    {
        // The winning request already issued the current cookie; a raced one
        // must not overwrite it, or the browser ends up holding a validator
        // the next request can't use.
        $this->service->issue(7);
        $firstCookie = $this->jar->store[PersistentLoginService::COOKIE_NAME];

        $this->service->restore();
        $rotatedCookie = $this->jar->store[PersistentLoginService::COOKIE_NAME];

        $this->jar->store[PersistentLoginService::COOKIE_NAME] = $firstCookie;
        $this->service->restore();

        $row = $this->repo->findBySelector(explode(':', $firstCookie, 2)[0]);
        $this->assertNotNull($row);
        $this->assertSame(
            hash('sha256', explode(':', $rotatedCookie, 2)[1]),
            $row['validator_hash'],
        );
    }

    public function testSupersededValidatorIsRevokedAfterGraceWindow(): void
    {
        $this->service->issue(7);
        $firstCookie = $this->jar->store[PersistentLoginService::COOKIE_NAME];
        [$selector] = explode(':', $firstCookie, 2);

        $this->assertSame(7, $this->service->restore());

        // Same replay, but late — that's a stolen cookie, not a race.
        $this->ageRotation(120);
        $this->jar->store[PersistentLoginService::COOKIE_NAME] = $firstCookie;

        $this->assertNull($this->service->restore());
        $this->assertNull($this->repo->findBySelector($selector));
    }

    /** Backdate the last rotation so the grace window has elapsed. */
    private function ageRotation(int $seconds): void
    {
        $stmt = $this->db->prepare('UPDATE persistent_tokens SET rotated_at = :ts');
        $stmt->execute([
            'ts' => date('Y-m-d H:i:s', time() - $seconds),
        ]);
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

    public function testTheftEmitsSecurityEvent(): void
    {
        $events = [];
        $service = new PersistentLoginService(
            $this->repo,
            $this->jar,
            secure: false,
            securityLog: new SecurityLogger(function (string $l) use (&$events): void {
                $events[] = $l;
            }),
        );

        $service->issue(1);
        $cookie = $this->jar->store[PersistentLoginService::COOKIE_NAME];
        [$selector] = explode(':', $cookie, 2);
        $this->jar->store[PersistentLoginService::COOKIE_NAME] = $selector . ':forged';

        $this->assertNull($service->restore());
        $this->assertCount(1, $events);
        $this->assertStringContainsString('token_theft_detected', $events[0]);
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
