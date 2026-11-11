<?php

declare(strict_types=1);

namespace App\Service;

use App\Engineer\CashOutlook;
use App\Engineer\CtrCeiling;
use App\Engineer\CtrPace;
use App\Engineer\CtrPlanner;
use App\Engineer\CtrPolicy;
use App\Engineer\DriverHiringAdvisor;
use App\Engineer\EnergyModel;
use App\Engineer\EngineerInputs;
use App\Engineer\GroupStanding;
use App\Engineer\Money;
use App\Engineer\PlaceGap;
use App\Engineer\PrizeTable;
use App\Engineer\RaceEngineer;
use App\Engineer\SeasonBudget;
use App\Engineer\SeasonObjective;
use App\Engineer\SeasonStart;
use App\Engineer\SmokeCost;
use App\Engineer\SmokeDeferral;
use App\Engineer\SmokeRisk;
use App\Engineer\StrengthFloor;
use App\Engineer\SwapSeason;
use App\Engineer\TestingGate;
use App\Engineer\TyreSupplierAdvisor;
use App\Engineer\UpgradeRanker;
use App\Repository\EconomyLedgerRepository;
use App\Repository\RaceTelemetryRepository;
use App\Repository\SeasonBudgetRepository;
use App\Repository\SeasonObjectiveRepository;
use App\Repository\StandingsRepository;
use App\Repository\TrackRepository;
use App\Repository\WeekendChoiceRepository;
use App\Repository\WeekendStateRepository;
use App\Support\OfficeFlags;
use App\Support\TrackName;

/**
 * Admin-only race engineer: loads the stored season history, reuses the values
 * Cockpit already computed and the existing advisors, and hands the pure
 * engineer classes everything they need.
 *
 * @phpstan-import-type InForce from SeasonObjective
 * @phpstan-import-type Line from RaceEngineer
 * @phpstan-import-type Plan from CtrPlanner
 * @phpstan-import-type Round from \App\Engineer\SeasonBudget
 * @phpstan-import-type Demand from UpgradeRanker
 * @phpstan-type PlanBasis array{
 *     rounds: list<Round>,
 *     parts: array<string, array{level: int, start: int|float, track_base: int|float, training?: int|float}>,
 *     prices: array<string, ?int>,
 *     driverFactor: float
 * }
 * @phpstan-import-type Risk from EngineerInputs
 * @phpstan-import-type TestingTarget from EngineerInputs
 * @phpstan-import-type WearAdvice from EngineerInputs
 * @phpstan-import-type SwapPick from EngineerInputs
 * @phpstan-import-type SmokeQuote from EngineerInputs
 * @phpstan-import-type TyreAdvice from TyreSupplierAdvisor
 * @phpstan-import-type TyreVerdict from TyreSupplierAdvisor
 * @phpstan-type TyrePanel array{
 *     pick: string,
 *     cost: int,
 *     best: string,
 *     reason: 'best'|'band'|'budget_short',
 *     wet_edge: bool,
 *     talent: ?int,
 *     baseline: ?int,
 *     finances_known: bool,
 *     figure: string,
 *     rows: list<array{
 *         name: string,
 *         cost: int,
 *         season_cost: int,
 *         total: float,
 *         parts: array{performance: float, temperature: float, durability: float, warmup: float},
 *         verdict: TyreVerdict,
 *         per_race: string,
 *         season: string
 *     }>
 * }
 * @phpstan-import-type DriverAdvice from DriverHiringAdvisor
 * @phpstan-import-type Current from DriverHiringAdvisor
 * @phpstan-import-type HiringMoney from DriverHiringAdvisor
 * @phpstan-type DriverPanel array{
 *     verdict: string,
 *     reason: string,
 *     answer: string,
 *     figure: ?string,
 *     current: ?array{name: string, rating: ?string, overall: string, cap: ?int, age: int, salary: string,
 *         races_left: int},
 *     group_average: ?string,
 *     extension: ?array{fee: string, salary: string, exact: bool},
 *     shortlist: list<array{
 *         name: string,
 *         rating: string,
 *         gain: ?string,
 *         salary: string,
 *         to_sign: string,
 *         age: int,
 *         overall: int,
 *         favourites: int,
 *         fits: string
 *     }>,
 *     out: list<array{label: string, count: int}>,
 *     notes: list<string>
 * }
 * @phpstan-type DriverCall array{advice: DriverAdvice, line: Line, panel: DriverPanel}
 * @phpstan-type EnergyCap array{cap: int, estimate: bool, start: ?int}
 * @phpstan-type TestingCall array{gate: TestingGate, picks: list<SwapPick>, unaffordable: bool}
 * @phpstan-type Context array{
 *     season: int,
 *     raceNb: int,
 *     cash: int,
 *     weekends: list<array<string, mixed>>,
 *     ledger: list<array<string, mixed>>,
 *     outlook_ledger: list<array<string, mixed>>,
 *     outlook: array{known: bool, committed: ?int, income: ?int, free_cash: ?int, end_committed: ?int},
 *     objective: InForce
 * }
 */
class RaceEngineerService
{
    private const array PINNABLE = [SeasonObjective::PROMOTE, SeasonObjective::STAY];
    private const string AUTO = 'auto';
    private const array TYRE_DIVISIONS = ['Pro', 'Master', 'Elite'];
    private const array TYRE_WEAR_SCALE = ['Very Low' => 1, 'Low' => 2, 'Medium' => 3, 'High' => 4, 'Very High' => 5];
    /** The plan keys the second planning pass, on the car after its update, decides. */
    private const array FROM_PASS_TWO = [
        'ctr', 'ctr_kind', 'safety', 'target', 'target_kind', 'cushion', 'shortfall', 'partial', 'value',
        'energy_cap', 'energy_estimate', 'energy_start', 'refused_swap_cost', 'infeasible', 'rows', 'envelope',
    ];
    private const string OFFER_NOTE = 'Offer a 17-race contract. Each offer costs from $500,000 and is not refunded; '
        . 'drivers decide Mondays and Thursdays, 19:00–21:00 CET.';
    /** Why market drivers were left out, in the order the panel lists them. */
    private const array OUT_LABELS = [
        'retiring' => 'Retiring',
        'cap' => 'Over the cap',
        'age' => 'Aged 35 or more',
        'offers' => 'Has offers',
        'salary_average' => "Above your group's average",
        'salary_plan' => 'Salary over the plan',
        'switch_cost' => 'Switch cost over the plan',
    ];

    public function __construct(
        private readonly SeasonObjectiveRepository $pins,
        private readonly StandingsRepository $standings,
        private readonly WeekendStateRepository $weekends,
        private readonly EconomyLedgerRepository $ledger,
        private readonly TrackRepository $tracks,
        private readonly CarWearService $wear,
        private readonly CarWearPlannerService $planner,
        private readonly CtrPlanner $ctrPlanner,
        private readonly SeasonBudgetRepository $budgets,
        private readonly CtrCeiling $ceiling,
        private readonly RiskAdvisorService $riskAdvisor,
        private readonly BoostFuelService $boost,
        private readonly FuelModel $fuel,
        private readonly UpgradeRanker $ranker,
        private readonly PartSwapAdvisorService $swapAdvisor,
        private readonly RaceTelemetryRepository $telemetry,
        private readonly IdealPilotService $idealPilot,
        private readonly CtrModelService $ctrModel,
        private readonly float $ctrGainFraction,
        private readonly ?WeekendChoiceRepository $choices = null,
        private readonly WearAdvisorService $wearAdvisor = new WearAdvisorService(),
        private readonly ?RecruitmentService $recruitment = null,
    ) {
    }

