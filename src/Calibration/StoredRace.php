<?php

declare(strict_types=1);

namespace App\Calibration;

use JsonException;

final readonly class StoredRace
{
    /**
     * @return array<mixed>|null
     */
    public static function decode(mixed $json): ?array
    {
        if (!is_string($json)) {
            return null;
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * Pre-race value, falling back to post when the pre snapshot is missing.
     *
     * @param array<mixed> $driver
     */
    public static function attribute(array $driver, string $key): ?int
    {
        $attributes = $driver['attributes'] ?? null;
        if (!is_array($attributes)) {
            return null;
        }
        foreach ($attributes as $attribute) {
            if (is_array($attribute) && ($attribute['key'] ?? null) === $key) {
                $value = $attribute['pre'] ?? $attribute['post'] ?? null;

                return is_numeric($value) ? (int) $value : null;
            }
        }

        return null;
    }

    /**
     * Level of a stored part by key, or null when absent.
     *
     * @param array<mixed> $parts
     */
    public static function partLevel(array $parts, string $key): ?int
    {
        foreach ($parts as $part) {
            if (is_array($part) && ($part['key'] ?? null) === $key && ($part['level'] ?? null) !== null) {
                return (int) $part['level'];
            }
        }

        return null;
    }
}
