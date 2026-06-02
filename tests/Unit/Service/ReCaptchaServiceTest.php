<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ReCaptchaService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReCaptchaService::class)]
final class ReCaptchaServiceTest extends TestCase
{
    private function configured(bool $isDev = false): ReCaptchaService
    {
        return new ReCaptchaService(
            siteKey: 'site-key',
            projectId: 'project-id',
            apiKey: 'api-key',
            isDev: $isDev,
        );
    }

    public function testMissingConfigInProdFailsClosed(): void
    {
        // The critical invariant: a missing siteKey / projectId / apiKey in
        // production must NOT let registration through. Returning true here
        // would silently bypass the bot check on a misconfigured deploy.
        $missing = new ReCaptchaService(
            siteKey: '', projectId: '', apiKey: '', isDev: false,
        );

        $this->assertFalse($missing->verify('any-token', '127.0.0.1'));
        $this->assertFalse($missing->verify('', '127.0.0.1'));
    }

    public function testPartialConfigInProdAlsoFailsClosed(): void
    {
        // Only one of the three set — still fail-closed.
        $service = new ReCaptchaService(
            siteKey: 'site-key', projectId: '', apiKey: '', isDev: false,
        );

        $this->assertFalse($service->verify('any-token', '127.0.0.1'));
    }

    public function testMissingConfigInDevSkipsVerification(): void
    {
        // Dev bypass is intentional — lets local development register without
        // hitting GCP. Fenced behind isDev so it cannot engage in prod where
        // IS_DEV is unset/false.
        $service = new ReCaptchaService(
            siteKey: '', projectId: '', apiKey: '', isDev: true,
        );

        $this->assertTrue($service->verify('any-token', '127.0.0.1'));
    }

    public function testEmptyTokenFailsBeforeNetworkCall(): void
    {
        // With all three values configured, an empty client-side token still
        // rejects without calling GCP — saves a roundtrip and avoids a
        // guaranteed negative verification.
        $this->assertFalse($this->configured()->verify('', '127.0.0.1'));
    }
}
