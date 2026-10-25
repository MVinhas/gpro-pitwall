<?php

declare(strict_types=1);

namespace App\Service;

use App\Calibration\FuelCalibration;
use App\Calibration\LedgerCategories;
use App\Calibration\WearCalibration;
use App\Debrief\WeekendDiff;
use App\Repository\EconomyLedgerRepository;
use App\Repository\RaceHistoryRepository;
use App\Repository\RaceTelemetryRepository;
use App\Repository\StandingsRepository;
use App\Repository\TrackRepository;
use App\Repository\WeekendStateRepository;
use App\Support\Sparkline;

/**
 * One season at a glance: how the races went, where the money went, what the
 * manager changed in the team, and how far the wear and fuel models drifted.
 * Every source is read for the season in one query, and each distinct track
 * name is looked up once, so the page costs a handful of queries plus one per
 * track rather than one per race.
 *
 * @phpstan-import-type Change from WeekendDiff
 * @phpstan-type Result array{
 *     race: int, track_name: string, final_pos: int|null, points: int|null, dnf: bool,
 *     champ_pos: int|null, champ_pts: int|null
 * }
 * @phpstan-type Category array{category: string, income: int, costs: int}
 * @phpstan-type Money array{
 *     cash: list<array{race: int, cash: int}>, cash_line: string,
 *     categories: list<Category>, income: int, costs: int
 * }
 * @phpstan-type PartBias array{label: string, mean_gap: float|null, n: int}
 * @phpstan-type Calibration array{
 *     wear: array{parts: array<string, PartBias>, skipped: int, enough: bool},
 *     fuel: array{mean_gap_pct: float|null, n: int, skipped: int, enough: bool},
 *     corpus: array{mean_gap: float|null, n: int}
 * }
 */
class SeasonService
{
    private const int SPARK_WIDTH = 240;
    private const int SPARK_HEIGHT = 40;

    public function __construct(
        private readonly RaceHistoryRepository $history,
        private readonly WeekendStateRepository $weekends,
        private readonly StandingsRepository $standings,
        private readonly EconomyLedgerRepository $ledger,
        private readonly RaceTelemetryRepository $corpus,
        private readonly TrackRepository $tracks,
        private readonly WearCalibration $wear,
        private readonly FuelCalibration $fuel,
    ) {
    }

    /**
     * @return array{
     *     season: int|null, seasons: list<int>, has_races: bool,
     *     results: list<Result>, results_line: string,
     *     money: Money, changes: list<array{race: int, items: list<Change>}>,
     *     calibration: Calibration
     * }
     */
    public function build(int $userId, ?int $season): array
    {
        $seasons = $this->history->seasons($userId);
        $season = $season !== null && in_array($season, $seasons, true) ? $season : ($seasons[0] ?? null);
        $rows = $season === null ? [] : $this->history->forSeason($userId, $season);

        if ($season === null || $rows === []) {
            return [
                'season' => $season,
                'seasons' => $seasons,
                'has_races' => false,
                'results' => [],
                'results_line' => '',
                'money' => ['cash' => [], 'cash_line' => '', 'categories' => [], 'income' => 0, 'costs' => 0],
                'changes' => [],
                'calibration' => $this->calibration([]),
            ];
        }

        $results = $this->results($userId, $season, $rows);

        return [
            'season' => $season,
            'seasons' => $seasons,
            'has_races' => true,
            'results' => $results,
            'results_line' => Sparkline::points(
                array_column($results, 'champ_pos'),
                self::SPARK_WIDTH,
                self::SPARK_HEIGHT,
                true,
            ),
            'money' => $this->money($userId, $season),
            'changes' => $this->changes($userId, $season),
            'calibration' => $this->calibration($rows),
        ];
    }

