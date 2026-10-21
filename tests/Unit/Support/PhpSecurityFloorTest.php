<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\PhpSecurityFloor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpSecurityFloor::class)]
final class PhpSecurityFloorTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function versions(): iterable
    {
        yield 'below the floor' => ['8.5.11', false];
        yield 'exactly the floor' => ['8.5.12', true];
        yield 'later patch' => ['8.5.13', true];
        yield 'next minor' => ['8.6.0', true];
        yield 'older minor, higher patch' => ['8.4.99', false];
        yield 'distro suffix at the floor' => ['8.5.12-1+deb13u1', true];
        yield 'distro suffix below the floor' => ['8.5.11-1+deb13u1', false];
        yield 'dev build of the floor' => ['8.5.12-dev', true];
    }

    #[DataProvider('versions')]
    public function testComparesOnlyTheNumericTriple(string $running, bool $expected): void
    {
        $floor = new PhpSecurityFloor('8.5.12');

        $this->assertSame($expected, $floor->isMet($running));
    }

    public function testEmptyFloorFallsBackToTheDefault(): void
    {
        $this->assertSame(PhpSecurityFloor::DEFAULT, (new PhpSecurityFloor(''))->minimum());
    }

    public function testDefaultsToTheRunningInterpreter(): void
    {
        $floor = new PhpSecurityFloor('1.0.0');

        $this->assertTrue($floor->isMet());
    }
}
