<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Single source of truth for the app version: the `version` field in
 * composer.json. The footer, the API User-Agent, and anything else that
 * shows a version all read it from here, so a release bump touches one
 * file and the three display sites can never drift apart again.
 */
final class Version
{
    private static ?string $cached = null;

    public static function current(string $composerJsonPath = __DIR__ . '/../../composer.json'): string
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $version = '0.0.0';
        $raw = @file_get_contents($composerJsonPath);
        if ($raw !== false) {
            $data = json_decode($raw, true);
            if (is_array($data) && isset($data['version']) && is_string($data['version'])) {
                $version = $data['version'];
            }
        }

        return self::$cached = $version;
    }

    /** Test-only: drop the memoised value so a different path can be read. */
    public static function reset(): void
    {
        self::$cached = null;
    }
}