    /**
     * @param array<string, mixed> $c Cockpit's already-computed values
     * @param bool $stale A race has finished since the last sync: the figures are last weekend's, so the
     *                    engineer asks for a re-sync instead of planning (or saving a plan) on them.
     * @return array{
     *     objective: InForce,
     *     lines: list<Line>,
     *     season: int,
     *     tyres: ?TyrePanel,
     *     car_updated: bool,
     *     driver: ?DriverPanel
     * }
     */
    public function forCockpit(int $userId, array $c, bool $stale = false): array
    {
        $ctx = $this->context($userId, $c);
        $objective = $ctx['objective'];
        $season = $ctx['season'];
        $locked = OfficeFlags::done(self::arr($c['office'] ?? null), 'doneCarRepair');

        if ($stale) {
            return [
                'objective' => $objective,
                'lines' => [RaceEngineer::stale()],
                'season' => $season,
                'tyres' => null,
                'car_updated' => $locked,
                'driver' => null,
            ];
        }

        $choices = $this->choicesFor($userId, $season, $ctx['raceNb']);
        $rounds = $this->rounds($c);
        $energy = $this->energyCap($ctx, $rounds, $c);
        ['basis' => $basis, 'plan' => $plan, 'inputs' => $inputs]
            = $this->pass($ctx, $c, $rounds, $energy, $locked, $choices);

        $call = $this->testingCall($ctx, $c, $plan, $basis, $inputs, $locked, $choices['testing'] ?? null);
        $inputs = self::withTestingCall($inputs, $call);
        if (self::testingWontHappen($inputs) && self::hasTestingWear($c)) {
            // The gate, the unlock call and the engineer's own skip stay pass 1's: they read the wear as given,
            // before testing is dropped, and the Cockpit still counts its laps.
            $skip = RaceEngineer::testingSkip($inputs);
            $laps = $inputs->testingLaps;
            $c = $this->withoutTestingWear($c);
            ['basis' => $basis, 'plan' => $plan, 'inputs' => $inputs]
                = $this->pass($ctx, $c, $rounds, $energy, $locked, $choices);
            $inputs = self::withTestingCall($inputs, $call)->withTestingSkip($skip, $laps);
        }

        // Quoted at pass 1's CTR: a part let smoke takes the upgrade out of the update the second pass plans.
        $inputs = $inputs->withSmoke($this->smokeQuotes($ctx, $inputs, $basis, $c));

        // Under a plan the advice waits for the plan it is checked against, below.
        $tyres = $plan === null ? $this->tyreAdvice($c, null, $ctx) : null;
        if ($plan !== null && $basis !== null) {
            if ($call['picks'] !== []) {
                $plan['swap_picks'] = [...$inputs->swapPicks, ...$call['picks']];
                $inputs = $inputs->withPlan($plan);
            }
            $tyres = $this->tyreAdvice($c, $plan, $ctx);
            $tyreExtra = self::tyrePickExtra($tyres, $ctx['outlook_ledger']);
            $plan = self::payingTyreExtra($plan, $tyreExtra);
            $inputs = $inputs->withPlan($plan);
            $outlook = ['committed' => (int) $ctx['outlook']['committed'] + $tyreExtra] + $ctx['outlook'];
            $saved = $plan;
            if (!$locked) {
                if (RaceEngineer::letSmoke($inputs) === []) {
                    $plan = $this->withUpgrades($plan, $inputs, $objective, $outlook, $ctx['cash'], $basis, $c);
                    $inputs = $inputs->withPlan($plan);
                }
                ['card' => $plan, 'saved' => $saved]
                    = $this->afterTheUpdate($ctx, $plan, $basis, $inputs, $c, $energy, $tyreExtra);
                $inputs = $inputs->withPlan($plan);
            } else {
                $saved['upgrades_locked'] = true;
            }
            try {
                $this->budgets->save($userId, $season, $ctx['raceNb'], $saved);
            } catch (\Throwable $e) {
                // Saving only feeds the Season screen; the advice stands without it.
                error_log('[Engineer] ' . $e::class);
            }
        }

        $driver = $objective['over']
            ? null
            : $this->driverCall($c, $inputs, $ctx['cash'], $objective['objective'] === SeasonObjective::PROMOTE);
        $lines = RaceEngineer::brief($inputs->withTyreAdvice($tyres)->withDriverAdvice($driver['advice'] ?? null));

        return [
            'objective' => $objective,
            'lines' => $lines,
            'season' => $season,
            'tyres' => $tyres === null ? null : self::tyrePanel($tyres),
            'car_updated' => $locked,
            'driver' => $driver['panel'] ?? null,
        ];
    }

    /**
     * The Driver panel for a manager with no driver under contract, whose screen has no card to plan: who to
     * sign, checked against the cash only. Null when the advice cannot be built.
     *
     * @param array<string, mixed> $c Cockpit's already-computed values
     * @return ?array{line: Line, panel: DriverPanel}
     */
    public function driverCard(int $userId, array $c): ?array
    {
        if ($this->recruitment === null) {
            return null;
        }

        try {
            $ctx = $this->context($userId, $c);
            // The division, the cap and the ages may still be last season's.
            if ($ctx['objective']['over']) {
                return null;
            }
            $cash = $ctx['cash'];
            $promote = $ctx['objective']['objective'] === SeasonObjective::PROMOTE;
        } catch (\Throwable $e) {
            // Without the stored season the shortlist still stands on the cash guard.
            error_log('[Engineer] ' . $e::class);
            $cash = self::cash($c);
            $promote = false;
        }
        $driver = $this->driverCall($c, null, $cash, $promote);

        return $driver === null ? null : ['line' => $driver['line'], 'panel' => $driver['panel']];
    }

    /**
     * One planning pass over $c: the basis, the plan with its forced swaps' season picks, and the inputs.
     *
     * @param Context $ctx
     * @param array<string, mixed> $c
     * @param list<Round> $rounds
     * @param EnergyCap $energy
     * @param array{testing?: string, training?: string} $choices
     * @return array{basis: ?PlanBasis, plan: ?Plan, inputs: EngineerInputs}
     */
    private function pass(array $ctx, array $c, array $rounds, array $energy, bool $locked, array $choices): array
    {
        $basis = $this->planBasis($ctx['outlook'], $ctx['raceNb'], $rounds, $c);
        $plan = $basis === null ? null : $this->plan($ctx, $basis, $this->policy($ctx, $basis, $c, $energy), $locked);
        $inputs = $this->inputs(
            $ctx['objective'],
            $ctx['outlook'],
            $ctx['cash'],
            $ctx['raceNb'],
            $rounds,
            $plan,
            $c,
            $locked,
            $energy,
            $choices,
            $ctx['season'],
        );
        if ($plan === null || $locked) {
            return ['basis' => $basis, 'plan' => $plan, 'inputs' => $inputs];
        }

        $picks = [];
        try {
            $picks = $this->picksFor(
                array_column($plan['rows'][0]['replaced'], 'part'),
                $plan,
                $this->swapSeasonFrom($ctx, $plan, $basis, $c),
                $c,
            );
        } catch (\Throwable $e) {
            // The picks only name a level; the parts line keeps its wording without them.
            error_log('[Engineer] ' . $e::class);
        }
        if ($picks !== []) {
            $plan['swap_picks'] = $picks;
            $inputs = $inputs->withPlan($plan)->withSwapPicks($picks);
        }

        return ['basis' => $basis, 'plan' => $plan, 'inputs' => $inputs];
    }

    /**
     * GPRO's testing gate on the wear as given, and under Promote the swaps that unlock it. Unlock swaps need a
     * plan to be priced, so without one the gate is blocked; unless the manager chose to test, they must leave
     * the session affordable, or the gate is blocked by the budget. A blocking part the picker can't judge
     * leaves no swap to name, so it blocks too.
     *
     * @param Context $ctx
     * @param array<string, mixed> $c
     * @param ?Plan $plan
     * @param ?PlanBasis $basis
     * @return TestingCall
     */
    private function testingCall(
        array $ctx,
        array $c,
        ?array $plan,
        ?array $basis,
        EngineerInputs $inputs,
        bool $locked,
        ?string $choice,
    ): array {
        $gate = TestingGate::decide(
            $inputs->testingOpen && $inputs->testingTarget !== null,
            $inputs->division,
            $ctx['objective']['objective'] ?? SeasonObjective::STAY,
            $locked,
            array_map(static fn (array $part): float => (float) $part['start'], $this->wearParts($c) ?? []),
            // A spent car update replaces nothing, whatever the Cockpit's swap advice says.
            $locked ? [] : array_column(
                $plan === null ? $inputs->wearAdvice['swap'] ?? [] : $plan['rows'][0]['replaced'],
                'part',
            ),
        );
        if ($gate->state !== TestingGate::UNLOCK || $choice === 'skip' || $locked) {
            return ['gate' => $gate, 'picks' => [], 'unaffordable' => false];
        }
        if ($plan === null || $basis === null) {
            return ['gate' => $gate->blocked(), 'picks' => [], 'unaffordable' => false];
        }

        $blocking = array_column($gate->blocking, 'part');
        $picks = [];
        try {
            $picks = $this->picksFor($blocking, $plan, $this->swapSeasonFrom($ctx, $plan, $basis, $c), $c);
        } catch (\Throwable $e) {
            error_log('[Engineer] ' . $e::class);
        }
        if (array_diff($blocking, array_column($picks, 'part')) !== []) {
            return ['gate' => $gate->blocked(), 'picks' => [], 'unaffordable' => false];
        }
        if (
            $choice !== 'do'
            && RaceEngineer::testingHeadroom($inputs->withUnlockPicks($picks)) < RaceEngineer::TESTING_SESSION_COST
        ) {
            return ['gate' => $gate->blocked(), 'picks' => [], 'unaffordable' => true];
        }

        return ['gate' => $gate, 'picks' => $picks, 'unaffordable' => false];
    }

    /**
     * @param TestingCall $call
     */
    private static function withTestingCall(EngineerInputs $inputs, array $call): EngineerInputs
    {
        return $inputs->withTestingGate($call['gate'], $call['unaffordable'])->withUnlockPicks($call['picks']);
    }

