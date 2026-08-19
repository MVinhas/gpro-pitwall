<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\RaceTelemetryRepository;

/**
 * Shapes the anonymous corpus into the admin intelligence report.
 *
 * Every figure here is an aggregate over many races. The service refuses to
 * present a slice thinner than MIN_SAMPLE so a two-race fluke never renders
 * as a finding, and it always keeps GPRO level as the outer dimension.
 */
final readonly class RaceIntelligenceService
{
    /** Below this many races a slice is noise, not intelligence. */
    public const int MIN_SAMPLE = 3;

    /** A slice needs at least this many races before r is worth reading. */
    public const int MIN_CORRELATION_SAMPLE = 8;

    /** Canonical level order, strongest league last. */
    private const array LEVEL_ORDER = ['Rookie', 'Amateur', 'Pro', 'Master', 'Elite'];

    public function __construct(
        private RaceTelemetryRepository $repository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $levels = $this->sortByLevel($this->repository->levelSummary());

        return [
            'total'          => $this->repository->total(),
            'levels'         => $levels,
            'has_data'       => $levels !== [],
            'min_sample'     => self::MIN_SAMPLE,
            'min_corr'       => self::MIN_CORRELATION_SAMPLE,
            'attributes'     => $this->attributeReport(),
            'tyres'          => $this->groupByLevel($this->repository->tyrePerformance(self::MIN_SAMPLE)),
            'td'             => $this->technicalDirector(),
            'risks'          => $this->riskReport(),
            'strategy'       => $this->groupByLevel($this->repository->strategyPerformance(self::MIN_SAMPLE)),
            'mistakes'       => $this->groupByLevel($this->repository->mistakeImpact(self::MIN_SAMPLE)),
            'prototype'      => $this->prototype(),
        ];
    }

    /**
     * Driver attribute → finishing position, per level.
     *
     * The stored r is against final_pos, where lower is better, so the sign is
     * flipped into an "advantage" reading: positive means more of the attribute
     * goes with a better finish. That flip happens once, here, so no template
     * or reader has to remember it.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function attributeReport(): array
    {
        $rows = $this->repository->driverAttributeCorrelations('final_pos', self::MIN_CORRELATION_SAMPLE);

        $shaped = [];
        foreach ($rows as $row) {
            $r = $row['r'];
            if ($r === null) {
                continue;
            }

            $advantage = -1.0 * (float) $r;
            $row['advantage'] = round($advantage, 3);
            $row['strength'] = $this->strengthOf($advantage);
            $shaped[] = $row;
        }

        $byLevel = $this->groupByLevel($shaped);

        // Strongest relationship first within each level.
        foreach ($byLevel as $level => $items) {
            usort($items, static fn (array $a, array $b): int
                => abs((float) $b['advantage']) <=> abs((float) $a['advantage']));
            $byLevel[$level] = $items;
        }

        return $byLevel;
    }

    /**
     * With/without TD per level, collapsed into one comparable row so the
     * template does not have to pair the two arms itself. A level that has
     * only one arm yields no verdict — there is nothing to compare it to.
     *
     * @return list<array<string, mixed>>
     */
    private function technicalDirector(): array
    {
        $byLevel = $this->groupByLevel($this->repository->technicalDirectorEffect(self::MIN_SAMPLE));

        $out = [];
        foreach ($byLevel as $level => $rows) {
            $with = null;
            $without = null;
            foreach ($rows as $row) {
                if ((int) $row['has_td'] === 1) {
                    $with = $row;
                } else {
                    $without = $row;
                }
            }

            $comparable = $with !== null && $without !== null;

            $out[] = [
                'level'      => $level,
                'with'       => $with,
                'without'    => $without,
                'comparable' => $comparable,
                'pos_delta'  => $comparable
                    ? round((float) $without['avg_pos'] - (float) $with['avg_pos'], 2)
                    : null,
                'points_delta' => $comparable
                    ? round((float) $with['avg_points'] - (float) $without['avg_points'], 2)
                    : null,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, array<string, list<array<string, mixed>>>>
     */
    private function riskReport(): array
    {
        $dimensions = [
            'q1_risk'        => 'Q1 risk',
            'q2_risk'        => 'Q2 risk',
            'start_risk'     => 'Start risk',
            'overtake_risk'  => 'Overtake risk',
            'defend_risk'    => 'Defend risk',
            'clear_dry_risk' => 'Clear track (dry)',
            'clear_wet_risk' => 'Clear track (wet)',
        ];

        $out = [];
        foreach ($dimensions as $column => $label) {
            $rows = $this->repository->riskPerformance($column, self::MIN_SAMPLE);
            if ($rows === []) {
                continue;
            }

            $out[$label] = $this->groupByLevel($rows);
        }

        return $out;
    }

    /**
     * Podium-vs-rest attribute means per level, with the gap precomputed.
     *
     * @return list<array<string, mixed>>
     */
    private function prototype(): array
    {
        $byLevel = $this->groupByLevel($this->repository->winningDriverPrototype(self::MIN_SAMPLE));

        $out = [];
        foreach ($byLevel as $level => $rows) {
            $podium = null;
            $rest = null;
            foreach ($rows as $row) {
                if ($row['band'] === 'podium') {
                    $podium = $row;
                } else {
                    $rest = $row;
                }
            }

            if ($podium === null) {
                continue;
            }

            $attributes = [];
            foreach (RaceTelemetryRepository::DRIVER_ATTRIBUTES as $key => $label) {
                $podiumValue = $podium[$key] ?? null;
                if ($podiumValue === null) {
                    continue;
                }

                $restValue = $rest[$key] ?? null;
                $attributes[] = [
                    'label'  => $label,
                    'podium' => (float) $podiumValue,
                    'rest'   => $restValue === null ? null : (float) $restValue,
                    'gap'    => $restValue === null
                        ? null
                        : round((float) $podiumValue - (float) $restValue, 1),
                ];
            }

            $out[] = [
                'level'        => $level,
                'podium_n'     => (int) $podium['n'],
                'rest_n'       => $rest === null ? 0 : (int) $rest['n'],
                'comparable'   => $rest !== null,
                'attributes'   => $attributes,
            ];
        }

        return $out;
    }

    /**
     * Conventional reading of |r|. Deliberately conservative: a correlation
     * under 0.2 is reported as "none" rather than dressed up as a finding.
     */
    private function strengthOf(float $r): string
    {
        $abs = abs($r);

        return match (true) {
            $abs >= 0.6 => 'strong',
            $abs >= 0.4 => 'moderate',
            $abs >= 0.2 => 'weak',
            default     => 'none',
        };
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupByLevel(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $level = (string) ($row['level'] ?? '');
            if ($level === '') {
                continue;
            }
            $grouped[$level][] = $row;
        }

        return $this->orderLevels($grouped);
    }

    /**
     * @param array<string, list<array<string, mixed>>> $grouped
     * @return array<string, list<array<string, mixed>>>
     */
    private function orderLevels(array $grouped): array
    {
        $ordered = [];
        foreach (self::LEVEL_ORDER as $level) {
            if (isset($grouped[$level])) {
                $ordered[$level] = $grouped[$level];
            }
        }

        // Anything GPRO adds later still shows, just after the known ladder.
        foreach ($grouped as $level => $rows) {
            if (!isset($ordered[$level])) {
                $ordered[$level] = $rows;
            }
        }

        return $ordered;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function sortByLevel(array $rows): array
    {
        usort($rows, static function (array $a, array $b): int {
            $ai = array_search($a['level'] ?? '', self::LEVEL_ORDER, true);
            $bi = array_search($b['level'] ?? '', self::LEVEL_ORDER, true);
            return ($ai === false ? PHP_INT_MAX : $ai) <=> ($bi === false ? PHP_INT_MAX : $bi);
        });

        return $rows;
    }
}
