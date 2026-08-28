<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Http\HttpException;
use App\Repository\UserRepository;
use App\Security\ApiTokenCrypto;
use App\Security\Authorize;
use App\Security\EmailCrypto;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Authorize::class)]
final class AuthorizeTest extends TestCase
{
    public function testAdminFlagGrantsAdminAccess(): void
    {
        $this->assertTrue(Authorize::hasAdminAccess(['is_admin' => 1]));
    }

    public function testMissingFlagFailsClosed(): void
    {
        $this->assertFalse(Authorize::hasAdminAccess([]));
    }

    public function testStringDigitFlagIsAccepted(): void
    {
        // SQLite/PDO hands back integer columns as strings depending on driver
        // attributes — "1" must still grant access, "true"/"yes" must not.
        $this->assertTrue(Authorize::hasAdminAccess(['is_admin' => '1']));
        $this->assertFalse(Authorize::hasAdminAccess(['is_admin' => 'yes']));
        $this->assertFalse(Authorize::hasAdminAccess(['is_admin' => 'true']));
    }

    public function testNonAdminRowIsRejected(): void
    {
        $this->assertFalse(Authorize::hasAdminAccess(['is_admin' => 0]));
    }

    private const string SECRET = 'authorize-test-secret-not-prod';

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function authorizeWith(?int $userId, bool $isAdmin = false, bool $deleted = false): Authorize
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec(
            "CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT,
                email_encrypted TEXT,
                email_hash TEXT,
                api_token TEXT DEFAULT NULL,
                is_admin INTEGER NOT NULL DEFAULT 0,
                verified_at TEXT DEFAULT NULL,
                last_synced_at TEXT DEFAULT NULL,
                sync_status TEXT NOT NULL DEFAULT 'idle',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                deleted_at TEXT DEFAULT NULL
            )"
        );

        $users = new UserRepository($db, new EmailCrypto(self::SECRET), new ApiTokenCrypto(self::SECRET));

        if ($userId !== null) {
            $user = $users->create('racer', 'racer@example.test');
            self::assertIsArray($user);
            $id = (int) $user['id'];
            $users->updateAdmin($id, $isAdmin);
            if ($deleted) {
                $users->softDelete($id);
            }
            $_SESSION['user_id'] = $id;
        }

        return new Authorize($users);
    }

    public function testCurrentUserIdIsNullWithoutASession(): void
    {
        $this->assertNull($this->authorizeWith(null)->currentUserId());
    }

    public function testCurrentUserIdReadsTheSessionValue(): void
    {
        $authorize = $this->authorizeWith(1);

        $this->assertSame($_SESSION['user_id'], $authorize->currentUserId());
    }

    /** PDO hands integer columns back as strings on some drivers. */
    public function testCurrentUserIdAcceptsANumericStringSessionValue(): void
    {
        $authorize = $this->authorizeWith(null);
        $_SESSION['user_id'] = '42';

        $this->assertSame(42, $authorize->currentUserId());
    }

    public function testANonNumericSessionValueIsTreatedAsNoSession(): void
    {
        $authorize = $this->authorizeWith(null);
        $_SESSION['user_id'] = 'not-an-id';

        $this->assertNull($authorize->currentUserId());
    }

    public function testIsAdminIsFalseForAnAnonymousVisitor(): void
    {
        $this->assertFalse($this->authorizeWith(null)->isAdmin());
    }

    public function testIsAdminIsFalseForAPlainUser(): void
    {
        $this->assertFalse($this->authorizeWith(1, isAdmin: false)->isAdmin());
    }

    public function testIsAdminIsTrueForAnAdmin(): void
    {
        $this->assertTrue($this->authorizeWith(1, isAdmin: true)->isAdmin());
    }

    /**
     * A session pointing at a row that has since been soft-deleted must fail
     * closed — the admin flag on a deleted account grants nothing.
     */
    public function testIsAdminIsFalseWhenTheSessionPointsAtADeletedUser(): void
    {
        $this->assertFalse($this->authorizeWith(1, isAdmin: true, deleted: true)->isAdmin());
    }

    public function testIsAdminIsFalseWhenTheSessionPointsAtAMissingUser(): void
    {
        $authorize = $this->authorizeWith(null);
        $_SESSION['user_id'] = 4242;

        $this->assertFalse($authorize->isAdmin());
    }

    public function testRequireAuthReturnsTheLoggedInUser(): void
    {
        $authorize = $this->authorizeWith(1);

        $user = $authorize->requireAuth();

        $this->assertSame('racer', $user['username']);
    }

    public function testRequireAdminReturnsTheUserWhenTheFlagIsSet(): void
    {
        $authorize = $this->authorizeWith(1, isAdmin: true);

        $this->assertSame('racer', $authorize->requireAdmin()['username']);
    }

    public function testRequireAdminForbidsAPlainUser(): void
    {
        $authorize = $this->authorizeWith(1, isAdmin: false);

        $this->expectException(HttpException::class);
        $authorize->requireAdmin();
    }

    public function testRequireFreshAuthReturnsTheUserWhenTheSessionIsFresh(): void
    {
        $authorize = $this->authorizeWith(1);
        $_SESSION['auth_fresh'] = true;

        $this->assertSame('racer', $authorize->requireFreshAuth('/settings')['username']);
    }
}
