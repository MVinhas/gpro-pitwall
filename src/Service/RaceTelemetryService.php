<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\RaceTelemetryRepository;
use App\Telemetry\RaceTelemetryMapper;
use Throwable;

/**
 * Ingests one manager's most recent race into the anonymous corpus.
 *
 * Called from the sync, which has already fetched (and cached) both payloads
 * this needs, so ingestion costs one extra API call per user per race window
 * for RaceAnalysis and nothing at all for the TD profile.
 *
 * The caller passes no user id and this class asks for none: whatever reaches
 * the repository is race data only.
 */
final readonly class RaceTelemetryService
{
    public function __construct(
        private RaceTelemetryRepository $repository,
        private RaceTelemetryMapper $mapper,
    ) {
    }

    /**
     * @param array<string, mixed> $analysis RaceAnalysis payload
     * @param array<string, mixed> $td       TDProfile payload ([] when none)
     * @return bool true when a previously unseen race was stored
     */
    public function ingest(array $analysis, array $td = []): bool
    {
        if ($analysis === []) {
            return false;
        }

        try {
            $row = $this->mapper->map($analysis, $td);
            if ($row === null) {
                return false;
            }

            return $this->repository->insertIfNew($row);
        } catch (Throwable $e) {
            // Telemetry is observational. A malformed payload must never
            // surface to the manager whose sync happened to carry it.
            error_log('[RaceTelemetry] ingest failed: ' . $e::class . ': ' . $e->getMessage());
            return false;
        }
    }
}
