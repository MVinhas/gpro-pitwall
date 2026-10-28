<?php

declare(strict_types=1);

namespace App\Service;

use App\Calibration\FuelCalibration;
use App\Calibration\LedgerCategories;
use App\Calibration\WearCalibration;
use App\Debrief\WeekendDiff;
use App\Engineer\CtrPlanner;
use App\Engineer\Money as Dollars;
use App\Repository\EconomyLedgerRepository;
use App\Repository\RaceHistoryRepository;
use App\Repository\RaceTelemetryRepository;
use App\Repository\SeasonBudgetRepository;
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
 * @phpstan-import-type Plan from CtrPlanner
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
 * @phpstan-type BudgetRowView array{
 *     race: int, track: string, replaced: list<array{part: string, cost: string}>, replaced_none: string,
 *     running: string, income: string, balance: string, balance_note: string, tone: 'ink'|'amber'|'red'
 * }
 * @phpstan-type UpgradeRowView array{part: string, level: string, gain: string, price: string, net: string, fits: bool}
 * @phpstan-type BudgetView array{
 *     season: int, race: int, computed_at: string, plan: Plan,
 *     view: array{
 *         as_of: string, target: string, spend: array{value: string, tone: 'ink'|'amber'|'red'},
 *         rows: list<BudgetRowView>, partial: string, upgrades: list<UpgradeRowView>|null
 *     }
 * }
 */
class SeasonService
{
    private const int SPARK_WIDTH = 240;
    private const int SPARK_HEIGHT = 40;
    private const array PLAN_KEYS = [
        'ctr', 'mode', 'target', 'target_kind', 'cushion', 'season_start', 'envelope', 'shortfall', 'rows', 'partial',
    ];

    public function __construct(
        private readonly RaceHistoryRepository $history,
        private readonly WeekendStateRepository $weekends,
        private readonly StandingsRepository $standings,
        private readonly EconomyLedgerRepository $ledger,
        private readonly RaceTelemetryRepository $corpus,
        private readonly TrackRepository $tracks,
        private readonly WearCalibration $wear,
        private readonly FuelCalibration $fuel,
        private readonly SeasonBudgetRepository $budgets,
    ) {
    }

