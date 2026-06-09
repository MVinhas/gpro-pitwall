<?php

declare(strict_types=1);

namespace App\Tests\Unit\Database;

use App\Database\Database;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Database::class)]
final class DatabaseTest extends TestCase
{
    private ?string $originalDbFile;

    protected function setUp(): void
    {
        $this->originalDbFile = $_ENV['DB_FILE'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->originalDbFile === null) {
            unset($_ENV['DB_FILE']);
        } else {
            $_ENV['DB_FILE'] = $this->originalDbFile;
        }
    }

    public function testPathIsAbsoluteSoItResolvesRegardlessOfCwd(): void
    {
        $_ENV['DB_FILE'] = 'gpro_pilots.sqlite';

        $path = Database::path();

        // Absolute path — the Debug page's filesize() must not depend on the
        // process CWD (which is the docroot under Apache/CGI, not the project root).
        $this->assertStringStartsWith('/', $path);
        $this->assertStringEndsWith('/gpro_pilots.sqlite', $path);
    }

    public function testPathHonoursDbFileOverride(): void
    {
        $_ENV['DB_FILE'] = 'custom_name.sqlite';

        $this->assertStringEndsWith('/custom_name.sqlite', Database::path());
    }
}
