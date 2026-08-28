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

    public function testBooleanContextValuesAreStringified(): void
    {
        $this->logger->event('rate_limited', ['blocked' => true, 'retry' => false]);

        $this->assertStringContainsString('blocked=1', $this->sink[0]);
        $this->assertStringContainsString('retry=', $this->sink[0]);
    }

    public function testAnEventWithNoContextStillEmitsALine(): void
    {
        $this->logger->event('session_regenerated');

        $this->assertSame('[security] action=session_regenerated', $this->sink[0]);
    }

    public function testEachEventEmitsExactlyOneLine(): void
    {
        $this->logger->event('a');
        $this->logger->event('b');
        $this->logger->event('c');

        $this->assertCount(3, $this->sink);
    }

    /**
     * The production default routes through error_log. Point error_log at a
     * file so the default sink is exercised without a custom callable.
     */
    public function testTheDefaultSinkWritesThroughErrorLog(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pitwall-seclog-');
        $this->assertIsString($file);

        $previous = ini_set('error_log', $file);

        try {
            (new SecurityLogger())->event('login_failed', ['user_id' => 7]);
        } finally {
            if (is_string($previous)) {
                ini_set('error_log', $previous);
            }
        }

        $contents = (string) file_get_contents($file);
        unlink($file);

        $this->assertStringContainsString('[security] action=login_failed user_id=7', $contents);
    }
}
