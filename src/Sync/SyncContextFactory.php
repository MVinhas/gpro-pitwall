<?php

declare(strict_types=1);

namespace App\Sync;

use App\Repository\ApiArchiveRepository;
use App\Service\GproApiClient;
use Throwable;

/**
 * Builds the planner's view of what a user already has, from the archive only.
 */
final class SyncContextFactory
{
    public function __construct(
        private readonly GproApiClient $apiClient,
        private readonly ApiArchiveRepository $archive,
    ) {
    }

    /**
     * The office the last SYNC planned against: page renders archive offices
     * too and would swallow done-flag flips. The plain row is only the
     * fallback for the first sync after the 'sync' rows were introduced.
     *
     * @return array<string, mixed>|null
     */
    public function previousOffice(int $userId, string $scope): ?array
    {
        return $this->safely(
            fn (): ?array => $this->archive->latest($userId, 'office', 'sync', $scope)
                ?? $this->archive->latest($userId, 'office', '', $scope),
            null,
        );
    }

    /**
     * @param array<string, mixed>|null $previousOffice
     * @param array<string, mixed> $office
     * @param list<string> $backfillPending 'season,race' pairs still missing an analysis
     */
    public function build(
        int $userId,
        string $scope,
        ?array $previousOffice,
        array $office,
        int $remaining,
        string $mode,
        array $backfillPending = [],
        int $backfillDoneThisWindow = 0,
    ): SyncContext {
        $season = self::intOrNull($office['seasonNb'] ?? null);
        $race = self::intOrNull($office['raceNb'] ?? null);

        $thisRace = [];
        $thisSeason = [];
        if ($season !== null) {
            $thisSeason = ArchiveEndpoints::toPlanner(
                $this->safely(fn (): array => $this->archive->endpointsForSeason($userId, $season, $scope), []),
            );
        }
        if ($season !== null && $race !== null) {
            $thisRace = array_values(array_diff(
                ArchiveEndpoints::toPlanner(
                    $this->safely(
                        fn (): array => $this->archive->endpointsForRace($userId, $season, $race, $scope),
                        [],
                    ),
                ),
                [Endpoint::ANALYSIS],
            ));
            if ($this->analysisArchivedForPreviousRace($userId, $scope, $season, $race)) {
                $thisRace[] = Endpoint::ANALYSIS;
            }
        }

        return new SyncContext(
            $previousOffice,
            $office,
            $remaining,
            $mode,
            $thisRace,
            $thisSeason,
            $backfillPending,
            $backfillDoneThisWindow,
        );
    }

    /**
     * RaceAnalysis is stamped with the race it analyses, which is the one just
     * run: the race before the office's (the office names the NEXT race).
     */
    private function analysisArchivedForPreviousRace(int $userId, string $scope, int $season, int $race): bool
    {
        if ($this->analysisAskedAfterPreviousRaceEnded($userId, $scope)) {
            return true;
        }

        $latest = $this->safely(
            fn (): ?array => $this->archive->latest($userId, 'RaceAnalysis', '', $scope),
            null,
        );
        $analysedSeason = self::intOrNull($latest['selSeasonNb'] ?? null);
        if ($analysedSeason === null) {
            return false;
        }
        if ($race > 1) {
            return $analysedSeason === $season && self::intOrNull($latest['selRaceNb'] ?? null) === $race - 1;
        }

        return $analysedSeason === $season - 1;
    }

    /**
     * Whatever GPRO answered once the race was over (an analysis, a NonSup or
     * NA payload, or an error) is as good as it gets until the next race, so
     * asking again would only spend a call: a manager who skipped the race, or
     * a non-supporter, would otherwise pay one on every login.
     */
    private function analysisAskedAfterPreviousRaceEnded(int $userId, string $scope): bool
    {
        $threshold = $this->apiClient->postRaceAt();
        if ($threshold === null) {
            return false;
        }
        if ($this->apiClient->hasAnalysisAttempt()) {
            return true;
        }
        $fetchedAt = $this->safely(
            fn (): ?string => $this->archive->latestFetchedAt($userId, 'RaceAnalysis', '', $scope),
            null,
        );

        return $fetchedAt !== null && (int) strtotime($fetchedAt . ' UTC') >= $threshold->getTimestamp();
    }

    /**
     * @template T
     *
     * @param callable(): T $read
     * @param T $default
     *
     * @return T
     */
    private function safely(callable $read, mixed $default): mixed
    {
        try {
            return $read();
        } catch (Throwable $e) {
            error_log('[GproSync] read failed: ' . $e::class);

            return $default;
        }
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
