<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Race-weekend settings that more than one screen asks for. Clear Track Risk
 * is one number in the game, so the Cockpit's wear forecast and the Strategy
 * calculation read and write the same remembered value instead of each
 * holding its own.
 */
final class RaceSettings
{
    public const string CTR = 'ctr';
    public const string TRAINING_LAPS = 'training_laps';

    private const string KEY = 'race_settings';

    /**
     * An explicit request value wins and is remembered; without one the
     * remembered value is used, or 0 when nothing was chosen yet.
     *
     * @param array<mixed>|null $session null outside a web request (no session started)
     * @param-out array<mixed> $session
     */
    public static function resolve(?array &$session, string $name, mixed $requested, int $max): int
    {
        $session ??= [];
        if ($requested !== null && $requested !== '') {
            $value = self::clamp((int) $requested, $max);
            $store = is_array($session[self::KEY] ?? null) ? $session[self::KEY] : [];
            $store[$name] = $value;
            $session[self::KEY] = $store;

            return $value;
        }

        return self::clamp(self::remembered($session, $name) ?? 0, $max);
    }

    /** @param array<mixed>|null $session */
    public static function remembered(?array $session, string $name): ?int
    {
        $store = $session[self::KEY] ?? null;
        if (!is_array($store) || !isset($store[$name]) || !is_int($store[$name])) {
            return null;
        }

        return $store[$name];
    }

    private static function clamp(int $value, int $max): int
    {
        return max(0, min($max, $value));
    }
}