    private static function testingWontHappen(EngineerInputs $in): bool
    {
        return $in->testingChoice === 'skip'
            || $in->testingGate === null
            || !$in->testingGate->runs()
            || ($in->testingChoice === null && !RaceEngineer::testingDrawn($in));
    }

    /**
     * @param array<string, mixed> $c
     */
    private static function hasTestingWear(array $c): bool
    {
        foreach (self::arr(self::arr($c['wear'] ?? null)['parts'] ?? null) as $part) {
            $training = self::arr($part)['training'] ?? null;
            if (is_numeric($training) && (float) $training > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * $c as the Cockpit would compute it with no testing laps: each part's testing wear taken out of its
     * projection, and the forced swaps classified again from that. The Cockpit's swap plan priced the old forced
     * set, so it goes when that set changes.
     *
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    private function withoutTestingWear(array $c): array
    {
        $wear = self::arr($c['wear'] ?? null);
        $parts = [];
        $classify = [];
        foreach (self::arr($wear['parts'] ?? null) as $label => $part) {
            $part = self::arr($part);
            $training = is_numeric($part['training'] ?? null) ? (float) $part['training'] : 0.0;
            $part = [
                'training' => 0.0,
                'est' => round((float) ($part['est'] ?? 0) - $training, 1),
                'end' => round((float) ($part['end'] ?? 0) - $training, 1),
            ] + $part;
            $parts[$label] = $part;
            $classify[$label] = [
                'level' => (int) ($part['level'] ?? 0),
                'start' => (float) ($part['start'] ?? 0),
                'est' => $part['est'],
                'end' => $part['end'],
            ];
        }
        $advice = $this->wearAdvisor->classify($classify);
        $wasForced = array_column(self::list(self::arr($c['wear_advice'] ?? null)['swap'] ?? null), 'part');

        return [
            'wear' => ['parts' => $parts] + $wear,
            'training_wear' => [],
            'wear_advice' => $advice,
            'training_laps' => 0,
            'swap_plan' => array_column($advice['swap'], 'part') === $wasForced ? $c['swap_plan'] ?? null : null,
        ] + $c;
    }

    /**
     * The season a swap is judged over, built the way the engineer builds its plan but never saved. Null when
     * the plan can't be built (costs, calendar or next-race wear unknown).
     *
     * @param array<string, mixed> $c Cockpit's already-computed values
     */
    public function swapSeason(int $userId, array $c): ?SwapSeason
    {
        $ctx = $this->context($userId, $c);
        $rounds = $this->rounds($c);
        $basis = $this->planBasis($ctx['outlook'], $ctx['raceNb'], $rounds, $c);
        if ($basis === null) {
            return null;
        }
        $policy = $this->policy($ctx, $basis, $c, $this->energyCap($ctx, $rounds, $c));

        return $this->swapSeasonFrom($ctx, $this->plan($ctx, $basis, $policy), $basis, $c);
    }

    public function pin(int $userId, int $season, string $value): bool
    {
        if ($value === self::AUTO) {
            $this->pins->clear($userId, $season);

            return true;
        }
        $objective = SeasonObjective::normalise($value);
        if (!in_array($objective, self::PINNABLE, true)) {
            return false;
        }

        $this->pins->save($userId, $season, $objective);

        return true;
    }

    public function choose(int $userId, int $season, int $race, string $item, string $choice): bool
    {
        if (
            $this->choices === null
            || !in_array($item, ['testing', 'training'], true)
            || !in_array($choice, ['do', 'skip', 'clear'], true)
            || $season < 1
            || $race < 1
            || $race > SeasonObjective::RACES
        ) {
            return false;
        }

        if ($choice === 'clear') {
            $this->choices->clear($userId, $season, $race, $item);
        } else {
            $this->choices->save($userId, $season, $race, $item, $choice);
        }

        return true;
    }

    /**
     * @return array{testing?: string, training?: string}
     */
    public function choicesFor(int $userId, int $season, int $race): array
    {
        return $this->choices?->choices($userId, $season, $race) ?? [];
    }

    /**
     * What the engineer knows before it plans: the race, the cash, the stored season and the objective in force.
     *
     * @param array<string, mixed> $c
     * @return Context
     */
    private function context(int $userId, array $c): array
    {
        $office = self::arr($c['office'] ?? null);
        $menu = self::arr($c['menu'] ?? null);
        $season = (int) ($office['seasonNb'] ?? 0);
        $raceNb = (int) ($office['raceNb'] ?? 0);
        $cash = self::cash($c);

        $standingsRow = $this->standingsRow($userId, $season, $raceNb);
        $weekends = $this->weekends->forSeason($userId, $season);
        $seasonLedger = $this->ledger->forSeason($userId, $season);

        $racesRun = $standingsRow === null ? max(0, $raceNb - 1) : max(0, (int) $standingsRow['race'] - 1);
        // Before race 1 there is nothing for this season yet; last season's final race is the best estimate.
        $outlookLedger = $seasonLedger;
        $outlookWeekends = $weekends;
        if ($seasonLedger === []) {
            $outlookLedger = $this->ledger->forSeason($userId, $season - 1);
            if ($weekends === []) {
                $outlookWeekends = $this->weekends->forSeason($userId, $season - 1);
            }
        }
        $racesLeft = max(0, SeasonObjective::RACES - $racesRun);
        $outlook = self::atSignedTyreCost(
            CashOutlook::from(
                $outlookWeekends === [] ? null : $outlookWeekends[array_key_last($outlookWeekends)],
                $outlookLedger,
                $cash,
                $racesLeft,
            ),
            self::signedTyreCost($c),
            $outlookLedger,
            $racesLeft,
        );

        $proposal = SeasonObjective::propose(
            $standingsRow,
            $raceNb,
            $outlook,
            $cash,
            GroupStanding::car(self::arr($c['money_levels'] ?? null), $menu)['rank'] ?? null,
            ($c['division'] ?? null) === 'Elite',
            ($c['division'] ?? null) === 'Rookie',
        );

        return [
            'season' => $season,
            'raceNb' => $raceNb,
            'cash' => $cash,
            'weekends' => $weekends,
            'ledger' => $seasonLedger,
            'outlook_ledger' => $outlookLedger,
            'outlook' => $outlook,
            'objective' => SeasonObjective::inForce($proposal, $this->pins->find($userId, $season)),
        ];
    }

    /**
     * @param array<string, mixed> $c
     */
    private static function cash(array $c): int
    {
        // Every sync fetches the office; the menu only once a season.
        return (int) (self::arr($c['office'] ?? null)['cash'] ?? self::arr($c['menu'] ?? null)['cash'] ?? 0);
    }

    /**
     * What the signed supplier charges a race, from the feed. Null when unsigned, or the feed doesn't carry
     * the supplier with a price.
     *
     * @param array<string, mixed> $c
     */
    private static function signedTyreCost(array $c): ?int
    {
        $signed = self::arr($c['office'] ?? null)['tyreSupplierId'] ?? null;
        if (!is_numeric($signed) || (int) $signed === 0) {
            return null;
        }
        foreach (self::list(self::arr($c['tyre_suppliers'] ?? null)['suppliers'] ?? null) as $supplier) {
            $id = $supplier['id'] ?? null;
            $cost = $supplier['cost'] ?? null;
            if (is_numeric($id) && (int) $id === (int) $signed) {
                return is_numeric($cost) && (int) $cost > 0 ? (int) $cost : null;
            }
        }

        return null;
    }

    /**
     * The outlook with every race's tyre line at the signed price. Before race 1 the ledger still carries
     * last season's supplier, and the contract is paid from the first race on.
     *
     * @param array{known: bool, committed: ?int, income: ?int, free_cash: ?int, end_committed: ?int} $outlook
     * @param list<array<string, mixed>> $ledger
     * @return array{known: bool, committed: ?int, income: ?int, free_cash: ?int, end_committed: ?int}
     */
    private static function atSignedTyreCost(array $outlook, ?int $cost, array $ledger, int $racesLeft): array
    {
        if ($cost === null || !$outlook['known']) {
            return $outlook;
        }
        $extra = $cost - CashOutlook::latestTyreCost($ledger);

        return [
            'known' => true,
            'committed' => (int) $outlook['committed'] + $extra,
            'income' => $outlook['income'],
            'free_cash' => (int) $outlook['free_cash'] - $extra * $racesLeft,
            'end_committed' => (int) $outlook['end_committed'] - $extra * $racesLeft,
        ];
    }

    /**
     * @param Context $ctx
     * @param Plan $plan
     * @param PlanBasis $basis
     * @param array<string, mixed> $c
     */
    private function swapSeasonFrom(array $ctx, array $plan, array $basis, array $c): SwapSeason
    {
        return new SwapSeason(
            $ctx['objective']['objective'] ?? SeasonObjective::STAY,
            $plan,
            $basis['rounds'],
            $this->demands($c),
            $basis['parts'],
            $basis['prices'],
            $basis['driverFactor'],
            $ctx['cash'],
            (int) $ctx['outlook']['committed'],
            (int) $ctx['outlook']['income'],
            StrengthFloor::fromGroup(
                GroupStanding::car(self::arr($c['money_levels'] ?? null), self::arr($c['menu'] ?? null)),
            ),
        );
    }

    /**
     * The season-long level for each of $names swapped before the next race, judged at the plan's CTR.
     * A part the picker can't judge is left out.
     *
     * @param list<string> $names
     * @param Plan $plan
     * @param array<string, mixed> $c
     * @return list<SwapPick>
     */
    private function picksFor(array $names, array $plan, SwapSeason $season, array $c): array
    {
        $parts = $this->wearParts($c);
        $trackPha = self::pha($c['track_pha'] ?? null);
        $carPha = self::pha($c['car_pha'] ?? null);
        if ($parts === null || $trackPha === null || $carPha === null) {
            return [];
        }

        $adviseParts = [];
        foreach ($parts as $label => $part) {
            $start = (int) $part['start'];
            $end = $this->endAt($part, $season->driverFactor, $plan['ctr']);
            $adviseParts[(string) $label] = [
                'level' => (int) $part['level'],
                'start' => $start,
                'est' => $end - $start,
                'end' => $end,
                'track_base' => (float) $part['track_base'],
            ];
        }
        return $this->picksAt(
            self::flagged($names, $adviseParts),
            $adviseParts,
            $season,
            $trackPha,
            $carPha,
            $c,
        );
    }

    /**
     * The swap advisor's season picks for $flagged, judged at the plan's CTR over $season.
     *
     * @param list<array{part: string, level: int, start: int, est: float, end: float}> $flagged
     * @param array<string, array{level: int, start: int, est: float, end: float, track_base: float}> $adviseParts
     * @param array{power: float, handling: float, acceleration: float} $trackPha
     * @param array{power: float, handling: float, acceleration: float} $carPha
     * @param array<string, mixed> $c
     * @return list<SwapPick>
     */
    private function picksAt(
        array $flagged,
        array $adviseParts,
        SwapSeason $season,
        array $trackPha,
        array $carPha,
        array $c,
    ): array {
        if ($flagged === []) {
            return [];
        }

        $advice = $this->swapAdvisor->advise(
            $flagged,
            self::arr($c['car'] ?? null),
            $adviseParts,
            self::arr($c['driver'] ?? null),
            $trackPha,
            $carPha,
            $season->plan['ctr'],
            self::ints($c['group_car_levels'] ?? null),
            $season->cash,
            self::floats($c['training_wear'] ?? null),
            $season,
        );

        $picks = [];
        foreach ($advice as $label => $entry) {
            $pick = $entry['season_pick'];
            if ($pick !== null) {
                $picks[] = [
                    'part' => (string) $label,
                    'level' => $pick['level'],
                    'kind' => $pick['kind'],
                    'cost' => $pick['cost'],
                    'net_cost' => $pick['net_cost'],
                    'reason' => $pick['reason'],
                    'start' => $pick['start'],
                    'price' => $pick['price'],
                ];
            }
        }

        return $picks;
    }

    /**
     * @param list<string> $names
     * @param array<string, array{level: int, start: int, est: float, end: float, track_base: float}> $parts
     * @return list<array{part: string, level: int, start: int, est: float, end: float}>
     */
    private static function flagged(array $names, array $parts): array
    {
        $flagged = [];
        foreach ($names as $name) {
            $part = $parts[$name] ?? null;
            if ($part !== null) {
                $flagged[] = [
                    'part' => $name,
                    'level' => $part['level'],
                    'start' => $part['start'],
                    'est' => $part['est'],
                    'end' => $part['end'],
                ];
            }
        }

        return $flagged;
    }

    /**
     * @param InForce $objective
     * @param array{known: bool, committed: ?int, income: ?int, free_cash: ?int, end_committed: ?int} $outlook
     * @param list<Round> $rounds
     * @param ?Plan $plan
     * @param array<string, mixed> $c
     * @param bool $carLocked This race's car update is spent: nothing can be swapped before the race.
     * @param EnergyCap $energy
     * @param array{testing?: string, training?: string} $choices
     */
    private function inputs(
        array $objective,
        array $outlook,
        int $cash,
        int $raceNb,
        array $rounds,
        ?array $plan,
        array $c,
        bool $carLocked,
        array $energy,
        array $choices,
        int $season,
    ): EngineerInputs {
        $parts = $this->wearParts($c);
        /** @var ?WearAdvice $wearAdvice */
        $wearAdvice = self::arrOrNull($c['wear_advice'] ?? null);
        $driver = self::arr($c['driver'] ?? null);
        $driverFactor = $this->wear->driverFactor($driver);
        $forced = array_column($wearAdvice['swap'] ?? [], 'part');
        $weather = self::arrOrNull($c['weather'] ?? null);
        $stale = (bool) ($c['setup_stale'] ?? false);
        $track = (string) ($c['track_name'] ?? '') === '' ? null : $this->tracks->findByName((string) $c['track_name']);
        $office = self::arr($c['office'] ?? null);

        $ceiling = $parts === null
            ? null
            : $this->ceiling->for($parts, $driverFactor, WearAdvisorService::THRESHOLD_SWAP, $carLocked);
        $stuckSmoke = $carLocked && $parts !== null
            ? $this->ceiling->pastLimitAtZero($parts, $driverFactor, WearAdvisorService::THRESHOLD_SWAP)
            : [];
        $testingTrack = $c['testing_track'] ?? null;
        $car = self::arr($c['car'] ?? null);

        $targets = self::list($c['testing_targets'] ?? null);
        $picks = self::list($c['training_picks'] ?? null);

        /** @var ?array{name: string, score: float, cost: int, gains: array<string, float>} $pick */
        $pick = $picks[0] ?? null;
        /** @var ?TestingTarget $target */
        $target = $targets[0] ?? null;

        return new EngineerInputs(
            objective: $objective,
            outlook: $outlook,
            cash: $cash,
            currentCtr: (int) ($c['ctr'] ?? 0),
            ctrCeiling: $ceiling,
            pushAdvice: $this->pushAdvice($c, $rounds, $raceNb),
            wearAdvice: $wearAdvice,
            forcedSwapCost: $this->forcedSwapCost($forced, self::arrOrNull($c['swap_plan'] ?? null)),
            trainingPick: $pick,
            testingTarget: $target,
            testingOpen: (bool) ($c['testing_open'] ?? false),
            testingTrack: is_string($testingTrack) && $testingTrack !== '' ? $testingTrack : null,
            testingCost: RaceEngineer::TESTING_SESSION_COST,
            risk: $this->risk($driver, $track, $weather, $stale),
            boostLitres: $this->boostLitres($track, $weather, $stale),
            weatherStale: $stale,
            plan: $plan,
            staffStanding: GroupStanding::staff(
                self::arr($c['group_staff'] ?? null),
                self::arr($c['menu'] ?? null),
                $objective['objective'] ?? SeasonObjective::STAY,
            ),
            carStanding: GroupStanding::car(self::arr($c['money_levels'] ?? null), self::arr($c['menu'] ?? null)),
            testingLaps: (int) ($c['training_laps'] ?? 0),
            division: self::stringOrNull($c['division'] ?? null),
            carUpdated: $carLocked,
            tested: OfficeFlags::done($office, 'doneTesting'),
            driverTrained: OfficeFlags::done($office, 'doneDriTrain'),
            stuckSmoke: $stuckSmoke,
            energyCap: $energy['cap'],
            energyEstimate: $energy['estimate'],
            energyStart: $energy['start'],
            trainingChoice: $choices['training'] ?? null,
            testingChoice: $choices['testing'] ?? null,
            season: $season,
            race: $raceNb,
        );
    }

    /**
     * Which supplier to sign for the season, while GPRO's contract window is open on a Pro, Master or Elite team
     * that has not signed. Null once the season is over, when the window is shut or anything the advice reads
     * fails.
     *
     * @param array<string, mixed> $c
     * @param ?Plan $plan
     * @param Context $ctx
     * @return ?TyreAdvice
     */
    private function tyreAdvice(array $c, ?array $plan, array $ctx): ?array
    {
        $division = (string) ($c['division'] ?? '');
        $feed = self::arr($c['tyre_suppliers'] ?? null);
        $signed = self::arr($c['office'] ?? null)['tyreSupplierId'] ?? null;
        $suppliers = self::list($feed['suppliers'] ?? null);
        // The feed is cached across managers, so its own canSignContract may be someone else's: only this
        // manager's office says whether they have signed.
        if (
            $ctx['objective']['over']
            || !in_array($division, self::TYRE_DIVISIONS, true)
            || !is_numeric($signed)
            || (int) $signed !== 0
            || $suppliers === []
        ) {
            return null;
        }

        try {
            $names = array_map(
                static fn (array $row): ?string => self::stringOrNull($row['track_name'] ?? null),
                self::list($c['season_calendar'] ?? null),
            );
            $talent = self::arr($c['driver'] ?? null)['talent'] ?? null;
            $baseline = $this->idealPilot->getIdealPilot($division)['stats']['Talent'] ?? null;

            return TyreSupplierAdvisor::advise(
                $suppliers,
                $division,
                TyreSupplierAdvisor::races(
                    $names,
                    $this->telemetry->trackConditions(),
                    $this->tyreWear($names),
                ),
                is_numeric($talent) ? (int) $talent : null,
                is_numeric($baseline) ? (int) $baseline : null,
                $plan,
                CashOutlook::latestTyreCost($ctx['outlook_ledger']),
            );
        } catch (\Throwable $e) {
            error_log('[Engineer] ' . $e::class);

            return null;
        }
    }

    /**
     * What the advised pick costs a race over today's tyre line; 0 without advice checked against a plan.
     *
     * @param ?TyreAdvice $advice
     * @param list<array<string, mixed>> $ledger
     */
    private static function tyrePickExtra(?array $advice, array $ledger): int
    {
        return $advice === null || !$advice['finances_known']
            ? 0
            : $advice['cost'] - CashOutlook::latestTyreCost($ledger);
    }

    /**
     * The plan with every race paying $extra more for tyres, and the envelope what that leaves: the contract
     * is signed before anything the envelope buys.
     *
     * @param Plan $plan
     * @return Plan
     */
    private static function payingTyreExtra(array $plan, int $extra): array
    {
        if ($extra === 0) {
            return $plan;
        }
        foreach ($plan['rows'] as $i => $row) {
            $plan['rows'][$i]['balance'] = $row['balance'] - $extra * ($i + 1);
        }
        $plan['envelope'] = max(0, CtrPlanner::headroom($plan['rows'], $plan['cushion'], $plan['target']));

        return $plan;
    }

    /**
     * The calendar's tracks on a numeric scale, keyed by base name. A track the database doesn't know, or whose
     * wear label isn't one we scale, is left out.
     *
     * @param list<?string> $names
     * @return array<string, float>
     */
    private function tyreWear(array $names): array
    {
        $wear = [];
        foreach ($names as $name) {
            if ($name === null) {
                continue;
            }
            $scale = self::TYRE_WEAR_SCALE[(string) ($this->tracks->findByName($name)['tyre_wear'] ?? '')] ?? null;
            if ($scale !== null) {
                $wear[TrackName::base($name)] = (float) $scale;
            }
        }

        return $wear;
    }

    /**
     * @param TyreAdvice $advice
     * @return TyrePanel
     */
    private static function tyrePanel(array $advice): array
    {
        return [
            ...$advice,
            'figure' => Money::short($advice['cost']) . ' a race',
            'rows' => array_map(static fn (array $row): array => [
                ...$row,
                'per_race' => Money::short($row['cost']),
                'season' => Money::short($row['season_cost']),
            ], $advice['rows']),
        ];
    }

    /**
     * Keep, extend, replace or hire, from the stored driver profile, the cached market and the card's plan. Null
     * without a recruitment service, or when anything the advice reads fails (a driver under contract with no
     * usable profile included).
     *
     * @param array<string, mixed> $c
     * @param ?EngineerInputs $card the card's final inputs; null on the no-driver screen, which has neither a
     *                              driver nor a plan
     * @return ?DriverCall
     */
    private function driverCall(array $c, ?EngineerInputs $card, int $cash, bool $promote): ?array
    {
        $recruitment = $this->recruitment;
        if ($recruitment === null) {
            return null;
        }

        try {
            $division = self::stringOrNull($c['division'] ?? null);
            $division = $division === '' ? null : $division;
            $office = self::arr($c['office'] ?? null);
            $driver = $card === null ? null : self::currentDriver($recruitment, $c, $division);
            ['ids' => $trackIds, 'left' => $seasonRacesLeft] = self::racesAhead($c, (int) ($office['raceNb'] ?? 0));
            $rows = self::arr($c['driver_market'] ?? null)['rows'] ?? null;
            $rows = is_array($rows) ? array_values($rows) : null;

            $advice = DriverHiringAdvisor::advise(
                $driver['current'] ?? null,
                $rows !== null && $division !== null ? self::rated($recruitment, $rows, $division) : null,
                $rows !== null,
                $trackIds,
                $division === null ? null : $recruitment->capFor($division),
                self::groupDriverSalary($c),
                $card === null ? null : self::hiringMoney($card),
                $cash,
                $promote,
                $seasonRacesLeft,
            );
            $line = RaceEngineer::driverLine($advice);

            return [
                'advice' => $advice,
                'line' => $line,
                'panel' => self::driverPanel($advice, $line, $driver['overall'] ?? null, $division),
            ];
        } catch (\Throwable $e) {
            error_log('[Engineer] ' . $e::class);

            return null;
        }
    }

    /**
     * What the season plan leaves for a driver change; null without a plan.
     *
     * @return ?HiringMoney
     */
    private static function hiringMoney(EngineerInputs $inputs): ?array
    {
        $plan = $inputs->plan;

        return $plan === null ? null : [
            'rows' => $plan['rows'],
            'cushion' => $plan['cushion'],
            'target' => $plan['target'],
            'envelope' => max(0, (int) $plan['envelope']),
            'spare' => RaceEngineer::spare($inputs),
        ];
    }

    /**
     * The driver under contract, from the stored profile; the office's salary and races left win over the
     * profile's. Null when the office says there is none.
     *
     * @param array<string, mixed> $c
     * @return ?array{current: Current, overall: string}
     * @throws \UnexpectedValueException a driver is under contract but the profile is not usable, or the overall,
     *                                   salary or races left is known from neither the office nor the profile
     */
    private static function currentDriver(RecruitmentService $recruitment, array $c, ?string $division): ?array
    {
        $office = self::arr($c['office'] ?? null);
        if (array_key_exists('driId', $office)) {
            $id = is_scalar($office['driId']) ? trim((string) $office['driId']) : '';
            if ($id === '' || $id === '0') {
                return null;
            }
        }
        if (!is_array($c['driver_profile'] ?? null)) {
            throw new \UnexpectedValueException('Driver profile missing');
        }
        $profile = self::arr($c['driver_profile']);

        $rating = null;
        if ($division !== null) {
            try {
                $rating = $recruitment->rateProfile($profile, $division);
            } catch (NoBaselineException) {
                $rating = null;
            }
        }
        $overall = $profile['overall'] ?? null;
        $salary = self::intOrNull($office['driSalary'] ?? null) ?? self::intOrNull($profile['salary'] ?? null);
        $racesLeft = self::intOrNull($office['driRacesLeft'] ?? null)
            ?? self::intOrNull($profile['racesLeft'] ?? null);
        if (!is_numeric($overall) || $salary === null || $racesLeft === null) {
            throw new \UnexpectedValueException('Driver contract figures missing');
        }
        $name = $profile['driName'] ?? null;
        $name = is_string($name) ? html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
        $retiring = $profile['retiring'] ?? null;
        $blocked = $profile['errorMsgExtend'] ?? null;
        $overallText = number_format(round((float) $overall, 1), 1, '.', '');

        return [
            'current' => [
                'name' => $name,
                'rating' => $rating,
                // Up, so a fraction above the cap counts as over it.
                'oa' => (int) ceil((float) $overall),
                'age' => self::intOrNull($profile['age'] ?? null) ?? 0,
                'salary' => $salary,
                'races_left' => $racesLeft,
                'retiring' => is_numeric($retiring) && (float) $retiring > 0,
                'renew_fee' => self::intOrNull($profile['seventeenRaceRenewFee'] ?? null),
                'renew_salary' => self::intOrNull($profile['seventeenRaceRenewSalary'] ?? null),
                'extension_blocked' => is_string($blocked) && trim($blocked) !== '',
            ],
            'overall' => str_ends_with($overallText, '.0') ? substr($overallText, 0, -2) : $overallText,
        ];
    }

    /**
     * The market rows rated for $division; null when the division has no baseline to rate against.
     *
     * @param list<mixed> $rows
     * @return ?list<array<string, mixed>>
     */
    private static function rated(RecruitmentService $recruitment, array $rows, string $division): ?array
    {
        try {
            return $recruitment->rated($rows, $division);
        } catch (NoBaselineException) {
            return null;
        }
    }

    /**
     * The track ids of the season's races from the next one on, and how many there are. Without a calendar the
     * count comes from the race number and no track is known.
     *
     * @param array<string, mixed> $c
     * @return array{ids: list<int>, left: int}
     */
    private static function racesAhead(array $c, int $raceNb): array
    {
        $calendar = self::list($c['season_calendar'] ?? null);
        if ($calendar === []) {
            return ['ids' => [], 'left' => max(0, SeasonObjective::RACES - $raceNb + 1)];
        }

        $ids = [];
        $left = 0;
        foreach ($calendar as $row) {
            if ((self::intOrNull($row['race'] ?? null) ?? 0) < $raceNb) {
                continue;
            }
            $left++;
            $id = self::intOrNull($row['track_id'] ?? null);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return ['ids' => $ids, 'left' => $left];
    }

    /**
     * The group's average driver salary, from the managers whose salary is known; null when none is.
     *
     * @param array<string, mixed> $c
     */
    private static function groupDriverSalary(array $c): ?int
    {
        $salaries = [];
        foreach (self::list(self::arr($c['group_staff'] ?? null)['managers'] ?? null) as $manager) {
            $salary = $manager['driSal'] ?? null;
            if (is_numeric($salary) && (float) $salary > 0) {
                $salaries[] = (float) $salary;
            }
        }

        return $salaries === [] ? null : (int) round(array_sum($salaries) / count($salaries));
    }

    /**
     * @param DriverAdvice $advice
     * @param Line $line
     * @param ?string $overall the current driver's overall as GPRO gives it
     * @return DriverPanel
     */
    private static function driverPanel(array $advice, array $line, ?string $overall, ?string $division): array
    {
        $current = $advice['current'];
        $extension = $advice['extension'];
        $average = $advice['group_average'];
        $hiring = in_array($advice['verdict'], ['hire', 'replace'], true);
        $shortlist = $hiring ? $advice['shortlist'] : [];

        $out = [];
        foreach ($hiring ? self::OUT_LABELS : [] as $key => $label) {
            if ($advice['out'][$key] > 0) {
                $out[] = ['label' => $label, 'count' => $advice['out'][$key]];
            }
        }
        $notes = [];
        if ($shortlist !== []) {
            $notes[] = self::OFFER_NOTE;
        }
        if (!$advice['market_known']) {
            $notes[] = 'Driver market unavailable: nobody was compared';
        }
        if ($average === null) {
            $notes[] = 'Group salaries unknown: salary line not applied';
        }
        if (!$advice['finances_known']) {
            $notes[] = 'Finances unknown: only your cash balance was checked';
        }

        return [
            'verdict' => $advice['verdict'],
            'reason' => $line['reason'],
            'answer' => $line['answer'],
            'figure' => $line['figure'],
            'current' => $current === null ? null : [
                'name' => $current['name'],
                'rating' => $current['rating'] === null ? null : number_format($current['rating'], 1),
                'overall' => $overall ?? (string) $current['oa'],
                // Elite has no cap; its configured value only keeps every Elite driver inside it.
                'cap' => $division === 'Elite' ? null : $advice['cap'],
                'age' => $current['age'],
                'salary' => Money::short($current['salary']),
                'races_left' => $current['races_left'],
            ],
            'group_average' => $average === null ? null : Money::short($average),
            'extension' => $extension === null ? null : [
                'fee' => Money::short($extension['fee']),
                'salary' => Money::short($extension['salary']),
                'exact' => $extension['exact'],
            ],
            'shortlist' => array_map(static fn (array $pick): array => [
                'name' => $pick['name'],
                'rating' => number_format($pick['rating'], 1),
                'gain' => $pick['gain'] === null
                    ? null
                    : ($pick['gain'] > 0 ? '+' : '') . number_format($pick['gain'], 1),
                'salary' => Money::short($pick['salary']),
                'to_sign' => Money::short($pick['one_off']),
                'age' => $pick['age'],
                'overall' => $pick['oa'],
                'favourites' => $pick['favourites'],
                'fits' => match ($pick['fits']) {
                    'plan' => 'In the plan',
                    'cuts' => 'Cuts ' . Money::short($pick['cut']) . ' from upgrades, training and testing',
                    'unknown' => 'Finances unknown',
                },
            ], $shortlist),
            'out' => $out,
            'notes' => $notes,
        ];
    }

    /**
     * @param array{level: int, start: int|float, track_base: int|float, training?: int|float} $part
     */
    private function endAt(array $part, float $driverFactor, int $ctr): float
    {
        $raceWear = $this->wear->raceWear((float) $part['track_base'], (int) $part['level'], $driverFactor, $ctr);

        return (float) $part['start'] + (float) ($part['training'] ?? 0) + round($raceWear, 1);
    }

    /**
     * @param array<string, mixed> $c
     * @return ?array<string, array{level: int, start: int|float, track_base: int|float, training?: int|float}>
     */
    private function wearParts(array $c): ?array
    {
        $wear = $c['wear'] ?? null;
        if (!is_array($wear) || isset($wear['error']) || !is_array($wear['parts'] ?? null)) {
            return null;
        }

        /** @var array<string, array{level: int, start: int|float, track_base: int|float, training?: int|float}> $parts */
        $parts = $wear['parts'];

        return $parts;
    }

    /**
     * What the plan is built from, shared with the upgrade ranking so both price the same races, parts and
     * prices. Null unless the budget can be forecast: costs known, parts known, a race left, the calendar's
     * first remaining round being the next race, and its track wear known for every part. Otherwise the
     * engineer falls back to the safety CTR.
     *
     * @param array{known: bool, committed: ?int, income: ?int, free_cash: ?int, end_committed: ?int} $outlook
     * @param list<Round> $rounds
     * @param array<string, mixed> $c
     * @return ?PlanBasis
     */
    private function planBasis(array $outlook, int $raceNb, array $rounds, array $c): ?array
    {
        $parts = $this->wearParts($c);
        if (!$outlook['known'] || $parts === null || $raceNb < 1 || $raceNb > SeasonObjective::RACES) {
            return null;
        }

        $remaining = array_values(array_filter(
            $rounds,
            static fn (array $round): bool => $round['race'] >= $raceNb,
        ));
        // The first round must be the next race itself: a calendar gap would price the wrong track.
        if (($remaining[0]['race'] ?? null) !== $raceNb) {
            return null;
        }
        $nextBase = $remaining[0]['base'];
        if ($nextBase === null || array_diff_key($parts, $nextBase) !== []) {
            return null;
        }

        return [
            'rounds' => $remaining,
            'parts' => $parts,
            'prices' => $this->planner->partPrices(self::arr($c['car'] ?? null)),
            'driverFactor' => $this->wear->driverFactor(self::arr($c['driver'] ?? null)),
        ];
    }

    /**
     * @param Context $ctx
     * @param PlanBasis $basis
     * @return Plan
     */
    private function plan(array $ctx, array $basis, CtrPolicy $policy, bool $carLocked = false): array
    {
        return $this->ctrPlanner->plan(
            $ctx['objective']['objective'] ?? SeasonObjective::STAY,
            $basis['rounds'],
            $basis['parts'],
            $basis['prices'],
            $basis['driverFactor'],
            $ctx['cash'],
            (int) $ctx['outlook']['committed'],
            (int) $ctx['outlook']['income'],
            SeasonStart::cash($ctx['ledger'], $ctx['weekends']),
            $carLocked,
            $policy,
        );
    }

    /**
     * The highest CTR the driver's energy allows at the next race, from the office's energy and Q2 flag. A model
     * that fails to load falls back to the estimate table rather than costing the card its CTR line.
     *
     * @param Context $ctx
     * @param list<Round> $rounds
     * @param array<string, mixed> $c
     * @return EnergyCap
     */
    private function energyCap(array $ctx, array $rounds, array $c): array
    {
        $office = self::arr($c['office'] ?? null);
        $division = self::stringOrNull($c['division'] ?? null);
        $driEnergy = is_numeric($office['driEnergy'] ?? null) ? (int) $office['driEnergy'] : null;
        $q2Done = OfficeFlags::done($office, 'doneQ2');
        $stamina = (int) (self::arr($c['driver'] ?? null)['stamina'] ?? 0);

        $next = null;
        foreach ($rounds as $round) {
            if ($round['race'] === $ctx['raceNb']) {
                $next = $round['track_name'];
                break;
            }
        }
        $next ??= self::stringOrNull($c['track_name'] ?? null);
        $laps = $next === null ? 0 : (int) ($this->tracks->findByName($next)['laps'] ?? 0);

        try {
            $model = $this->ctrModel->energy($division, $ctx['season']);
        } catch (\Throwable $e) {
            error_log('[Engineer] ' . $e::class);
            $model = EnergyModel::fallback();
        }

        return $model->cap($driEnergy, $q2Done, $laps, $stamina, $division);
    }

    /**
     * What a CTR point is worth over the rest of the season: the lap time it buys back on each remaining track,
     * in places at the division's gap per place, priced at the division's prize per place. A round whose track
     * row is missing or has no lap time adds nothing.
     *
     * @param Context $ctx
     * @param PlanBasis $basis
     * @param array<string, mixed> $c
     * @param EnergyCap $energy
     */
    private function policy(array $ctx, array $basis, array $c, array $energy): CtrPolicy
    {
        $division = self::stringOrNull($c['division'] ?? null);
        $gap = $this->placeGap($ctx['season']);

        $places = 0.0;
        $placesNext = null;
        $paceKnown = false;
        foreach ($basis['rounds'] as $round) {
            $track = $round['track_name'] === null ? null : $this->tracks->findByName($round['track_name']);
            $laps = (int) ($track['laps'] ?? 0);
            $secondsPerPlace = $gap->secondsPerPlace($division, $laps);
            if ($track === null || $laps <= 0 || $secondsPerPlace <= 0.0) {
                continue;
            }
            $seconds = CtrPace::secondsPerPointPerLap(
                (float) ($track['lap_length'] ?? 0.0),
                (float) ($track['avg_speed'] ?? 0.0),
                $this->ctrGainFraction,
            ) * $laps;
            if ($seconds <= 0.0) {
                continue;
            }
            $perPoint = $seconds / $secondsPerPlace;
            $places += $perPoint;
            $paceKnown = true;
            // The places a race quote the first race with pace figures: the next race may have none.
            $placesNext ??= $perPoint;
        }

        $numbers = $ctx['objective']['numbers'];
        $perPlace = PrizeTable::perPlace($division);

        return new CtrPolicy(
            $energy['cap'],
            $energy['estimate'],
            $energy['start'],
            (int) round($places * $perPlace),
            $placesNext ?? 0.0,
            // Without a prize per place (no or unknown division) the pace is worth nothing to weigh.
            $paceKnown && $perPlace > 0,
            CtrPolicy::safetyFor($numbers['projected_pos'], $numbers['relegation_pos']),
        );
    }

    private function placeGap(int $season): PlaceGap
    {
        try {
            return $this->ctrModel->placeGap($season);
        } catch (\Throwable $e) {
            error_log('[Engineer] ' . $e::class);

            return PlaceGap::fallback();
        }
    }

    /**
     * When each forced or stuck part reaches 100 % at the CTR the card shows, what its smoke costs in places
     * and in an expected break, and what fitting it a race later saves over the season. A failure costs the
     * card only its smoke quotes.
     *
     * @param Context $ctx
     * @param ?PlanBasis $basis
     * @param array<string, mixed> $c
     * @return array<string, SmokeQuote>
     */
    private function smokeQuotes(array $ctx, EngineerInputs $inputs, ?array $basis, array $c): array
    {
        try {
            return $this->quoteSmoke($ctx, $inputs, $basis, $c);
        } catch (\Throwable $e) {
            error_log('[Engineer] ' . $e::class);

            return [];
        }
    }

    /**
     * @param Context $ctx
     * @param ?PlanBasis $basis
     * @param array<string, mixed> $c
     * @return array<string, SmokeQuote>
     */
    private function quoteSmoke(array $ctx, EngineerInputs $inputs, ?array $basis, array $c): array
    {
        $parts = $this->wearParts($c);
        $name = self::stringOrNull($c['track_name'] ?? null);
        $track = $name === null ? null : $this->tracks->findByName($name);
        $laps = (int) ($track['laps'] ?? 0);
        if ($parts === null || $track === null || $laps <= 0) {
            return [];
        }

        $plan = $inputs->plan;
        /** @var list<array{part: string}> $forced */
        $forced = $plan === null ? $inputs->wearAdvice['swap'] ?? [] : $plan['rows'][0]['replaced'];
        $names = array_values(array_unique([...array_column($forced, 'part'), ...$inputs->stuckSmoke]));
        $ctr = $plan === null
            ? min($inputs->ctrCeiling ?? $inputs->currentCtr, $inputs->energyCap ?? 100)
            : $plan['ctr'];

        $division = self::stringOrNull($c['division'] ?? null);
        $pace = CtrPace::secondsPerPointPerLap(
            (float) ($track['lap_length'] ?? 0.0),
            (float) ($track['avg_speed'] ?? 0.0),
            $this->ctrGainFraction,
        );
        $secondsPerPlace = $this->placeGap($ctx['season'])->secondsPerPlace($division, $laps);
        $perPlace = PrizeTable::perPlace($division);
        $pos = $ctx['objective']['numbers']['projected_pos'] ?? null;
        $prize = $pos === null ? 0 : PrizeTable::at($division, $pos);
        $priced = $pace > 0 && $secondsPerPlace > 0 && $perPlace > 0 && $prize > 0;

        $driverFactor = $this->wear->driverFactor(self::arr($c['driver'] ?? null));
        $advice = self::arr($c['swap_advice'] ?? null);
        $last = $basis !== null && count($basis['rounds']) === 1;
        $quotes = [];
        foreach ($names as $part) {
            $row = $parts[$part] ?? null;
            if ($row === null) {
                continue;
            }
            $start = (float) $row['start'] + (float) ($row['training'] ?? 0);
            $raceWear = $this->wear->raceWear((float) $row['track_base'], (int) $row['level'], $driverFactor, $ctr);
            $risk = SmokeRisk::for($start, round($raceWear, 1), $laps);
            if ($risk === null) {
                continue;
            }
            $quotes[$part] = [
                'risk' => $risk,
                'start' => $start,
                'price' => $priced ? SmokeCost::price($risk, $pace, $secondsPerPlace, $perPlace, $prize) : null,
                'saving' => $basis === null || !isset($basis['parts'][$part]) ? null : SmokeDeferral::saving(
                    new SeasonBudget($this->wear),
                    $basis['rounds'],
                    $part,
                    $basis['parts'][$part],
                    $basis['prices'][$part] ?? null,
                    $basis['driverFactor'],
                    $ctr,
                ),
                'cost' => self::recommendedCost($advice[$part] ?? null),
                'last' => $last,
            ];
        }

        return $quotes;
    }

    /**
     * What the Cockpit's recommended replacement for a part costs; null when it has none.
     */
    private static function recommendedCost(mixed $advice): ?int
    {
        foreach (self::list(self::arr($advice)['options'] ?? null) as $option) {
            if (($option['recommended'] ?? false) === true && is_numeric($option['cost'] ?? null)) {
                return (int) $option['cost'];
            }
        }

        return null;
    }

    /**
     * The plan with its upgrade ranking. What the card's season picks, optional swaps, training and testing take
     * out of the envelope ($inputs carries the plan and the picks) is paid before any upgrade, and a part with
     * a pick is not ranked. A failing ranking costs the card only its upgrades line.
     *
     * @param Plan $plan
     * @param InForce $objective
     * @param array{known: bool, committed: ?int, income: ?int, free_cash: ?int, end_committed: ?int} $outlook
     * @param PlanBasis $basis
     * @param array<string, mixed> $c
     * @return Plan
     */
    private function withUpgrades(
        array $plan,
        EngineerInputs $inputs,
        array $objective,
        array $outlook,
        int $cash,
        array $basis,
        array $c,
    ): array {
        try {
            $plan['upgrades'] = $this->ranker->rank(
                $plan,
                $basis['rounds'],
                $this->demands($c),
                $basis['parts'],
                $basis['prices'],
                array_diff_key(
                    $this->planner->upgradeOptions(self::arr($c['car'] ?? null)),
                    array_flip(array_column([...$inputs->swapPicks, ...$inputs->unlockPicks], 'part')),
                ),
                $basis['driverFactor'],
                $cash,
                (int) $outlook['committed'],
                (int) $outlook['income'],
                $plan['envelope'] - RaceEngineer::upgradeBudget($inputs),
            );
        } catch (\Throwable $e) {
            error_log('[Engineer] ' . $e::class);
        }

        return $plan;
    }

    /**
     * The plan again on the car as it will be once the manager buys what the card advises this race: each
     * replaced part at its pick's level (or its own level without one), every unlock swap, and the recommended
     * upgrade, each new at 0 % except a free downgrade, which keeps the wear GPRO reports for it. Planned with
     * the update spent, as the next render will plan it, so the CTR holds when the advice is followed. The CTR,
     * its targets and the rows come from that car; the picks, swaps, push and upgrades stay pass 1's, and so does
     * the card's first row, whose replacements the parts line names. The saved plan's first row lists the batch at
     * what each part costs, and its envelope is the second pass's own, so both reconcile with balances already net
     * of the batch. The card's envelope adds back what the lines draw for the batch, so they still draw it from the
     * envelope they are shown.
     *
     * @param Context $ctx
     * @param Plan $plan
     * @param PlanBasis $basis
     * @param array<string, mixed> $c
     * @param EnergyCap $energy
     * @return array{card: Plan, saved: Plan}
     */
    private function afterTheUpdate(
        array $ctx,
        array $plan,
        array $basis,
        EngineerInputs $inputs,
        array $c,
        array $energy,
        int $tyreExtra,
    ): array {
        $picks = array_column([...$inputs->swapPicks, ...$inputs->unlockPicks], null, 'part');
        $batch = [];
        foreach ($plan['rows'][0]['replaced'] as $swap) {
            $part = $swap['part'];
            $batch[$part] = isset($picks[$part]) ? self::bought($picks[$part]) : [
                'level' => $basis['parts'][$part]['level'],
                'start' => 0.0,
                'price' => $basis['prices'][$part] ?? null,
                'spend' => $swap['cost'],
            ];
        }
        foreach ($picks as $part => $pick) {
            $batch[$part] ??= self::bought($pick);
        }
        $upgrade = RaceEngineer::recommendedUpgrade($inputs);
        if ($upgrade !== null) {
            $batch[$upgrade['part']] = [
                'level' => $upgrade['to'],
                'start' => 0.0,
                'price' => $upgrade['price'],
                'spend' => $upgrade['price'],
            ];
        }
        if ($batch === []) {
            return ['card' => $plan, 'saved' => $plan];
        }

        $updated = $basis;
        $spend = 0;
        foreach ($batch as $part => $buy) {
            $spend += $buy['spend'];
            if (isset($updated['parts'][$part])) {
                $updated['parts'][$part] = ['level' => $buy['level'], 'start' => $buy['start']]
                    + $updated['parts'][$part];
                $updated['prices'][$part] = $buy['price'];
            }
        }
        $after = ['cash' => $ctx['cash'] - $spend] + $ctx;
        $second = self::payingTyreExtra(
            $this->plan($after, $updated, $this->policy($after, $updated, $c, $energy), true),
            $tyreExtra,
        );

        $merged = $plan;
        unset($merged['infeasible']);
        foreach (self::FROM_PASS_TWO as $key) {
            if (array_key_exists($key, $second)) {
                $merged[$key] = $second[$key];
            }
        }
        $merged['rows'][0] = ['replaced' => $plan['rows'][0]['replaced'], 'repairs' => $plan['rows'][0]['repairs']]
            + $second['rows'][0];
        // Locked, the second pass can't push: the swap pass 1 buys stays a push.
        $merged['mode'] = match (true) {
            $second['mode'] === 'short' => 'short',
            $plan['mode'] === 'push' => 'push',
            default => $second['mode'],
        };

        $bought = [];
        foreach ($batch as $part => $buy) {
            $bought[] = ['part' => $part, 'cost' => $buy['spend']];
        }
        $saved = $merged;
        $saved['rows'][0] = ['replaced' => $bought, 'repairs' => $spend] + $second['rows'][0];
        $merged['envelope'] = $second['envelope'] + RaceEngineer::pickSpend($inputs)
            + max(0, $upgrade['net_cost'] ?? 0);

        return ['card' => $merged, 'saved' => $saved];
    }

    /**
     * @param SwapPick $pick
     * @return array{level: int, start: float, price: ?int, spend: int}
     */
    private static function bought(array $pick): array
    {
        return [
            'level' => $pick['level'],
            'start' => $pick['kind'] === 'free_downgrade' ? $pick['start'] : 0.0,
            'price' => $pick['price'],
            'spend' => $pick['cost'],
        ];
    }

    /**
     * PHA demand per race from the season calendar; null for a race whose track is unknown.
     *
     * @param array<string, mixed> $c
     * @return array<int, ?Demand>
     */
    private function demands(array $c): array
    {
        $out = [];
        foreach (self::list($c['season_calendar'] ?? null) as $row) {
            $power = $row['power'] ?? null;
            $handling = $row['handling'] ?? null;
            $acceleration = $row['acceleration'] ?? null;
            $out[(int) $row['race']] = is_numeric($power) && is_numeric($handling) && is_numeric($acceleration)
                ? ['power' => $power + 0, 'handling' => $handling + 0, 'acceleration' => $acceleration + 0]
                : null;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $c
     * @return list<Round>
     */
    private function rounds(array $c): array
    {
        $calendar = self::list($c['season_calendar'] ?? null);
        $names = [];
        foreach ($calendar as $row) {
            $name = (string) ($row['track_name'] ?? '');
            if ($name !== '') {
                $names[] = $name;
            }
        }
        $bases = $this->wear->trackBaseWearByName($names);

        return array_map(static function (array $row) use ($bases): array {
            $name = $row['track_name'] ?? null;

            return [
                'race' => (int) $row['race'],
                'track_name' => $name === null ? null : (string) $name,
                'base' => $name !== null ? ($bases[(string) $name] ?? null) : null,
            ];
        }, $calendar);
    }

    /**
     * @param array<string, mixed> $c
     * @param list<Round> $rounds
     */
    private function pushAdvice(array $c, array $rounds, int $raceNb): ?string
    {
        if ($rounds === []) {
            return null;
        }

        $car = self::arr($c['car'] ?? null);
        $rows = $this->planner->seasonWear(
            $rounds,
            $raceNb,
            $this->wear->driverFactor(self::arr($c['driver'] ?? null)),
            $this->planner->partsFrom($car, [], []),
            $this->planner->partPrices($car),
        );
        foreach ($rows as $row) {
            if ($row['race'] === $raceNb) {
                return $row['advice'];
            }
        }

        return null;
    }

    /**
     * Zero when nothing is forced; null when something is but the cost is not known.
     *
     * @param list<mixed> $forced
     * @param ?array<string, mixed> $swapPlan
     */
    private function forcedSwapCost(array $forced, ?array $swapPlan): ?int
    {
        if ($forced === []) {
            return 0;
        }

        return is_numeric($swapPlan['cost'] ?? null) ? (int) $swapPlan['cost'] : null;
    }

    /**
     * With the setup stale the weather is withheld, so the advice is computed dry only to
     * let the engineer say "after you save the setup" instead of dropping the line.
     *
     * @param array<string, mixed> $driver
     * @param ?array<string, mixed> $track
     * @param ?array<string, mixed> $weather
     * @return ?Risk
     */
    private function risk(array $driver, ?array $track, ?array $weather, bool $stale): ?array
    {
        if ($track === null || ($weather === null && !$stale)) {
            return null;
        }

        return $this->riskAdvisor->suggest(
            $driver,
            [
                'overtaking' => self::stringOrNull($track['overtaking'] ?? null),
                'grip' => self::stringOrNull($track['grip'] ?? null),
                'tyre_wear' => self::stringOrNull($track['tyre_wear'] ?? null),
                'distance' => (float) ($track['distance'] ?? 0),
            ],
            (bool) ($weather['race_start_wet'] ?? false),
            (float) ($weather['race_rain_avg'] ?? 0.0),
        );
    }

    /**
     * @param ?array<string, mixed> $track
     * @param ?array<string, mixed> $weather
     */
    private function boostLitres(?array $track, ?array $weather, bool $stale): ?int
    {
        if ($track === null || ($weather === null && !$stale)) {
            return null;
        }

        $coeff = $track[($weather['race_start_wet'] ?? false) ? 'boost_wet' : 'boost_dry'] ?? null;
        if (!is_numeric($coeff)) {
            return null;
        }

        return $this->boost->extraFuel(
            RaceEngineer::BOOST_SETS * RaceEngineer::BOOST_LAPS_PER_SET,
            $this->fuel->lapLength($track),
            (float) $coeff,
        );
    }

    /**
     * The latest stored standings on entering a race up to the next one: a newer row is a
     * future race and must not leak in.
     *
     * @return ?array<string, mixed>
     */
    private function standingsRow(int $userId, int $season, int $raceNb): ?array
    {
        $row = null;
        foreach ($this->standings->forSeason($userId, $season) as $candidate) {
            if ((int) $candidate['race'] <= $raceNb) {
                $row = $candidate;
            }
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private static function arr(mixed $value): array
    {
        /** @var array<string, mixed> */
        return is_array($value) ? $value : [];
    }

    /**
     * @return ?array<string, mixed>
     */
    private static function arrOrNull(mixed $value): ?array
    {
        /** @var ?array<string, mixed> */
        return is_array($value) ? $value : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function list(mixed $value): array
    {
        /** @var list<array<string, mixed>> */
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /**
     * @return ?array{power: float, handling: float, acceleration: float}
     */
    private static function pha(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $out = [];
        foreach (['power', 'handling', 'acceleration'] as $key) {
            if (!is_numeric($value[$key] ?? null)) {
                return null;
            }
            $out[$key] = (float) $value[$key];
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    private static function ints(mixed $value): array
    {
        return is_array($value) ? array_values(array_map(intval(...), array_filter($value, is_numeric(...)))) : [];
    }

    /**
     * @return array<string, float>
     */
    private static function floats(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $key => $number) {
            if (is_numeric($number)) {
                $out[(string) $key] = (float) $number;
            }
        }

        return $out;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
