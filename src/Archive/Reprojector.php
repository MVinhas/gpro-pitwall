<?php

declare(strict_types=1);

namespace App\Archive;

use App\Repository\ApiArchiveRepository;
use InvalidArgumentException;
use Throwable;

/**
 * Bulk rebuild of the projections from the archive, for the local CLI.
 * Production re-projects lazily through idle harvest slices instead.
 */
final class Reprojector
{
    private const int BATCH = 50;

    public function __construct(
        private readonly ArchiveProjector $projector,
        private readonly ApiArchiveRepository $archive,
    ) {
    }

    /**
     * @param list<string> $args CLI arguments after the script name
     */
    public static function parseUser(array $args): ?int
    {
        if ($args === []) {
            return null;
        }
        if (count($args) > 1 || preg_match('/^--user=([1-9][0-9]*)$/', $args[0], $m) !== 1) {
            throw new InvalidArgumentException('Expected [--user=N] with N a positive integer.');
        }

        return (int) $m[1];
    }

    /**
     * @return list<int>
     */
    public function userIds(?int $only): array
    {
        return $only !== null ? [$only] : $this->archive->userIds();
    }

    /**
     * Projects pending races in batches until none remain. A pass whose whole
     * batch was already attempted in this run (it failed, or stayed pending)
     * ends the loop, so a race that never clears can't spin forever.
     *
     * @return array{projected: int, stuck: int}
     */
    public function projectAllPending(int $userId): array
    {
        $projected = 0;
        $attempted = [];

        while (true) {
            $batch = $this->projector->pendingRaces($userId, self::BATCH);
            if ($batch === []) {
                return ['projected' => $projected, 'stuck' => 0];
            }

            $fresh = array_values(array_filter(
                $batch,
                static fn (array $r): bool => !isset($attempted[$r['season'] . ':' . $r['race']]),
            ));
            if ($fresh === []) {
                return ['projected' => $projected, 'stuck' => count($batch)];
            }

            foreach ($fresh as $r) {
                $attempted[$r['season'] . ':' . $r['race']] = true;
                try {
                    $this->projector->projectRace($userId, $r['season'], $r['race']);
                    $projected++;
                } catch (Throwable $e) {
                    error_log('[Reprojector] race failed: ' . $e::class);
                }
            }
        }
    }
}