    /**
     * Standings rows mean "entering race N", so the table after race R is the
     * row stored for R + 1.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<Result>
     */
    private function results(int $userId, int $season, array $rows): array
    {
        $standings = [];
        foreach ($this->standings->forSeason($userId, $season) as $row) {
            $standings[(int) $row['race']] = $row;
        }

        $results = [];
        foreach ($rows as $row) {
            $race = (int) $row['race'];
            $after = $standings[$race + 1] ?? null;
            $results[] = [
                'race' => $race,
                'track_name' => (string) ($row['track_name'] ?? ''),
                'final_pos' => $this->intOrNull($row['final_pos'] ?? null),
                'points' => $this->intOrNull($row['points'] ?? null),
                'dnf' => (int) ($row['dnf'] ?? 0) === 1,
                'champ_pos' => $this->intOrNull($after['own_pos'] ?? null),
                'champ_pts' => $this->intOrNull($after['own_pts'] ?? null),
            ];
        }

        return $results;
    }

    /**
     * Cash after a race is the balance on its last ledger line.
     *
     * @return Money
     */
    private function money(int $userId, int $season): array
    {
        $balance = [];
        $totals = [];
        foreach ($this->ledger->forSeason($userId, $season) as $row) {
            $race = (int) $row['race'];
            $balance[$race] = $this->intOrNull($row['balance_after'] ?? null);

            $amount = (int) ($row['amount'] ?? 0);
            $category = LedgerCategories::categorise((string) ($row['description'] ?? ''));
            $totals[$category] ??= ['income' => 0, 'costs' => 0];
            if ($amount >= 0) {
                $totals[$category]['income'] += $amount;
            } else {
                $totals[$category]['costs'] -= $amount;
            }
        }

        $cash = [];
        foreach ($balance as $race => $value) {
            if ($value !== null) {
                $cash[] = ['race' => $race, 'cash' => $value];
            }
        }

        // x follows race number; a race with no balance is dropped from the polyline, which joins across it.
        $series = [];
        if ($balance !== []) {
            for ($race = min(array_keys($balance)); $race <= max(array_keys($balance)); $race++) {
                $series[] = $balance[$race] ?? null;
            }
        }

        $categories = [];
        $income = 0;
        $costs = 0;
        foreach (LedgerCategories::ORDER as $category) {
            $sum = $totals[$category] ?? null;
            if ($sum === null || ($sum['income'] === 0 && $sum['costs'] === 0)) {
                continue;
            }
            $categories[] = ['category' => $category, 'income' => $sum['income'], 'costs' => $sum['costs']];
            $income += $sum['income'];
            $costs += $sum['costs'];
        }

        return [
            'cash' => $cash,
            'cash_line' => Sparkline::points($series, self::SPARK_WIDTH, self::SPARK_HEIGHT),
            'categories' => $categories,
            'income' => $income,
            'costs' => $costs,
        ];
    }

    /**
     * @return list<array{race: int, items: list<Change>}>
     */
    private function changes(int $userId, int $season): array
    {
        $states = $this->weekends->forSeason($userId, $season);
        if ($states === []) {
            return [];
        }

        $previous = $this->weekends->previous($userId, $season, (int) $states[0]['race']);
        $changes = [];
        foreach ($states as $state) {
            $items = WeekendDiff::withNames(WeekendDiff::changes($previous, $state), $previous, $state);
            if ($items !== []) {
                $changes[] = ['race' => (int) $state['race'], 'items' => $items];
            }
            $previous = $state;
        }

        return $changes;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return Calibration
     */
    private function calibration(array $rows): array
    {
        $corpusRows = $this->corpus->calibrationRows();

        $trackRows = [];
        $looked = [];
        foreach ([...$rows, ...$corpusRows] as $row) {
            $name = (string) ($row['track_name'] ?? '');
            if ($name === '' || isset($looked[$name])) {
                continue;
            }
            $looked[$name] = true;
            $track = $this->tracks->findByName($name);
            if ($track !== null) {
                $trackRows[$name] = $track;
            }
        }

        return [
            'wear' => $this->wear->bias($rows, $trackRows),
            'fuel' => $this->fuel->bias($rows, $trackRows),
            'corpus' => $this->wear->corpusCheck($corpusRows, $trackRows),
        ];
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
