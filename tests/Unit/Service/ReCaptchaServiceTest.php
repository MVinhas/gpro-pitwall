<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ReCaptchaService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReCaptchaService::class)]
final class ReCaptchaServiceTest extends TestCase
{
    public function testEmptySecretInProdFailsClosed(): void
    {
        // The critical invariant: a missing RECAPTCHA_SECRET_KEY in production
        // must NOT let registration through. Returning true here would silently
        // bypass the bot check on a misconfigured deploy.
        $service = new ReCaptchaService(secretKey: '', isDev: false);

        $this->assertFalse($service->verify('any-token', '127.0.0.1'));
        $this->assertFalse($service->verify('', '127.0.0.1'));
    }

    public function testEmptySecretInDevSkipsVerification(): void
    {
        // Dev bypass is intentional — lets local development register without
        // hitting Google's reCAPTCHA endpoint. Fenced behind isDev so it cannot
        // engage in prod where IS_DEV is unset/false.
        $service = new ReCaptchaService(secretKey: '', isDev: true);

        $this->assertTrue($service->verify('any-token', '127.0.0.1'));
    }

    public function testEmptyTokenFailsBeforeNetworkCall(): void
    {
        // With a configured key, an empty client-side token still rejects
        // without calling Google — saves a roundtrip and avoids a guaranteed
        // negative verification.
        $service = new ReCaptchaService(secretKey: 'configured-secret', isDev: false);

        $this->assertFalse($service->verify('', '127.0.0.1'));
    }
}
