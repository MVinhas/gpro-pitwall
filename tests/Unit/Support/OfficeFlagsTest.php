<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\OfficeFlags;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(OfficeFlags::class)]
final class OfficeFlagsTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function values(): iterable
    {
        yield 'string one' => ['1', true];
        yield 'int one' => [1, true];
        yield 'bool true' => [true, true];
        yield 'string true' => ['true', true];
        yield 'string yes' => ['yes', true];
        yield 'string on' => ['on', true];
        yield 'lap count two' => ['2', true];
        yield 'lap count 34' => ['34', true];
        yield 'lap count 100' => ['100', true];
        yield 'int lap count' => [100, true];
        yield 'string no' => ['no', false];
        yield 'string zero' => ['0', false];
        yield 'int zero' => [0, false];
        yield 'empty string' => ['', false];
        yield 'null' => [null, false];
    }

    #[DataProvider('values')]
    public function testReadsTheFlag(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, OfficeFlags::done(['doneQ2' => $value], 'doneQ2'));
    }

    public function testMissingKeyIsNotDone(): void
    {
        $this->assertFalse(OfficeFlags::done(['doneTesting' => '1'], 'doneQ2'));
    }
}
