<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * A GPRO error message can quote payload content (manager names), so a
 * controller logs the exception class and never its message. Most controllers
 * have no test seam around their catch blocks; this reads the source instead.
 */
#[CoversNothing]
final class ControllerLogHygieneTest extends TestCase
{
    /** Database and cache checks: no GPRO payload in those messages (A3). */
    private const array MAY_LOG_MESSAGES = ['HealthController.php'];

    public function testNoControllerLogsAnExceptionMessage(): void
    {
        $files = glob(dirname(__DIR__, 3) . '/src/Controller/*.php');
        $this->assertNotEmpty($files);

        $offenders = [];
        foreach ($files as $file) {
            if (in_array(basename($file), self::MAY_LOG_MESSAGES, true)) {
                continue;
            }
            foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $index => $line) {
                if (str_contains($line, 'error_log(') && str_contains($line, 'getMessage()')) {
                    $offenders[] = basename($file) . ':' . ($index + 1);
                }
            }
        }

        $this->assertSame([], $offenders);
    }
}
