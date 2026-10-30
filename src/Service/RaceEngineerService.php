<?php

declare(strict_types=1);

namespace App\Service;

use App\Engineer\CashOutlook;
use App\Engineer\CtrCeiling;
use App\Engineer\CtrPlanner;
use App\Engineer\EngineerInputs;
use App\Engineer\GroupStanding;
use App\Engineer\Money;
use App\Engineer\RaceEngineer;
use App\Engineer\SeasonObjective;
use App\Engineer\SeasonStart;
use App\Engineer\StrengthFloor;
use App\Engineer\SwapSeason;
use App\Engineer\TyreSupplierAdvisor;
use App\Engineer\UpgradeRanker;
use App\Repository\EconomyLedgerRepository;
use App\Repository\RaceTelemetryRepository;
use App\Repository\SeasonBudgetRepository;
use App\Repository\SeasonObjectiveRepository;
use App\Repository\StandingsRepository;
use App\Repository\TrackRepository;
use App\Repository\WeekendStateRepository;
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
 * @phpstan-import-type TyreAdvice from TyreSupplierAdvisor
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
 *         verdict: 'pick'|'close'|'out_pipirelli'|'out_price'|'out_dry'|'out_budget'|null,
 *         per_race: string,
 *         season: string
 *     }>
 * }
 * @phpstan-type Context array{
 *     season: int,
 *     raceNb: int,
 *     cash: int,
 *     standingsRow: ?array<string, mixed>,
 *     weekends: list<array<string, mixed>>,
 *     ledger: list<array<string, mixed>>,
 *     outlook_ledger: list<array<string, mixed>>,
 *     outlook: array{known: bool, committed: ?int, income: ?int, free_cash: ?int, end_committed: ?int},
 *     objective: InForce
 * }
 */
