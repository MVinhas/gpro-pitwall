<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal .env loader. Populates $_ENV with KEY=VALUE pairs read from a
 * dotenv-style file. Pre-existing keys win, matching phpdotenv's
 * createImmutable() semantics — so deploy-time env vars override
 * committed defaults.
 *
 * Intentionally limited: no variable interpolation, no nested-file
 * inheritance, no exports. We don't use those features.
 */
final class Env
{
    public static function load(string $path): void
    {
        if (!is_readable($path)) {
            return;
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }

            $key = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1));

            if ($key === '' || !preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key)) {
                continue;
            }

            // Strip a single matched pair of surrounding quotes.
            $len = strlen($value);
            if ($len >= 2) {
                $first = $value[0];
                $last = $value[$len - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            // Immutable: existing values (set by the SAPI / shell) take
            // precedence over the file.
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
            }
        }
    }
}
