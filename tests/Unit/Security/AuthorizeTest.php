<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\Authorize;
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
}