class RaceEngineerService
{
    private const array PINNABLE = [SeasonObjective::PROMOTE, SeasonObjective::HOLD, SeasonObjective::SURVIVE];
    private const string AUTO = 'auto';
    private const array TYRE_DIVISIONS = ['Pro', 'Master', 'Elite'];
    private const array TYRE_WEAR_SCALE = ['Very Low' => 1, 'Low' => 2, 'Medium' => 3, 'High' => 4, 'Very High' => 5];

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
    ) {
    }

    /**
     * @param array<string, mixed> $c Cockpit's already-computed values
     * @param bool $stale A race has finished since the last sync: the figures are last weekend's, so the
     *                    engineer asks for a re-sync instead of planning (or saving a plan) on them.
     * @return array{objective: InForce, lines: list<Line>, season: int, tyres: ?TyrePanel}
     */
    public function forCockpit(int $userId, array $c, bool $stale = false): array
    {
        $ctx = $this->context($userId, $c);
        $objective = $ctx['objective'];
        $season = $ctx['season'];

        if ($stale) {
            return [
                'objective' => $objective,
                'lines' => [RaceEngineer::stale()],
                'season' => $season,
                'tyres' => null,
            ];
        }

        $rounds = $this->rounds($c);
        $basis = $this->planBasis($ctx['outlook'], $ctx['raceNb'], $rounds, $c);
        $plan = $basis === null ? null : $this->plan($ctx, $basis);
        $inputs = $this->inputs(
            $objective,
            $ctx['outlook'],
            $ctx['cash'],
            $ctx['raceNb'],
            $rounds,
            $plan,
            $c,
        );

        if ($plan !== null) {
            try {
                $picks = $this->swapPicks($inputs, $plan, $this->swapSeasonFrom($ctx, $plan, $basis, $c), $c);
            } catch (\Throwable $e) {
                // The picks only name a level; the parts line keeps its wording without them.
                error_log('[Engineer] ' . $e::class);
                $picks = [];
            }
            if ($picks !== []) {
                $plan['swap_picks'] = $picks;
                $inputs = $inputs->withPlan($plan)->withSwapPicks($picks);
            }
            $plan = $this->withUpgrades($plan, $inputs, $objective, $ctx['outlook'], $ctx['cash'], $basis, $c);
            $inputs = $inputs->withPlan($plan);
            try {
                $this->budgets->save($userId, $season, $ctx['raceNb'], $plan);
            } catch (\Throwable $e) {
                // Saving only feeds the Season screen; the advice stands without it.
                error_log('[Engineer] ' . $e::class);
            }
        }

        $tyres = $this->tyreAdvice($c, $plan, $ctx);
        $lines = RaceEngineer::brief($inputs->withTyreAdvice($tyres));

        return [
            'objective' => $objective,
            'lines' => $lines,
            'season' => $season,
            'tyres' => $tyres === null ? null : self::tyrePanel($tyres),
        ];
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
        $basis = $this->planBasis($ctx['outlook'], $ctx['raceNb'], $this->rounds($c), $c);
        if ($basis === null) {
            return null;
        }

        return $this->swapSeasonFrom($ctx, $this->plan($ctx, $basis), $basis, $c);
    }

    public function pin(int $userId, int $season, string $value): bool
    {
        if ($value === self::AUTO) {
            $this->pins->clear($userId, $season);

            return true;
        }
        if (!in_array($value, self::PINNABLE, true)) {
            return false;
        }

        $this->pins->save($userId, $season, $value);

        return true;
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
        // Every sync fetches the office; the menu only once a season.
        $cash = (int) ($office['cash'] ?? $menu['cash'] ?? 0);

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
        $outlook = CashOutlook::from(
            $outlookWeekends === [] ? null : $outlookWeekends[array_key_last($outlookWeekends)],
            $outlookLedger,
            $cash,
            max(0, SeasonObjective::RACES - $racesRun),
        );

        $proposal = SeasonObjective::propose(
            $standingsRow,
            $raceNb,
            $outlook,
            $cash,
            GroupStanding::car(self::arr($c['money_levels'] ?? null), $menu)['rank'] ?? null,
            ($c['division'] ?? null) === 'Elite',
        );

        return [
            'season' => $season,
            'raceNb' => $raceNb,
            'cash' => $cash,
            'standingsRow' => $standingsRow,
            'weekends' => $weekends,
            'ledger' => $seasonLedger,
            'outlook_ledger' => $outlookLedger,
            'outlook' => $outlook,
            'objective' => SeasonObjective::inForce($proposal, $this->pins->find($userId, $season)),
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
            $ctx['objective']['objective'] ?? SeasonObjective::HOLD,
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
                SeasonObjective::relegationLine($ctx['standingsRow']),
            ),
        );
    }

    /**
     * The season-long level for each part the plan replaces before the next race, then for each optional swap
     * the parts line offers from what those picks leave, judged at the plan's CTR on a season already paying
     * for the forced picks. A part the picker can't judge is left out.
     *
     * @param Plan $plan
     * @param array<string, mixed> $c
     * @return list<SwapPick>
     */
    private function swapPicks(EngineerInputs $inputs, array $plan, SwapSeason $season, array $c): array
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
        $forced = $this->picksAt(
            self::flagged(array_column($plan['rows'][0]['replaced'], 'part'), $adviseParts),
            $adviseParts,
            $season,
            $trackPha,
            $carPha,
            $c,
        );
        $optional = array_column(RaceEngineer::offeredOptionals($inputs->withSwapPicks($forced)), 'part');
        $committed = array_sum(array_map(static fn (array $pick): int => max(0, $pick['net_cost']), $forced));

        $picks = [...$forced, ...$this->picksAt(
            self::flagged($optional, $adviseParts),
            $adviseParts,
            $season->committing($committed),
            $trackPha,
            $carPha,
            $c,
        )];

        return RaceEngineer::shownPicks($inputs->withSwapPicks($picks));
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
     */
    private function inputs(
        array $objective,
        array $outlook,
        int $cash,
        int $raceNb,
        array $rounds,
        ?array $plan,
        array $c,
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

        $ceiling = null;
        $survive = null;
        $alwaysRed = [];
        if ($parts !== null) {
            $ceiling = $this->ceiling->for($parts, $driverFactor, WearAdvisorService::THRESHOLD_SWAP);
            $survive = min($ceiling, $this->ceiling->for($parts, $driverFactor, WearAdvisorService::THRESHOLD_RISKY));
            // Parts past the swap limit even at CTR 0 get replaced, so they aren't stuck in the red.
            // Both lists ignore the current CTR, which keeps the reason stable once "Use N" is followed.
            $alwaysRed = array_values(array_diff(
                $this->ceiling->pastLimitAtZero($parts, $driverFactor, WearAdvisorService::THRESHOLD_RISKY),
                $this->ceiling->pastLimitAtZero($parts, $driverFactor, WearAdvisorService::THRESHOLD_SWAP),
            ));
        }
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
            ctrSurvive: $survive,
            alwaysRed: $alwaysRed,
            pushAdvice: $this->pushAdvice($c, $rounds, $raceNb),
            wearAdvice: $wearAdvice,
            forcedSwapCost: $this->forcedSwapCost($forced, self::arrOrNull($c['swap_plan'] ?? null)),
            optionalSwaps: $plan !== null && $parts !== null
                ? $this->plannedOptionalSwaps($plan, $parts, $driverFactor, $car)
                : $this->optionalSwaps($wearAdvice, $car),
            trainingPick: $pick,
            testingTarget: $target,
            testingOpen: (bool) ($c['testing_open'] ?? false),
            testingTrack: is_string($testingTrack) && $testingTrack !== '' ? $testingTrack : null,
            testingCost: RaceEngineer::TESTING_SESSION_COST,
            risk: $this->risk($driver, $track, $weather, $stale),
            boostLitres: $this->boostLitres($track, $weather, $stale),
            weatherStale: $stale,
            plan: $plan,
            redZoneSwaps: $plan !== null && $parts !== null ? $this->redZoneSwaps($plan, $parts, $driverFactor) : [],
            staffStanding: GroupStanding::staff(
                self::arr($c['group_staff'] ?? null),
                self::arr($c['menu'] ?? null),
                $objective['objective'] ?? SeasonObjective::HOLD,
            ),
            carStanding: GroupStanding::car(self::arr($c['money_levels'] ?? null), self::arr($c['menu'] ?? null)),
            testingLaps: (int) ($c['training_laps'] ?? 0),
            division: self::stringOrNull($c['division'] ?? null),
        );
    }

    /**
     * Which supplier to sign for the season, while GPRO's contract window is open on a Pro, Master or Elite team
     * that has not signed. Null when the window is shut or anything the advice reads fails.
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
            !in_array($division, self::TYRE_DIVISIONS, true)
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
     * Under a plan the optional swaps are the parts that would end the next race in the red at the plan's CTR,
     * not at the manager's current one, so following "Use N" changes nothing. Parts the plan already
     * replaces are paid for inside the envelope.
     *
     * @param Plan $plan
     * @param array<string, array{level: int, start: int|float, track_base: int|float, training?: int|float}> $parts
     * @param array<string, mixed> $car
     * @return list<array{part: string, cost: int}>
     */
    private function plannedOptionalSwaps(array $plan, array $parts, float $driverFactor, array $car): array
    {
        $prices = $this->planner->partPrices($car);
        $replaced = array_column($plan['rows'][0]['replaced'], 'part');
        $out = [];
        foreach ($parts as $label => $part) {
            $end = $this->endAt($part, $driverFactor, $plan['ctr']);
            $price = $prices[$label] ?? null;
            if (
                $end >= WearAdvisorService::THRESHOLD_RISKY
                && $end < WearAdvisorService::THRESHOLD_SWAP
                && !in_array($label, $replaced, true)
                && $price !== null
            ) {
                $out[] = ['part' => (string) $label, 'cost' => $price];
            }
        }

        return $out;
    }

    /**
     * Parts the plan replaces before the next race that would only finish it in the red at the plan's CTR
     * (a Survive plan swaps at 90), so the engineer doesn't call them a break.
     *
     * @param Plan $plan
     * @param array<string, array{level: int, start: int|float, track_base: int|float, training?: int|float}> $parts
     * @return list<string>
     */
    private function redZoneSwaps(array $plan, array $parts, float $driverFactor): array
    {
        $out = [];
        foreach ($plan['rows'][0]['replaced'] as $swap) {
            $part = $parts[$swap['part']] ?? null;
            $end = $part === null ? null : $this->endAt($part, $driverFactor, $plan['ctr']);
            if ($end !== null && $end < WearAdvisorService::THRESHOLD_SWAP) {
                $out[] = $swap['part'];
            }
        }

        return $out;
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
    private function plan(array $ctx, array $basis): array
    {
        return $this->ctrPlanner->plan(
            $ctx['objective']['objective'] ?? SeasonObjective::HOLD,
            $basis['rounds'],
            $basis['parts'],
            $basis['prices'],
            $basis['driverFactor'],
            $ctx['cash'],
            (int) $ctx['outlook']['committed'],
            (int) $ctx['outlook']['income'],
            SeasonStart::cash($ctx['ledger'], $ctx['weekends']),
        );
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
                $objective['objective'] ?? SeasonObjective::HOLD,
                $basis['rounds'],
                $this->demands($c),
                $basis['parts'],
                $basis['prices'],
                array_diff_key(
                    $this->planner->upgradeOptions(self::arr($c['car'] ?? null)),
                    array_flip(array_column($inputs->swapPicks, 'part')),
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
     * @param ?array<string, mixed> $wearAdvice
     * @param array<string, mixed> $car
     * @return list<array{part: string, cost: int}>
     */
    private function optionalSwaps(?array $wearAdvice, array $car): array
    {
        $risky = self::list($wearAdvice['risky'] ?? null);
        if ($risky === []) {
            return [];
        }

        $prices = $this->planner->partPrices($car);
        $out = [];
        foreach ($risky as $part) {
            $label = (string) $part['part'];
            $price = $prices[$label] ?? null;
            if ($price !== null) {
                $out[] = ['part' => $label, 'cost' => $price];
            }
        }

        return $out;
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
                'name' => (string) ($track['name'] ?? ''),
                'overtaking' => self::stringOrNull($track['overtaking'] ?? null),
                'grip' => self::stringOrNull($track['grip'] ?? null),
                'tyre_wear' => self::stringOrNull($track['tyre_wear'] ?? null),
                'distance' => (float) ($track['distance'] ?? 0),
                'pit_lane_loss' => (float) ($track['pit_time'] ?? 0),
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
}
