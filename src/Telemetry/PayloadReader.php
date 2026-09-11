<?php

declare(strict_types=1);

namespace App\Telemetry;

/**
 * Scalar coercion for GPRO payloads, which type numbers inconsistently — the
 * same field arrives as int in one response and as a numeric string in
 * another. Every reader here returns null rather than a zero-ish default, so a
 * missing value stays distinguishable from a real 0.
 */
trait PayloadReader
{
    /** @return array<string, mixed> */
    private function arrayOf(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @return list<array<string, mixed>> */
    private function listOf(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $out[] = $item;
            }
        }

        return $out;
    }

    private function int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (int) trim($value);
        }
        if (is_float($value)) {
            return (int) $value;
        }

        return null;
    }

    private function float(mixed $value): ?float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return null;
    }

    private function str(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed === '' ? null : $trimmed;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * "1:59.502s" / "1:59.502" / "20.558" -> milliseconds. A stable parse
     * matters more than precision: this feeds the telemetry natural key.
     */
    private function lapTimeMs(?string $time): ?int
    {
        if ($time === null) {
            return null;
        }

        $clean = trim(rtrim(trim($time), 's'));
        if ($clean === '' || $clean === '-') {
            return null;
        }

        if (preg_match('/^(\d+):(\d+)\.(\d+)$/', $clean, $m) === 1) {
            return ((int) $m[1]) * 60000
                + ((int) $m[2]) * 1000
                + (int) str_pad(substr($m[3], 0, 3), 3, '0');
        }

        if (preg_match('/^(\d+)\.(\d+)$/', $clean, $m) === 1) {
            return ((int) $m[1]) * 1000 + (int) str_pad(substr($m[2], 0, 3), 3, '0');
        }

        return null;
    }
}