    /**
     * @return array{
     *     season: int|null, seasons: list<int>, has_races: bool,
     *     results: list<Result>, results_line: string,
     *     money: Money, changes: list<array{race: int, items: list<Change>}>,
     *     calibration: Calibration, budget: BudgetView|null, budget_over: bool
     * }
     */
    public function build(int $userId, ?int $season): array
    {
        $seasons = $this->history->seasons($userId);
        $season = $season !== null && in_array($season, $seasons, true) ? $season : ($seasons[0] ?? null);
        $rows = $season === null ? [] : $this->history->forSeason($userId, $season);
        [$budget, $budgetOver] = $season === ($seasons[0] ?? null)
            ? $this->budget($userId, $season, $rows)
            : [null, false];

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
                'budget' => $budget,
                'budget_over' => $budgetOver,
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
            'budget' => $budget,
            'budget_over' => $budgetOver,
        ];
    }

    /**
     * The plan the Cockpit's engineer last saved, formatted for the page. The Season screen has none of the
     * Cockpit's live inputs, so it only ever shows what was saved. It belongs to the newest season view, and
     * its season may be newer than any race history (a new season's first weekend). Once that season's
     * history reaches the plan's last forecast race the plan is spent: no budget, and $over is true.
     *
     * @param list<array<string, mixed>> $rows the shown season's race history
     * @return array{BudgetView|null, bool}
     */
    private function budget(int $userId, ?int $season, array $rows): array
    {
        try {
            $saved = $this->budgets->latest($userId);
            if ($saved === null || ($season !== null && $saved['season'] < $season)) {
                return [null, false];
            }

            $plan = self::checkedPlan($saved['plan']);
            $history = $saved['season'] === $season ? $rows : $this->history->forSeason($userId, $saved['season']);
            $lastForecast = max(array_column($plan['rows'], 'race') ?: [0]);
            if (max(array_map(static fn (array $r): int => (int) $r['race'], $history) ?: [0]) >= $lastForecast) {
                return [null, true];
            }

            $asOf = 'as of race ' . $saved['race'];
            $asOf = $saved['season'] === $season ? ucfirst($asOf) : 'Season ' . $saved['season'] . ' · ' . $asOf;

            return [[
                'season' => $saved['season'],
                'race' => $saved['race'],
                'computed_at' => $saved['computed_at'],
                'plan' => $plan,
                'view' => [
                    'as_of' => $asOf,
                    'target' => $this->target($plan),
                    'spend' => $this->spend($plan),
                    'rows' => $this->budgetRows($plan),
                    'partial' => implode(', ', $plan['partial']),
                    'upgrades' => $this->upgradeRows($plan),
                ],
            ], false];
        } catch (\Throwable $e) {
            error_log('[Season] ' . $e::class);

            return [null, false];
        }
    }

    /**
     * A v1 plan saved with keys missing (a hand edit, or a shape change without a version bump) is refused
     * rather than half-rendered.
     *
     * @param array<string, mixed> $plan
     * @return Plan
     */
    private static function checkedPlan(array $plan): array
    {
        $missing = array_diff(self::PLAN_KEYS, array_keys($plan));
        if ($missing !== [] || !is_array($plan['rows']) || !is_array($plan['partial'])) {
            throw new \UnexpectedValueException('Saved budget plan has an unexpected shape.');
        }

        /** @var Plan $plan */
        return $plan;
    }

    /**
     * @param Plan $plan
     */
    private function target(array $plan): string
    {
        return match ($plan['target_kind']) {
            'start' => 'End above ' . Dollars::short($plan['target']),
            'start_out_of_reach' => 'Keep one race of costs in hand (your '
                . Dollars::short($plan['season_start'] ?? $plan['target']) . ' start is out of reach)',
            default => 'Keep one race of costs in hand',
        };
    }

    /**
     * @param Plan $plan
     *
     * @return array{value: string, tone: 'ink'|'amber'|'red'}
     */
    private function spend(array $plan): array
    {
        return match (true) {
            $plan['mode'] === 'short' => [
                'value' => 'Over budget by ' . Dollars::short($plan['shortfall'] ?? 0),
                'tone' => 'red',
            ],
            $plan['envelope'] <= 0 => ['value' => 'Nothing to spare', 'tone' => 'amber'],
            default => ['value' => Dollars::short($plan['envelope']), 'tone' => 'ink'],
        };
    }

    /**
     * @param Plan $plan
     *
     * @return list<BudgetRowView>
     */
    private function budgetRows(array $plan): array
    {
        $rows = [];
        $last = array_key_last($plan['rows']);
        foreach ($plan['rows'] as $i => $row) {
            // Every race keeps the cushion; only the last must reach the end target.
            $target = $i === $last ? $plan['target'] : $plan['cushion'];
            [$tone, $note] = match (true) {
                $row['balance'] < 0 => ['red', ' · negative'],
                $row['balance'] < $target => ['amber', ' · below target'],
                default => ['ink', ''],
            };
            $rows[] = [
                'race' => $row['race'],
                'track' => $row['track_name'] ?? '—',
                'replaced' => $row['wear_known'] ? array_map(
                    static fn (array $s): array => ['part' => $s['part'], 'cost' => Dollars::short($s['cost'])],
                    $row['replaced'],
                ) : [],
                'replaced_none' => match (true) {
                    !$row['wear_known'] => 'wear unknown',
                    $row['replaced'] === [] => '—',
                    default => '',
                },
                'running' => Dollars::short($row['running']),
                'income' => Dollars::short($row['income']),
                'balance' => Dollars::short($row['balance']),
                'balance_note' => $note,
                'tone' => $tone,
            ];
        }

        return $rows;
    }

    /**
     * Null for a plan saved before the ranking existed, or whose ranking has a malformed row (that costs only
     * the Upgrades card, never the budget); an empty list is a ranking that found nothing worth buying.
     *
     * @param Plan $plan
     *
     * @return list<UpgradeRowView>|null
     */
    private function upgradeRows(array $plan): ?array
    {
        if (!isset($plan['upgrades'])) {
            return null;
        }

        try {
            foreach ($plan['upgrades'] as $u) {
                if (!self::isUpgrade($u)) {
                    throw new \UnexpectedValueException('Saved upgrade ranking has an unexpected row.');
                }
            }

            return array_map(static fn (array $u): array => [
                'part' => $u['part'],
                'level' => 'L' . $u['from'] . ' → L' . $u['to'],
                'gain' => number_format($u['gain'], 1),
                'price' => Dollars::short($u['price']),
                'net' => $u['net_cost'] > 0 ? Dollars::short($u['net_cost']) : 'no extra cost',
                'fits' => $u['affordable'],
            ], $plan['upgrades']);
        } catch (\Throwable $e) {
            error_log('[Season] ' . $e::class);

            return null;
        }
    }

    private static function isUpgrade(mixed $u): bool
    {
        return is_array($u)
            && is_string($u['part'] ?? null)
            && is_int($u['from'] ?? null)
            && is_int($u['to'] ?? null)
            && is_int($u['price'] ?? null)
            && is_int($u['net_cost'] ?? null)
            && (is_int($u['gain'] ?? null) || is_float($u['gain'] ?? null))
            && is_bool($u['affordable'] ?? null);
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
