<?php

declare(strict_types=1);

namespace App\Service;

use App\Cache\CacheInterface;
use App\Engineer\RiskCurve;
use App\Repository\RaceTelemetryRepository;
use Throwable;

/**
 * Fits the overtake/defend risk curve from the corpus and caches the fitted
 * curve. The key carries the corpus size, so a new race refits on the next call
 * instead of waiting out the TTL; the season is in it so a new season starts clean.
 *
 * @phpstan-import-type Cached from RiskCurve
 */
final class RiskCurveService
{
    private const int TTL = 604800;

    public function __construct(
        private readonly RaceTelemetryRepository $telemetry,
        private readonly CacheInterface $cache,
    ) {
    }

    public function curve(int $season): RiskCurve
    {
        try {
            $key = 'engineer.riskcurve.v1.' . $season . '.' . $this->telemetry->total();
            $cached = $this->cache->get($key);
            if (is_array($cached) && isset($cached['rows'], $cached['columns'], $cached['range'])) {
                /** @var Cached $cached */
                return RiskCurve::fromArray($cached);
            }

            $curve = RiskCurve::fit($this->telemetry->curveRows());
            $this->cache->set($key, $curve->toArray(), self::TTL);

            return $curve;
        } catch (Throwable $e) {
            error_log('[Engineer] ' . $e::class);

            return RiskCurve::fallback();
        }
    }
}
