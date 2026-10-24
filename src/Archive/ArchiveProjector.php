<?php

declare(strict_types=1);

namespace App\Archive;

use App\Repository\ProjectionStateRepository;
use App\Service\RaceHistoryService;
use PDO;
use Throwable;

/**
 * Rebuilds the typed per-race tables from the raw archive, never from the API.
 *
 * (season, race) means "entering race R": the weekend state and standings come
 * from the snapshot rows stamped with R, the race history from R's own
 * analysis together with the TD, staff and driver rows stamped R — what
 * actually raced R. Bump PROJECTOR_VERSION when a projection's shape or rules
 * change, and every projected race becomes pending again.
 */
final class ArchiveProjector
{
    public const int PROJECTOR_VERSION = 1;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ProjectionStateRepository $state,
        private readonly WeekendStateProjector $weekend,
        private readonly StandingsProjector $standings,
        private readonly LedgerProjector $ledger,
        private readonly RaceHistoryService $history,
        private readonly RaceSnapshotReader $snapshots,
        private readonly AnalysisReader $analyses,
    ) {
    }

    /**
     * Races (oldest first) whose projection is missing, stale or older than
     * the archive rows that feed it. Reads indexes only; decodes no payload.
     *
     * @return list<array{season: int, race: int}>
     */
    public function pendingRaces(int $userId, int $limit): array
    {
        return $this->state->pendingRaces($userId, self::PROJECTOR_VERSION, $limit);
    }

    /**
     * All four projections for one race in one transaction. A projection that
     * throws is skipped (class name logged) and the race is still marked
     * projected, so one malformed payload never stalls the queue.
     *
     * The transaction takes the write lock up front (BEGIN IMMEDIATE): under
     * WAL a deferred one reads a snapshot, and if another process commits
     * before its first write that write fails at once with a stale snapshot;
     * busy_timeout only makes the wait for an immediate lock retry.
     */
    public function projectRace(int $userId, int $season, int $race): void
    {
        $owns = !$this->pdo->inTransaction();
        if ($owns) {
            $this->pdo->exec('BEGIN IMMEDIATE');
        }

        try {
            $this->guarded('weekend', fn () => $this->weekend->project($userId, $season, $race));
            $this->guarded('standings', fn () => $this->standings->project($userId, $season, $race));
            $this->guarded('ledger', fn () => $this->ledger->project($userId, $season, $race));
            $this->guarded('history', fn () => $this->projectHistory($userId, $season, $race));
            $this->state->mark($userId, $season, $race, self::PROJECTOR_VERSION);
            if ($owns) {
                $this->pdo->exec('COMMIT');
            }
        } catch (Throwable $e) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK');
            }
            throw $e;
        }
    }

    private function projectHistory(int $userId, int $season, int $race): void
    {
        $analysis = $this->analyses->forRace($userId, $season, $race);
        if ($analysis === []) {
            return;
        }

        $snapshot = $this->snapshots->read($userId, $season, $race);

        $this->history->archive(
            $userId,
            $analysis,
            $snapshot->tdThatRaced(),
            $snapshot->staffThatRaced(),
            $snapshot->driverThatRaced($analysis),
        );
    }

    /**
     * @param callable(): void $projection
     */
    private function guarded(string $step, callable $projection): void
    {
        $this->pdo->exec('SAVEPOINT projection');
        try {
            $projection();
        } catch (Throwable $e) {
            $this->pdo->exec('ROLLBACK TO projection');
            error_log('[Projector] ' . $step . ' failed: ' . $e::class);
        } finally {
            $this->pdo->exec('RELEASE projection');
        }
    }
}
