<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\Env;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Env::class)]
final class EnvTest extends TestCase
{
    private const KEY = 'ENV_TEST_KEY';

    private bool $hadKey = false;

    /** @var scalar|null */
    private mixed $originalValue = null;

    protected function setUp(): void
    {
        $this->hadKey = array_key_exists(self::KEY, $_ENV);
        $this->originalValue = $this->hadKey ? $_ENV[self::KEY] : null;
        unset($_ENV[self::KEY]);
    }

    protected function tearDown(): void
    {
        if ($this->hadKey) {
            $_ENV[self::KEY] = $this->originalValue;
        } else {
            unset($_ENV[self::KEY]);
        }
    }

    public function testGetReturnsValueWhenPresent(): void
    {
        $_ENV[self::KEY] = 'hello';
        self::assertSame('hello', Env::get(self::KEY));
    }

    public function testGetReturnsDefaultWhenMissing(): void
    {
        self::assertSame('fallback', Env::get(self::KEY, 'fallback'));
    }

    public function testGetReturnsNullWhenMissingAndNoDefault(): void
    {
        self::assertNull(Env::get(self::KEY));
    }

    public function testGetCastsNonStringScalarValueDefensively(): void
    {
        $_ENV[self::KEY] = 42;
        self::assertSame('42', Env::get(self::KEY));
    }

    public function testIntCastsStringValue(): void
    {
        $_ENV[self::KEY] = '123';
        self::assertSame(123, Env::int(self::KEY, 0));
    }

    public function testIntReturnsDefaultWhenMissing(): void
    {
        self::assertSame(99, Env::int(self::KEY, 99));
    }

    public function testFloatCastsStringValue(): void
    {
        $_ENV[self::KEY] = '1.5';
        self::assertSame(1.5, Env::float(self::KEY, 0.0));
    }

    public function testFloatReturnsDefaultWhenMissing(): void
    {
        self::assertSame(2.5, Env::float(self::KEY, 2.5));
    }

    public function testBoolReturnsDefaultWhenMissing(): void
    {
        self::assertFalse(Env::bool(self::KEY));
        self::assertTrue(Env::bool(self::KEY, true));
    }

    /**
     * @return list<array{string, bool}>
     */
    public static function boolParsingProvider(): array
    {
        return [
            ['true', true],
            ['1', true],
            ['yes', true],
            ['on', true],
            ['false', false],
            ['0', false],
            ['no', false],
            ['off', false],
        ];
    }

    #[DataProvider('boolParsingProvider')]
    public function testBoolParsesRecognisedValues(string $raw, bool $expected): void
    {
        $_ENV[self::KEY] = $raw;
        self::assertSame($expected, Env::bool(self::KEY));
    }

    public function testBoolFallsBackToDefaultOnGarbageValue(): void
    {
        $_ENV[self::KEY] = 'not-a-bool';
        self::assertTrue(Env::bool(self::KEY, true));
        self::assertFalse(Env::bool(self::KEY, false));
    }

    public function testRequiredReturnsValueWhenPresent(): void
    {
        $_ENV[self::KEY] = 'secret';
        self::assertSame('secret', Env::required(self::KEY));
    }

    public function testRequiredThrowsWhenMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required env var: ' . self::KEY);
        Env::required(self::KEY);
    }

    public function testRequiredThrowsWhenEmptyString(): void
    {
        $_ENV[self::KEY] = '';
        $this->expectException(\RuntimeException::class);
        Env::required(self::KEY);
    }
}
