<?php

declare(strict_types=1);

namespace App\Tests\Unit\Archive;

use App\Archive\ArchiveSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArchiveSanitizer::class)]
final class ArchiveSanitizerTest extends TestCase
{
    public function testDropsTopLevelPii(): void
    {
        $sanitizer = new ArchiveSanitizer();

        $this->assertSame(
            ['cash' => 5],
            $sanitizer->sanitize(['email' => 'a@b', 'cash' => 5]),
        );
    }

    public function testDropsNestedPii(): void
    {
        $sanitizer = new ArchiveSanitizer();

        $this->assertSame(
            ['managers' => [['name' => 'X']]],
            $sanitizer->sanitize(['managers' => [['name' => 'X', 'email' => 'y']]]),
        );
    }

    public function testKeepsGameData(): void
    {
        $sanitizer = new ArchiveSanitizer();
        $payload = [
            'standings' => [
                ['name' => 'Team Alpha', 'IDM' => 42, 'pts' => 17],
                ['name' => 'Team Beta', 'IDM' => 7, 'pts' => 3],
            ],
        ];

        $this->assertSame($payload, $sanitizer->sanitize($payload));
    }
}
