<?php

declare(strict_types=1);

namespace App\Service;

use App\Calibration\LedgerCategories;
use App\Engineer\CashOutlook;
use App\Engineer\CtrCeiling;
use App\Engineer\CtrPlanner;
use App\Engineer\EngineerInputs;
use App\Engineer\RaceEngineer;
use App\Engineer\SeasonObjective;
use App\Engineer\SeasonStart;
use App\Repository\EconomyLedgerRepository;
use App\Repository\SeasonBudgetRepository;
use App\Repository\SeasonObjectiveRepository;
use App\Repository\StandingsRepository;
use App\Repository\TrackRepository;
use App\Repository\WeekendStateRepository;

/**
 * Admin-only race engineer: loads the stored season history, reuses the values
 * Cockpit already computed and the existing advisors, and hands the pure
 * engineer classes everything they need.
 *
 * @phpstan-import-type InForce from SeasonObjective
 * @phpstan-import-type Line from RaceEngineer
 * @phpstan-import-type Plan from CtrPlanner
 * @phpstan-import-type Round from \App\Engineer\SeasonBudget
 * @phpstan-import-type Risk from EngineerInputs
 * @phpstan-import-type TestingTarget from EngineerInputs
 * @phpstan-import-type WearAdvice from EngineerInputs
 */
class RaceEngineerService
{
    private const array PINNABLE = [SeasonObjective::PROMOTE, SeasonObjective::HOLD, SeasonObjective::SURVIVE];
    private const string AUTO = 'auto';

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
    ) {
    }

    /**
     * @param array<string, mixed> $c Cockpit's already-computed values
     * @return array{objective: InForce, lines: list<Line>, season: int}
     */
    public function forCockpit(int $userId, array $c): array
    {
        $office = self::arr($c['office'] ?? null);
        $menu = self::arr($c['menu'] ?? null);
        $season = (int) ($office['seasonNb'] ?? 0);
        $raceNb = (int) ($office['raceNb'] ?? 0);
        $cash = (int) ($menu['cash'] ?? 0);

        $standingsRow = $this->standingsRow($userId, $season, $raceNb);
        $weekends = $this->weekends->forSeason($userId, $season);
        $seasonLedger = $this->ledger->forSeason($userId, $season);

        $racesRun = $standingsRow === null ? max(0, $raceNb - 1) : max(0, (int) $standingsRow['race'] - 1);
        $outlook = CashOutlook::from(
            $weekends === [] ? null : $weekends[array_key_last($weekends)],
            $seasonLedger,
            $cash,
            max(0, SeasonObjective::RACES - $racesRun),
        );

        $proposal = SeasonObjective::propose(
            $standingsRow,
            $raceNb,
            $outlook,
            $cash,
            self::ownCarRank($menu, self::arr($c['money_levels'] ?? null)),
            ($c['division'] ?? null) === 'Elite',
        );
        $objective = SeasonObjective::inForce($proposal, $this->pins->find($userId, $season));

        $rounds = $this->rounds($c);
        $plan = $this->plan($objective, $outlook, $cash, $raceNb, $rounds, $seasonLedger, $weekends, $c);
        if ($plan !== null) {
            $this->budgets->save($userId, $season, $raceNb, $plan);
        }

        $lines = RaceEngineer::brief(
            $this->inputs($objective, $outlook, $cash, $raceNb, $seasonLedger, $rounds, $plan, $c),
        );

        return ['objective' => $objective, 'lines' => $lines, 'season' => $season];
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
     * @param InForce $objective
     * @param array{known: bool, committed: ?int, income: ?int, free_cash: ?int, end_committed: ?int} $outlook
     * @param list<array<string, mixed>> $seasonLedger
     * @param list<Round> $rounds
     * @param ?Plan $plan
     * @param array<string, mixed> $c
     */
    private function inputs(
        array $objective,
        array $outlook,
        int $cash,
        int $raceNb,
        array $seasonLedger,
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
            optionalSwaps: $this->optionalSwaps($wearAdvice, self::arr($c['car'] ?? null)),
            trainingPick: $pick,
            testingTarget: $target,
            testingOpen: (bool) ($c['testing_open'] ?? false),
            testingTrack: is_string($testingTrack) && $testingTrack !== '' ? $testingTrack : null,
            testingCost: self::testingCost($seasonLedger),
            risk: $this->risk($driver, $track, $weather, $stale),
            boostLitres: $this->boostLitres($track, $weather, $stale),
            weatherStale: $stale,
            plan: $plan,
        );
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
     * Built only when the budget can be forecast: costs known, parts known, a race left, and the
     * next race's track wear known for every part. Otherwise the engineer falls back to the safety CTR.
     *
     * @param InForce $objective
     * @param array{known: bool, committed: ?int, income: ?int, free_cash: ?int, end_committed: ?int} $outlook
     * @param list<Round> $rounds
     * @param list<array<string, mixed>> $seasonLedger
     * @param list<array<string, mixed>> $weekends
     * @param array<string, mixed> $c
     * @return ?Plan
     */
    private function plan(
        array $objective,
        array $outlook,
        int $cash,
        int $raceNb,
        array $rounds,
        array $seasonLedger,
        array $weekends,
        array $c,
    ): ?array {
        $parts = $this->wearParts($c);
        if (!$outlook['known'] || $parts === null || $raceNb > SeasonObjective::RACES) {
            return null;
        }

        $remaining = array_values(array_filter(
            $rounds,
            static fn (array $round): bool => $round['race'] >= $raceNb,
        ));
        $nextBase = $remaining[0]['base'] ?? null;
        if ($nextBase === null || array_diff_key($parts, $nextBase) !== []) {
            return null;
        }

        $car = self::arr($c['car'] ?? null);

        return $this->ctrPlanner->plan(
            $objective['objective'] ?? SeasonObjective::HOLD,
            $remaining,
            $parts,
            $this->planner->partPrices($car),
            $this->wear->driverFactor(self::arr($c['driver'] ?? null)),
            $cash,
            (int) $outlook['committed'],
            (int) $outlook['income'],
            SeasonStart::cash($seasonLedger, $weekends),
        );
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
     * Mean cost of this season's testing sessions, from the ledger; null before the first one.
     *
     * @param list<array<string, mixed>> $seasonLedger
     */
    private static function testingCost(array $seasonLedger): ?int
    {
        $amounts = [];
        foreach ($seasonLedger as $line) {
            $description = trim((string) $line['description']);
            if (
                LedgerCategories::categorise($description) === 'Track time'
                && stripos($description, 'Testing session') === 0
            ) {
                $amounts[] = abs((int) $line['amount']);
            }
        }

        return $amounts === [] ? null : (int) round(array_sum($amounts) / count($amounts));
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
     * @param array<string, mixed> $menu
     * @param array<string, mixed> $moneyLevels
     */
    private static function ownCarRank(array $menu, array $moneyLevels): ?int
    {
        if (!isset($menu['IDM'])) {
            return null;
        }

        foreach (self::list($moneyLevels['managers'] ?? null) as $manager) {
            if (isset($manager['IDM']) && (string) $manager['IDM'] === (string) $menu['IDM']) {
                return is_numeric($manager['carLevelRank'] ?? null) ? (int) $manager['carLevelRank'] : null;
            }
        }

        return null;
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

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
