<?php

declare(strict_types=1);

namespace App\Archive;

/**
 * Recognises GPRO's "not available to you" answers, which arrive as ordinary
 * 200 payloads: a non-supporter gets an errorMsg with empty data, and a
 * RaceAnalysis with nothing to analyse has no laps.
 */
final class SupporterGate
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function isUnavailable(string $endpoint, array $payload): bool
    {
        $error = $payload['errorMsg'] ?? null;
        if (is_string($error) && trim($error) !== '' && ($payload['data'] ?? null) === []) {
            return true;
        }

        return $endpoint === 'RaceAnalysis' && !is_array($payload['laps'] ?? null);
    }
}
