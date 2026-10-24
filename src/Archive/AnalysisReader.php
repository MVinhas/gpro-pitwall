<?php

declare(strict_types=1);

namespace App\Archive;

use App\Repository\ApiArchiveRepository;

/**
 * The RaceAnalysis that describes one race: the latest row stamped with it,
 * else the backfilled `SR=S,R` row. A NonSup/NA answer reads as no analysis.
 */
final class AnalysisReader
{
    public function __construct(private readonly ApiArchiveRepository $archive)
    {
    }

    /**
     * @return array<string, mixed> empty when the race has no usable analysis
     */
    public function forRace(int $userId, int $season, int $race): array
    {
        foreach (['', "SR={$season},{$race}"] as $params) {
            $payload = $this->archive->latestStamped($userId, 'RaceAnalysis', $season, $race, $params);
            if ($payload !== null && !SupporterGate::isUnavailable('RaceAnalysis', $payload)) {
                return $payload;
            }
        }

        return [];
    }
}
