<?php

declare(strict_types=1);

namespace App\Service;

use App\Cache\CacheInterface;
use App\Engineer\EnergyModel;
use App\Engineer\PlaceGap;
use App\Repository\RaceHistoryRepository;
use App\Repository\RaceTelemetryRepository;
use Throwable;

/**
 * Fits the driver-energy and place-gap models from the corpus and caches the
 * fitted parameters for a week, keyed per season so a new season refits.
 */
final class CtrModelService
{
    private const int TTL = 604800;

    public function __construct(
        private readonly RaceTelemetryRepository $telemetry,
        private readonly RaceHistoryRepository $history,
        private readonly CacheInterface $cache,
    ) {
    }

    public function energy(?string $division, int $season): EnergyModel
    {
        $key = 'engineer.energy.v1.' . $season . '.' . ($division ?? 'none');
        $cached = $this->cache->get($key);
        if (is_array($cached) && array_key_exists('drain', $cached) && isset($cached['r0'], $cached['quali_drain'])) {
            /** @var array{drain: array{a: float, b: float, s: float}|null, r0: float, r1: float, quali_drain: int} $cached */
            return EnergyModel::fromArray($cached);
        }

        try {
            $raceRows = $division !== null ? $this->telemetry->energyRows($division) : [];
            $model = EnergyModel::fit($raceRows, $this->history->qualifyingEnergyRows());
        } catch (Throwable $e) {
            error_log('[Engineer] ' . $e::class);

            return EnergyModel::fallback();
        }

        $this->cache->set($key, $model->toArray(), self::TTL);

        return $model;
    }

    public function placeGap(int $season): PlaceGap
    {
        $key = 'engineer.place_gap.v1.' . $season;
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            /** @var array<string, float> $cached */
            return PlaceGap::fromArray($cached);
        }

        try {
            $gap = PlaceGap::fromLapGaps($this->telemetry->lapGapsByDivision());
        } catch (Throwable $e) {
            error_log('[Engineer] ' . $e::class);

            return PlaceGap::fallback();
        }

        $this->cache->set($key, $gap->toArray(), self::TTL);

        return $gap;
    }
}
