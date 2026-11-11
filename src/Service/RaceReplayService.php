<?php

declare(strict_types=1);

namespace App\Service;

use App\Archive\AnalysisReader;
use App\Repository\RaceHistoryRepository;
use App\Repository\RaceTelemetryRepository;
use App\Telemetry\RaceReplayMapper;
use App\Telemetry\RaceTelemetryMapper;
use Throwable;

/**
 * Files what a RaceReplay says about a race the manager already has stored:
 * the manager's own history row and the matching row of the shared corpus.
 * The replay payload itself is dropped here; it names every manager in the group.
 *
 * @phpstan-import-type ReplayFacts from \App\Telemetry\RaceReplayMapper
 */
final class RaceReplayService
{
    public function __construct(
        private readonly RaceHistoryRepository $history,
        private readonly RaceTelemetryRepository $telemetry,
        private readonly RaceTelemetryMapper $telemetryMapper,
        private readonly RaceReplayMapper $mapper,
        private readonly AnalysisReader $analyses,
    ) {
    }

    /** The group a stored race was run in, which the replay has to be asked for by name. */
    public function groupFor(int $userId, int $season, int $race): ?string
    {
        $group = $this->analyses->forRace($userId, $season, $race)['group'] ?? null;

        return is_string($group) && trim($group) !== '' ? trim($group) : null;
    }

    /**
     * An answer that cannot be used (no access, or our car cannot be found in
     * it) still marks the race as read, so it is not asked for again.
     *
     * @param array<string, mixed> $replay
     *
     * @return bool whether the race-time facts were stored
     */
    public function ingest(int $userId, int $season, int $race, array $replay): bool
    {
        $analysis = $this->analyses->forRace($userId, $season, $race);
        $facts = null;
        if ($analysis !== [] && (int) ($replay['hasAccess'] ?? 0) === 1) {
            $laps = $analysis['laps'] ?? null;
            $laps = is_array($laps) ? array_values(array_filter($laps, 'is_array')) : [];
            $facts = $this->mapper->map($replay, $laps);
        }

        $this->history->setReplay($userId, $season, $race, $facts);
        if ($analysis !== []) {
            $this->fileInCorpus($analysis, $facts);
        }

        return $facts !== null;
    }

    /**
     * @param array<string, mixed> $analysis
     * @param ReplayFacts|null $facts
     */
    private function fileInCorpus(array $analysis, ?array $facts): void
    {
        try {
            $key = $this->telemetryMapper->map($analysis);
            if ($key !== null) {
                $this->telemetry->setReplay($key, $facts);
            }
        } catch (Throwable $e) {
            error_log('[RaceReplay] corpus update failed: ' . $e::class);
        }
    }
}
