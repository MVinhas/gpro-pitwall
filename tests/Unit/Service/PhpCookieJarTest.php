<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\PhpCookieJar;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpCookieJar::class)]
final class PhpCookieJarTest extends TestCase
{
    private PhpCookieJar $jar;

    protected function setUp(): void
    {
        $this->jar = new PhpCookieJar();
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
    }

    public function testGetReturnsNullForAnAbsentCookie(): void
    {
        $this->assertNull($this->jar->get('remember'));
    }

    public function testGetReadsAStringCookie(): void
    {
        $_COOKIE['remember'] = 'selector:validator';

        $this->assertSame('selector:validator', $this->jar->get('remember'));
    }

    /**
     * A bracketed query string turns $_COOKIE entries into arrays; the jar
     * must hand callers null rather than an array they'd use as a token.
     */
    public function testAnArrayValuedCookieReadsAsNullRatherThanAnArray(): void
    {
        $_COOKIE['remember'] = ['not', 'a', 'token'];

        $this->assertNull($this->jar->get('remember'));
    }

    public function testAnEmptyCookieIsStillAString(): void
    {
        $_COOKIE['remember'] = '';

        $this->assertSame('', $this->jar->get('remember'));
    }

    #[RunInSeparateProcess]
    public function testSetMakesTheValueImmediatelyReadableWithinTheSameRequest(): void
    {
        $jar = new PhpCookieJar();

        $jar->set('remember', 'sel:val', ['expires' => time() + 3600, 'path' => '/']);

        $this->assertSame('sel:val', $jar->get('remember'));
    }

    #[RunInSeparateProcess]
    public function testSetOverwritesAnExistingValue(): void
    {
        $jar = new PhpCookieJar();
        $_COOKIE['remember'] = 'old';

        $jar->set('remember', 'new', ['path' => '/']);

        $this->assertSame('new', $jar->get('remember'));
    }

    #[RunInSeparateProcess]
    public function testClearMakesTheCookieUnreadableWithinTheSameRequest(): void
    {
        $jar = new PhpCookieJar();
        $jar->set('remember', 'sel:val', ['path' => '/']);

        $jar->clear('remember', '/');

        $this->assertNull($jar->get('remember'));
    }

    #[RunInSeparateProcess]
    public function testClearingAnAbsentCookieIsHarmless(): void
    {
        $jar = new PhpCookieJar();

        $jar->clear('never-set', '/');

        $this->assertNull($jar->get('never-set'));
    }

    #[RunInSeparateProcess]
    public function testCookiesAreIsolatedByName(): void
    {
        $jar = new PhpCookieJar();
        $jar->set('a', 'value-a', ['path' => '/']);
        $jar->set('b', 'value-b', ['path' => '/']);

        $jar->clear('a', '/');

        $this->assertNull($jar->get('a'));
        $this->assertSame('value-b', $jar->get('b'));
    }
}
