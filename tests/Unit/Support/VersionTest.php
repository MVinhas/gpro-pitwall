<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\Version;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Version::class)]
final class VersionTest extends TestCase
{
    protected function setUp(): void
    {
        Version::reset();
    }

    protected function tearDown(): void
    {
        Version::reset();
    }

    public function testReadsVersionFromComposerJson(): void
    {
        $path = $this->writeComposer('{"version": "9.8.7"}');
        self::assertSame('9.8.7', Version::current($path));
    }

    public function testFallsBackWhenFileMissing(): void
    {
        self::assertSame('0.0.0', Version::current('/no/such/composer.json'));
    }

    public function testFallsBackWhenVersionFieldAbsent(): void
    {
        $path = $this->writeComposer('{"name": "x/y"}');
        self::assertSame('0.0.0', Version::current($path));
    }

    public function testMemoisesFirstReadValue(): void
    {
        $path = $this->writeComposer('{"version": "1.0.0"}');
        self::assertSame('1.0.0', Version::current($path));

        // Same path rewritten — cached value must persist until reset().
        file_put_contents($path, '{"version": "2.0.0"}');
        self::assertSame('1.0.0', Version::current($path));

        Version::reset();
        self::assertSame('2.0.0', Version::current($path));
    }

    public function testTrackedComposerJsonExposesAValidSemver(): void
    {
        $version = Version::current(__DIR__ . '/../../../composer.json');
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);
    }

    private function writeComposer(string $json): string
    {
        $path = tempnam(sys_get_temp_dir(), 'composer_') ?: throw new \RuntimeException('tempnam failed');
        file_put_contents($path, $json);
        return $path;
    }
}
