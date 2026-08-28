<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\Csrf;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[CoversClass(Csrf::class)]
final class CsrfTest extends TestCase
{
    private Csrf $csrf;

    protected function setUp(): void
    {
        $this->csrf = new Csrf();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * Without an active session there is nothing to compare against, so every
     * token must be refused — a CSRF check that passes on a dead session is
     * no check at all.
     */
    public function testValidationFailsOutrightWithoutAnActiveSession(): void
    {
        $_SESSION['csrf_token'] = 'a-token';

        $this->assertFalse($this->csrf->validate('a-token'));
    }

    public function testANullTokenIsAlwaysRejected(): void
    {
        $this->assertFalse($this->csrf->validate(null));
    }

    #[RunInSeparateProcess]
    public function testGetTokenMintsAStoredHexToken(): void
    {
        $token = (new Csrf())->getToken();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertSame($token, $_SESSION['csrf_token']);
    }

    #[RunInSeparateProcess]
    public function testGetTokenIsStableWithinASession(): void
    {
        $csrf = new Csrf();

        $this->assertSame($csrf->getToken(), $csrf->getToken());
    }

    #[RunInSeparateProcess]
    public function testTheMatchingTokenValidates(): void
    {
        $csrf = new Csrf();
        $token = $csrf->getToken();

        $this->assertTrue($csrf->validate($token));
    }

    #[RunInSeparateProcess]
    public function testAMismatchedTokenIsRejected(): void
    {
        $csrf = new Csrf();
        $csrf->getToken();

        $this->assertFalse($csrf->validate('not-the-token'));
    }

    #[RunInSeparateProcess]
    public function testAnEmptyTokenIsRejectedEvenWithASession(): void
    {
        $csrf = new Csrf();
        $csrf->getToken();

        $this->assertFalse($csrf->validate(''));
    }

    #[RunInSeparateProcess]
    public function testValidationFailsWhenTheSessionHoldsNoToken(): void
    {
        session_start();
        $_SESSION = [];

        $this->assertFalse((new Csrf())->validate('anything'));
    }

    #[RunInSeparateProcess]
    public function testRegenerateReplacesTheTokenAndInvalidatesTheOldOne(): void
    {
        $csrf = new Csrf();
        $old = $csrf->getToken();

        $csrf->regenerate();
        $new = $csrf->getToken();

        $this->assertNotSame($old, $new);
        $this->assertFalse($csrf->validate($old));
        $this->assertTrue($csrf->validate($new));
    }

    #[RunInSeparateProcess]
    public function testRegenerateWithoutAPriorTokenStillMintsOne(): void
    {
        $csrf = new Csrf();
        $csrf->regenerate();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $csrf->getToken());
    }
}
