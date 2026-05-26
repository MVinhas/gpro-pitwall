<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\Authorize;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Authorize::class)]
final class AuthorizeTest extends TestCase
{
    public function testPremiumUserHasPremiumAccess(): void
    {
        $this->assertTrue(Authorize::hasPremiumAccess(['is_premium' => 1, 'is_admin' => 0]));
    }

    public function testAdminInheritsPremiumAccess(): void
    {
        // Admins must be able to use every premium-gated route — otherwise
        // running the app as an admin would be more restrictive than as a premium user.
        $this->assertTrue(Authorize::hasPremiumAccess(['is_premium' => 0, 'is_admin' => 1]));
    }

    public function testFreeUserHasNoPremiumAccess(): void
    {
        $this->assertFalse(Authorize::hasPremiumAccess(['is_premium' => 0, 'is_admin' => 0]));
    }

    public function testMissingFlagsAreTreatedAsFreeTier(): void
    {
        // Fail-closed: a malformed user row must NOT escalate privileges.
        $this->assertFalse(Authorize::hasPremiumAccess([]));
        $this->assertFalse(Authorize::hasAdminAccess([]));
    }

    public function testStringDigitFlagIsAccepted(): void
    {
        // SQLite/PDO hands back integer columns as PHP strings depending on driver
        // attributes — "1" must still grant access, but non-numeric strings must not.
        $this->assertTrue(Authorize::hasPremiumAccess(['is_premium' => '1']));
        $this->assertFalse(Authorize::hasPremiumAccess(['is_premium' => 'true']));
        $this->assertFalse(Authorize::hasAdminAccess(['is_admin' => 'yes']));
    }

    public function testAdminAccessRequiresAdminFlag(): void
    {
        $this->assertFalse(Authorize::hasAdminAccess(['is_premium' => 1, 'is_admin' => 0]));
        $this->assertTrue(Authorize::hasAdminAccess(['is_premium' => 0, 'is_admin' => 1]));
    }
}
