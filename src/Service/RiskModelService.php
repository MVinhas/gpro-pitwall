<?php

declare(strict_types=1);

namespace App\Service;

use App\Cache\CacheInterface;
use App\Engineer\RiskModel;
use App\Repository\RaceTelemetryRepository;
use Throwable;

/**
 * Fits the overtake/defend risk model from the corpus and caches the fitted
 * parameters. The key carries the corpus size, so a new race or a replay filed
 * for a stored one refits on the next call instead of waiting out the TTL; the
 * season is in it so a new season starts clean.
 *
 * @phpstan-import-type Cached from RiskModel
 */
final class RiskModelService
{
    private const int TTL = 604800;

    public function __construct(
        private readonly RaceTelemetryRepository $telemetry,
        private readonly CacheInterface $cache,
    ) {
    }

    public function model(int $season): RiskModel
    {
        try {
            $size = $this->telemetry->total() . '.' . $this->telemetry->replayCount();
            $key = 'engineer.risk.v1.' . $season . '.' . $size;
            $cached = $this->cache->get($key);
            if (is_array($cached) && isset($cached['races'], $cached['replay_races'], $cached['range'])) {
                /** @var Cached $cached */
                return RiskModel::fromArray($cached);
            }

            $model = RiskModel::fit($this->telemetry->riskRows());
            $this->cache->set($key, $model->toArray(), self::TTL);

            return $model;
        } catch (Throwable $e) {
            error_log('[Engineer] ' . $e::class);

            return RiskModel::fallback();
        }
    }
}
