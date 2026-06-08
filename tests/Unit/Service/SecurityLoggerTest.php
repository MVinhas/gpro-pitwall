<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\SecurityLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SecurityLogger::class)]
final class SecurityLoggerTest extends TestCase
{
    /** @var list<string> */
    private array $sink;
    private SecurityLogger $logger;

    protected function setUp(): void
    {
        $this->sink = [];
        $this->logger = new SecurityLogger(function (string $line): void {
            $this->sink[] = $line;
        });
    }

    public function testEventIsPrefixedAndCarriesAction(): void
    {
        $this->logger->event('login_failed', ['username' => 'alice']);

        $this->assertCount(1, $this->sink);
        $this->assertStringContainsString('[security]', $this->sink[0]);
        $this->assertStringContainsString('login_failed', $this->sink[0]);
        $this->assertStringContainsString('username=alice', $this->sink[0]);
    }

    public function testContextIsRenderedAsKeyValuePairs(): void
    {
        $this->logger->event('token_theft_detected', ['selector' => 'abc123', 'user_id' => 7]);

        $this->assertStringContainsString('token_theft_detected', $this->sink[0]);
        $this->assertStringContainsString('selector=abc123', $this->sink[0]);
        $this->assertStringContainsString('user_id=7', $this->sink[0]);
    }

    public function testNullAndScalarContextValuesAreSafe(): void
    {
        $this->logger->event('login_ok', ['user_id' => 0, 'note' => null]);

        $this->assertStringContainsString('login_ok', $this->sink[0]);
        $this->assertStringContainsString('user_id=0', $this->sink[0]);
        $this->assertStringContainsString('note=', $this->sink[0]);
    }
}
