<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\RaceHistoryRepository;
use App\Repository\RaceTelemetryRepository;

/**
 * View-model builder for the three telemetry screens.
 *
 * The scope switch is the whole point of this class. "Mine" reads the manager's
 * own detailed archive; "everyone" reads the anonymous corpus and can only ever
 * return aggregates and identity-free rows. There is deliberately no third
 * option: no screen here can be pointed at another named manager, because no
 * table holds the mapping that would allow it.
 */
final readonly class DebriefService
{
    /** Below this, a slice is noise and is labelled as such rather than hidden. */
    public const int MIN_SAMPLE = 3;

    /** Divisions in ladder order, so every table reads the same way. */
    private const array LEVEL_ORDER = ['Rookie', 'Amateur', 'Pro', 'Master', 'Elite'];

    /** Qualifying risk choices, least to most aggressive. */
    private const array Q_RISK_ORDER = [
        'Keep the car on the track',
        'Push the car a little',
        'Push the car a lot',
        'Push the car to the limit',
    ];

    /** Race-start risk choices, least to most aggressive. */
    private const array START_RISK_ORDER = [
        'Avoid trouble',
        'Maintain his position',
        'Overtake where possible',
        'Force his way to the front',
    ];

    public function __construct(
        private RaceHistoryRepository $history,
        private RaceTelemetryRepository $corpus,
        private RaceIntelligenceService $intelligence,
    ) {
    }

    /**
     * Bird's Eye View: a filtered, ranked list of races plus the headline that
     * describes exactly that slice.
     *
     * @param array<string, int|string|null> $filters
     * @return array<string, mixed>
     */
    public function birdsEye(?int $userId, string $scope, array $filters, string $sort): array
    {
        $mine = $scope === 'mine' && $userId !== null;

        $rows = $mine
            ? $this->history->search($userId, $filters, $sort)
            : $this->corpus->browse($filters, $sort);

        $summary = $mine
            ? $this->history->summary($userId, $filters)
            : $this->corpus->browseSummary($filters);

        // Filter choices come from whichever dataset is on screen, so a filter
        // can never be offered that would return an empty table.
        $options = $mine
            ? $this->history->filterOptions($userId)
            : $this->corpus->filterOptions();

        return [
            'scope'         => $mine ? 'mine' : 'all',
            'rows'          => $rows,
            'summary'       => $summary,
            'options'       => $options,
            'filters'       => $filters,
            'sort'          => $sort,
            'has_rows'      => $rows !== [],
            'own_races'     => $userId === null ? 0 : $this->history->countFor($userId),
            'race_options'  => $userId === null ? [] : $this->history->raceOptions($userId),
            'active_filters' => $this->countActive($filters),
        ];
    }

    /**
     * One archived race in full. Only ever the caller's own — the repository
     * scopes by user id and there is no corpus equivalent to fall back to.
     *
     * @return array<string, mixed>|null
     */
    public function raceDetail(int $userId, ?int $season, ?int $race): ?array
    {
        if ($season === null || $race === null) {
            $latest = $this->history->latest($userId);
            if ($latest === null) {
                return null;
            }
            $season = $latest['season'];
            $race = $latest['race'];
        }

        return $this->history->find($userId, $season, $race);
    }

    /**
     * Track History: everything the corpus knows about one circuit, in the
     * shape GPRO's own track analysis uses, plus the manager's own races there.
     *
     * @return array<string, mixed>
     */
    public function trackHistory(int $trackId, ?int $userId): array
    {
        $totals = $this->orderLevels($this->corpus->trackLevelTotals($trackId), 'level');
        $races = 0;
        foreach ($totals as $row) {
            $races += (int) ($row['races'] ?? 0);
        }

        return [
            'track_id'   => $trackId,
            'track_name' => $this->trackNameFor($trackId),
            'totals'     => $totals,
            'total_races' => $races,
            'has_data'   => $totals !== [],
            'q1_risk'    => $this->distribution($trackId, 'q1_risk', self::Q_RISK_ORDER),
            'q2_risk'    => $this->distribution($trackId, 'q2_risk', self::Q_RISK_ORDER),
            'start_risk' => $this->distribution($trackId, 'start_risk', self::START_RISK_ORDER),
            'risk_avg'   => $this->orderLevels($this->corpus->trackRiskAverages($trackId), 'level'),
            'strategy'   => $this->stopDistribution($trackId),
            'weather'    => $this->corpus->trackWeatherBySeason($trackId),
            'tyres'      => $this->orderLevels($this->corpus->trackTyreUsage($trackId, self::MIN_SAMPLE), 'level'),
            'mine'       => $userId === null ? [] : $this->history->atTrack($userId, $trackId),
            'min_sample' => self::MIN_SAMPLE,
        ];
    }

    /**
     * Insights: what the corpus says wins, carried over from the admin-only
     * intelligence report and pointed at one track when asked.
     *
     * The driver-attribute correlations are the same computation the admin
     * screen has always run — reused, not reimplemented, so both screens can
     * never drift apart.
     *
     * @return array<string, mixed>
     */
    public function insights(?int $trackId): array
    {
        $report = $this->intelligence->report();

        $report['track_id'] = $trackId;
        $report['track_name'] = $trackId === null ? null : $this->trackNameFor($trackId);
        $report['tracks'] = $this->corpus->trackCoverage();

        if ($trackId !== null) {
            $report['winner_profile'] = $this->winnerProfile($trackId);
            $report['podium_tyres'] = $this->orderLevels(
                $this->corpus->trackPodiumTyres($trackId),
                'level'
            );
        }

        return $report;
    }

    /** Tracks the corpus has any data for — the picker for the two track screens. */
    /** @return list<array<string, mixed>> */
    public function trackOptions(): array
    {
        return $this->corpus->trackCoverage();
    }

    /**
     * Podium-versus-rest, paired per level so a template can render the gap
     * without re-joining two bands itself.
     *
     * @return list<array<string, mixed>>
     */
    private function winnerProfile(int $trackId): array
    {
        $rows = $this->corpus->trackWinnerProfile($trackId, self::MIN_SAMPLE);

        /** @var array<string, array<string, mixed>> $byLevel */
        $byLevel = [];
        foreach ($rows as $row) {
            $level = (string) ($row['level'] ?? '');
            $band = (string) ($row['band'] ?? '');
            $byLevel[$level] ??= ['level' => $level, 'podium' => null, 'rest' => null];
            $byLevel[$level][$band] = $row;
        }

        $metrics = [
            'avg_grid' => 'Grid slot', 'avg_stops' => 'Pit stops',
            'avg_start_fuel' => 'Start fuel', 'overtaking' => 'Overtake risk',
            'defensive' => 'Defend risk', 'clear_dry' => 'Clear track (dry)',
            'malfunctioning' => 'Malfunction risk', 'driver_oa' => 'Driver OA',
            'part_level' => 'Mean part level', 'boost_laps' => 'Boost laps',
        ];

        $out = [];
        foreach (self::LEVEL_ORDER as $level) {
            $entry = $byLevel[$level] ?? null;
            if ($entry === null || $entry['podium'] === null || $entry['rest'] === null) {
                continue;
            }

            /** @var array<string, mixed> $podium */
            $podium = $entry['podium'];
            /** @var array<string, mixed> $rest */
            $rest = $entry['rest'];

            $comparison = [];
            foreach ($metrics as $key => $label) {
                $a = $podium[$key] ?? null;
                $b = $rest[$key] ?? null;
                if ($a === null || $b === null) {
                    continue;
                }
                $comparison[] = [
                    'label'  => $label,
                    'podium' => (float) $a,
                    'rest'   => (float) $b,
                    'delta'  => round((float) $a - (float) $b, 2),
                ];
            }

            $out[] = [
                'level'   => $level,
                'n_podium' => (int) ($podium['n'] ?? 0),
                'n_rest'   => (int) ($rest['n'] ?? 0),
                'setup'    => [
                    'fwing' => $podium['fwing'] ?? null,
                    'rwing' => $podium['rwing'] ?? null,
                    'engine' => $podium['engine'] ?? null,
                    'brakes' => $podium['brakes'] ?? null,
                    'gear'  => $podium['gear'] ?? null,
                    'susp'  => $podium['susp'] ?? null,
                ],
                'metrics' => $comparison,
            ];
        }

        return $out;
    }

    /**
     * Turns per-level choice counts into the percentage table GPRO prints,
     * keeping every choice in its canonical order even when nobody picked it —
     * a missing row would otherwise read as a different choice's percentage.
     *
     * @param list<string> $order
     * @return array<string, mixed>
     */
    private function distribution(int $trackId, string $column, array $order): array
    {
        $rows = $this->corpus->trackRiskDistribution($trackId, $column);

        /** @var array<string, array<string, int>> $tally */
        $tally = [];
        /** @var array<string, int> $totals */
        $totals = [];

        foreach ($rows as $row) {
            $level = (string) ($row['level'] ?? '');
            $choice = (string) ($row['choice'] ?? '');
            $n = (int) ($row['n'] ?? 0);

            $tally[$level][$choice] = ($tally[$level][$choice] ?? 0) + $n;
            $totals[$level] = ($totals[$level] ?? 0) + $n;
        }

        $levels = [];
        foreach (self::LEVEL_ORDER as $level) {
            if (($totals[$level] ?? 0) > 0) {
                $levels[] = $level;
            }
        }

        $choices = [];
        foreach ($order as $choice) {
            $cells = [];
            $seen = false;
            foreach ($levels as $level) {
                $n = $tally[$level][$choice] ?? 0;
                $total = $totals[$level] ?? 0;
                if ($n > 0) {
                    $seen = true;
                }
                $cells[] = [
                    'level'   => $level,
                    'n'       => $n,
                    'percent' => $total > 0 ? round(100 * $n / $total, 1) : null,
                ];
            }

            if ($seen) {
                $choices[] = ['choice' => $choice, 'cells' => $cells];
            }
        }

        return [
            'levels'  => $levels,
            'choices' => $choices,
            'totals'  => $totals,
        ];
    }

    /**
     * Stop-count distribution as percentages per level, which is how a manager
     * reads "is this a two- or three-stop track".
     *
     * @return array<string, mixed>
     */
    private function stopDistribution(int $trackId): array
    {
        $rows = $this->corpus->trackStopDistribution($trackId);

        /** @var array<string, array<int, array<string, mixed>>> $tally */
        $tally = [];
        /** @var array<string, int> $totals */
        $totals = [];
        $stopCounts = [];

        foreach ($rows as $row) {
            $level = (string) ($row['level'] ?? '');
            $stops = (int) ($row['pit_stops'] ?? 0);
            $n = (int) ($row['n'] ?? 0);

            $tally[$level][$stops] = ['n' => $n, 'avg_pos' => $row['avg_pos'] ?? null];
            $totals[$level] = ($totals[$level] ?? 0) + $n;
            $stopCounts[$stops] = true;
        }

        $levels = [];
        foreach (self::LEVEL_ORDER as $level) {
            if (($totals[$level] ?? 0) > 0) {
                $levels[] = $level;
            }
        }

        ksort($stopCounts);

        $out = [];
        foreach (array_keys($stopCounts) as $stops) {
            $cells = [];
            foreach ($levels as $level) {
                $entry = $tally[$level][$stops] ?? null;
                $total = $totals[$level] ?? 0;
                $n = $entry === null ? 0 : (int) $entry['n'];
                $cells[] = [
                    'level'   => $level,
                    'n'       => $n,
                    'percent' => $total > 0 ? round(100 * $n / $total, 1) : null,
                    'avg_pos' => $entry['avg_pos'] ?? null,
                ];
            }
            $out[] = ['stops' => $stops, 'cells' => $cells];
        }

        return ['levels' => $levels, 'rows' => $out, 'totals' => $totals];
    }

    /**
     * Sorts rows into ladder order. Anything with an unrecognised level keeps
     * its place at the end rather than vanishing.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function orderLevels(array $rows, string $key): array
    {
        usort($rows, static function (array $a, array $b) use ($key): int {
            $ia = array_search((string) ($a[$key] ?? ''), self::LEVEL_ORDER, true);
            $ib = array_search((string) ($b[$key] ?? ''), self::LEVEL_ORDER, true);

            return ($ia === false ? PHP_INT_MAX : $ia) <=> ($ib === false ? PHP_INT_MAX : $ib);
        });

        return $rows;
    }

    private function trackNameFor(int $trackId): ?string
    {
        foreach ($this->corpus->trackCoverage() as $row) {
            if ((int) ($row['track_id'] ?? 0) === $trackId) {
                $name = $row['track_name'] ?? null;
                return is_string($name) ? $name : null;
            }
        }

        return null;
    }

    /** @param array<string, int|string|null> $filters */
    private function countActive(array $filters): int
    {
        $n = 0;
        foreach ($filters as $value) {
            if ($value !== null && $value !== '') {
                $n++;
            }
        }

        return $n;
    }
}
