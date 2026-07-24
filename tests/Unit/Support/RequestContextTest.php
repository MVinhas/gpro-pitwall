<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\RequestContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestContext::class)]
final class RequestContextTest extends TestCase
{
    public function testXRequestedWithMarksJson(): void
    {
        $this->assertTrue(RequestContext::wantsJson(['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']));
        // Header comparison is case-insensitive — some clients lowercase it.
        $this->assertTrue(RequestContext::wantsJson(['HTTP_X_REQUESTED_WITH' => 'xmlhttprequest']));
    }

    public function testJsonAcceptHeaderMarksJson(): void
    {
        $this->assertTrue(RequestContext::wantsJson(['HTTP_ACCEPT' => 'application/json']));
        $this->assertTrue(RequestContext::wantsJson(['HTTP_ACCEPT' => 'text/html, application/json;q=0.9']));
    }

    public function testBrowserNavigationIsNotJson(): void
    {
        $this->assertFalse(RequestContext::wantsJson([]));
        $this->assertFalse(RequestContext::wantsJson([
            'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ]));
        $this->assertFalse(RequestContext::wantsJson(['HTTP_X_REQUESTED_WITH' => 'fetch']));
    }

    public function testHttpsDetectionUnaffected(): void
    {
        $this->assertTrue(RequestContext::isHttps(['HTTPS' => 'on']));
        $this->assertTrue(RequestContext::isHttps(['HTTP_X_FORWARDED_PROTO' => 'https']));
        $this->assertFalse(RequestContext::isHttps([]));
    }
}
