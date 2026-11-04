<?php

declare(strict_types=1);

namespace App\Engineer;

use App\Support\Ordinal;

/**
 * @phpstan-import-type Plan from CtrPlanner
 * @phpstan-import-type CtrValueFigures from CtrPlanner
 * @phpstan-import-type Upgrade from UpgradeRanker
 * @phpstan-import-type SwapPick from EngineerInputs
 * @phpstan-import-type TyreAdvice from TyreSupplierAdvisor
 * @phpstan-type Line array{
 *     key: string,
 *     answer: string,
 *     figure: ?string,
 *     reason: string,
 *     anchor: ?string,
 *     tone: string,
 *     action: ?array{label: string, href: string},
 *     step: 'season'|'car'|'prep'|'race'
 * }
 */
final class RaceEngineer
{
    private const string SMOKE_COST = 'expect smoke and lost time, and a retirement if it comes early';

    /** Tunable: how much a Stay weekend lowers the advisor's overtake risk. */
    public const int STAY_OVERTAKE_STEP = 15;

    /** Boost sets per race; the caller's extra-fuel litres must use BOOST_SETS * BOOST_LAPS_PER_SET laps. */
    public const int BOOST_SETS = 3;
    public const int BOOST_LAPS_PER_SET = 3;
    /** GPRO charges a flat $1M for every testing session. */
    public const int TESTING_SESSION_COST = 1_000_000;

    private const string NOTHING = 'Nothing to do';
    private const string STALE = 'After you save the race setup';
    private const string BUDGET_ANCHOR = '/season#season-budget';
    private const string STAGE_TRAINING = 'training';
    private const string STAGE_TESTING = 'testing';
    private const string UPGRADES_ANCHOR = '/season#season-upgrades';

    /** Which step of the weekend each line belongs to; brief() keeps lines of one step together. */
    public const array STEPS = [
        'objective' => 'season',
        'tyres' => 'season',
        'cash' => 'season',
        'staff' => 'season',
        'stale' => 'season',
        'parts' => 'car',
        'upgrades' => 'car',
        'training' => 'prep',
        'testing' => 'prep',
        'ctr' => 'race',
        'risk' => 'race',
        'boost' => 'race',
    ];

    private const array KIND_WORDS = [
        'free_downgrade' => 'free downgrade',
        'downgrade' => 'downgrade',
        'sidegrade' => 'same level',
        'upgrade' => 'upgrade',
    ];

    /**
     * @return list<Line>
     */
    public static function brief(EngineerInputs $in): array
    {
        $lines = [self::objective($in)];
        if ($in->objective['over']) {
            return $lines;
        }

        $tyres = self::tyres($in);
        if ($tyres !== null) {
            $lines[] = $tyres;
        }

        $optional = [
            self::cash($in),
            self::staff($in),
            self::parts($in),
            self::upgrades($in),
            self::training($in),
            self::testing($in),
            self::ctr($in),
            self::risk($in),
            self::boost($in),
        ];

        return [...$lines, ...array_values(array_filter($optional))];
    }

    /**
     * The whole brief once a race has finished since the last sync: nothing else is safe to say.
     *
     * @return Line
     */
    public static function stale(): array
    {
        return self::line('stale', 'New race weekend', "Re-sync for this weekend's advice", tone: 'amber');
    }

    /**
     * @return ?Line
     */
    private static function tyres(EngineerInputs $in): ?array
    {
        $advice = $in->tyreAdvice;
        if ($advice === null) {
            return null;
        }

        $reason = match ($advice['reason']) {
            'best' => 'Best balance for your calendar',
            'band' => 'Within 10% of ' . $advice['best'] . ', and '
                . Money::short((array_column($advice['rows'], 'cost', 'name')[$advice['best']] ?? $advice['cost'])
                    - $advice['cost']) . ' a race cheaper',
            'budget_short' => 'Budget short: cheapest supplier your division allows',
        };
        if ($advice['wet_edge'] && $advice['reason'] !== 'budget_short' && $in->division !== null) {
            $reason .= "; your driver's talent (" . $advice['talent'] . ') is at or above the '
                . $in->division . ' average (' . $advice['baseline'] . '), so rain counts more';
        }
        if (!$advice['finances_known']) {
            $reason .= '; finances unknown, picked on performance';
        }

        return self::line(
            'tyres',
            'Sign ' . $advice['pick'],
            $reason,
            anchor: '/engineer#engineer-tyres',
            figure: Money::short($advice['cost']) . ' a race',
        );
    }

    /**
     * @return Line
     */
    private static function objective(EngineerInputs $in): array
    {
        $o = $in->objective;
        $pinnedBlind = $o['pinned'] && $o['proposed'] === null;
        $reason = $o['reasons'][0] ?? match (true) {
            $o['over'] => '',
            $o['objective'] === null => "Your position in the group isn't known yet; treated as Stay",
            $pinnedBlind => "Your position in the group isn't known yet",
            default => '',
        };
        $tone = $o['warning'] !== null || $o['cash_alarm'] ? 'amber' : 'ink';

        return self::line('objective', $o['label'], $reason, figure: self::objectiveFigure($o['numbers']), tone: $tone);
    }

    /**
     * @param array<string, int|null> $n
     */
    private static function objectiveFigure(array $n): ?string
    {
        $parts = [];
        if (($n['pos'] ?? null) !== null) {
            $parts[] = 'P' . $n['pos'];
        }
        if (($n['gap'] ?? 0) > 0 && ($n['line_pos'] ?? null) !== null) {
            $parts[] = $n['gap'] . ' pts behind P' . $n['line_pos'];
        }
        if (($n['races_left'] ?? null) !== null) {
            $parts[] = $n['races_left'] . ($n['races_left'] === 1 ? ' race left' : ' races left');
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * @return Line
     */
    private static function cash(EngineerInputs $in): array
    {
        if ($in->plan !== null) {
            return self::budgetCash($in, $in->plan);
        }

        $free = self::free($in);
        if ($free === null) {
            return self::line(
                'cash',
                'Costs unknown — sync once after a race',
                'Spending is checked against your balance until costs are known',
            );
        }

        $forced = $in->forcedSwapCost ?? 0;
        $figure = Money::short($free);

        return match (true) {
            $free < 0 => self::line(
                'cash',
                'Cash runs out this season',
                'Committed costs outrun income before the last race',
                figure: $figure,
                tone: 'red',
            ),
            $free < $forced => self::line(
                'cash',
                'Tight — repairs may not fit',
                'Free cash is below the ' . Money::short($forced) . ' of forced swaps',
                figure: $figure,
                tone: 'amber',
            ),
            default => self::line(
                'cash',
                'Cash covers the season',
                'Free cash after committed costs to the last race',
                figure: $figure,
            ),
        };
    }

    /**
     * @param Plan $plan
     * @return Line
     */
    private static function budgetCash(EngineerInputs $in, array $plan): array
    {
        $partial = $plan['partial'] === [] ? '' : '; forecast partial: ' . implode(', ', $plan['partial']);
        $envelope = self::afterPicks($in);

        if ($plan['mode'] === 'short') {
            return self::line(
                'cash',
                'Over budget',
                'The forecast misses the target by ' . Money::short($plan['shortfall'] ?? 0) . $partial,
                self::BUDGET_ANCHOR,
                tone: 'red',
            );
        }
        if ($envelope <= 0) {
            return self::line(
                'cash',
                'Nothing to spare',
                'The forecast only just meets the target' . $partial,
                self::BUDGET_ANCHOR,
                tone: 'amber',
            );
        }

        $start = Money::short($plan['season_start'] ?? $plan['target']);
        $reason = match (true) {
            $plan['target_kind'] === 'start' => 'Ends the season above your ' . $start . ' start',
            $plan['target_kind'] === 'reserve' => 'Ends the season above ' . Money::short($plan['target'])
                . ', half your ' . $start . ' start',
            $plan['target_kind'] === 'start_out_of_reach' => 'Keeps one race of costs in hand (your ' . $start
                . ' start is out of reach)',
            $plan['target_kind'] === 'reserve_out_of_reach' => 'Keeps one race of costs in hand (half your ' . $start
                . ' start is out of reach)',
            self::objectiveIs($in, SeasonObjective::PROMOTE) => 'Keeps one race of costs in hand',
            $plan['season_start'] !== null => 'Keeps one race of costs in hand (above your ' . $start . ' start)',
            default => 'Keeps one race of costs in hand (season start unknown)',
        };

        return self::line('cash', 'Safe to spend', $reason . $partial, self::BUDGET_ANCHOR, Money::short($envelope));
    }

    /**
     * @return ?Line
     */
    private static function ctr(EngineerInputs $in): ?array
    {
        $plan = $in->plan;
        if ($plan === null && $in->ctrCeiling === null) {
            return null;
        }

        $stuck = self::stuckCtrReason($in);
        $rated = $in->pushAdvice === null ? '' : 'Race rated ' . $in->pushAdvice . ': ';

        if ($plan !== null) {
            $target = $plan['ctr'];
            $swapNames = implode(' + ', array_column($plan['swaps'], 'part'));
            $safetyReason = self::safetyCtrReason($in);
            $byValue = in_array($plan['ctr_kind'], ['value', 'energy'], true) && $plan['value'] !== null;
            $reason = match (true) {
                $stuck !== null && $target === 0 && $plan['mode'] !== 'short' => $stuck,
                $plan['mode'] === 'short' => 'Even at CTR 0 the forecast misses the target by '
                    . Money::short($plan['shortfall'] ?? 0),
                $plan['mode'] === 'push' && $plan['swaps'] !== [] => $rated . 'swap ' . $swapNames . ' ('
                    . Money::short(array_sum(array_column($plan['swaps'], 'cost')))
                    . '); the budget covers the swaps',
                // Below the cap the energy-kind pick was made by the value or budget rule, so that rule explains it.
                $plan['ctr_kind'] === 'energy' && $plan['ctr'] === $plan['energy_cap'] => self::energyCtrReason(
                    $plan['energy_cap'],
                    $plan['energy_estimate'],
                    $plan['energy_start'],
                    $in->division,
                ),
                $byValue => self::valueCtrReason($plan['ctr'], $plan['value']),
                $plan['ctr_kind'] === 'safety' && $safetyReason !== null => $safetyReason,
                $plan['ctr'] < $plan['safety'] => $rated . 'the highest CTR the budget covers to season end',
                $plan['mode'] === 'nurse' && $plan['refused_swap_cost'] !== null => $rated . 'pushing past '
                    . $plan['safety'] . ' needs ' . Money::short($plan['refused_swap_cost'])
                    . " of swaps the budget can't cover",
                default => $rated . 'the highest CTR where no part breaks',
            };
        } else {
            $ceiling = (int) $in->ctrCeiling;
            $capped = $in->energyCap !== null && $in->energyCap < $ceiling;
            $target = $capped ? (int) $in->energyCap : $ceiling;
            $reason = match (true) {
                $stuck !== null && $target === 0 => $stuck,
                $capped => self::energyCtrReason($target, $in->energyEstimate, $in->energyStart, $in->division),
                default => $rated . 'the highest CTR where no part breaks; season costs unknown',
            };
        }

        return self::line(
            'ctr',
            'CTR ' . $target,
            $reason,
            '/cockpit#cockpit-wear',
            'On ' . $in->currentCtr . ' now',
            action: $target === $in->currentCtr
                ? null
                : ['label' => 'Use ' . $target, 'href' => '/engineer?cockpit_risk=' . $target],
        );
    }

    /**
     * Why CTR 0 is advised on an updated car: a part reaches 100% at any CTR and can't be swapped any more.
     */
    private static function stuckCtrReason(EngineerInputs $in): ?string
    {
        if (!$in->carUpdated || $in->stuckSmoke === []) {
            return null;
        }

        return self::smoke($in->stuckSmoke) . ' and the car is already updated: CTR 0 keeps '
            . self::possessive($in->stuckSmoke) . ' wear lowest';
    }

    private static function energyCtrReason(int $cap, bool $estimate, ?int $start, ?string $division): string
    {
        if (!$estimate && $start !== null) {
            return 'Capped at ' . $cap . ": your driver's energy (about " . $start
                . '% at the start) would run out above that';
        }

        return 'Capped at ' . $cap . ': a rough ' . ($division === null ? '' : $division . ' ')
            . "estimate of what your driver's energy allows";
    }

    /**
     * @param CtrValueFigures $value
     */
    private static function valueCtrReason(int $ctr, array $value): string
    {
        $next = $value['next'];
        if ($ctr === 0) {
            return match (true) {
                !$value['pace_known'] => 'No pace figures for the remaining tracks: the lowest wear wins',
                $next !== null => 'A higher CTR earns less than its repairs cost (CTR ' . $next['ctr'] . ': +'
                    . Money::short($next['gain_more']) . ', repairs +' . Money::short($next['cost_more']) . ')',
                default => 'A higher CTR earns less than its repairs cost',
            };
        }

        $pick = 'About +' . number_format($value['places'], 1) . ' places a race (+' . Money::short($value['gain'])
            . ' this season); ';

        return $pick . ($next === null
            ? 'no higher CTR fits the budget'
            : 'CTR ' . $next['ctr'] . ' would earn ' . Money::short($next['gain_more']) . ' more but cost '
                . Money::short($next['cost_more']) . ' more in repairs');
    }

    /**
     * Why Stay takes the highest CTR: the projected finish is near or inside the relegation zone. Null without
     * both figures.
     */
    private static function safetyCtrReason(EngineerInputs $in): ?string
    {
        $projected = $in->objective['numbers']['projected_pos'] ?? null;
        $relegation = $in->objective['numbers']['relegation_pos'] ?? null;
        if ($projected === null || $relegation === null) {
            return null;
        }
        $above = $relegation - $projected;

        return 'P' . $projected . ' projected, '
            . ($above > 0 ? $above . ' places above the relegation zone' : 'inside the relegation zone')
            . ': the highest CTR the budget and energy allow';
    }

    /**
     * @param list<string> $parts
     */
    private static function smoke(array $parts): string
    {
        return implode(', ', $parts) . (count($parts) === 1 ? ' reaches' : ' reach') . ' 100% this race';
    }

    /**
     * @param list<string> $parts
     */
    private static function possessive(array $parts): string
    {
        return count($parts) === 1 ? 'its' : 'their';
    }

    /**
     * @return Line
     */
    private static function parts(EngineerInputs $in): array
    {
        if ($in->carUpdated) {
            $reason = $in->stuckSmoke === [] ? null : self::smoke($in->stuckSmoke) . ': ' . self::SMOKE_COST;

            return self::line(
                'parts',
                'Car updated',
                $reason === null
                    ? 'One update per race; the plan reads the car as it is now'
                    : $reason . '; no more changes until the next race',
                '/cockpit#cockpit-wear',
                tone: $reason === null ? 'ink' : 'amber',
            );
        }

        $plan = $in->plan;
        $replaced = $plan === null ? [] : $plan['rows'][0]['replaced'];
        $forced = array_column($plan === null ? $in->wearAdvice['swap'] ?? [] : $replaced, 'part');
        $forcedSwapCost = $plan === null ? $in->forcedSwapCost : self::pickedCost($in, $replaced);

        if ($plan !== null && $plan['mode'] === 'push' && $plan['swaps'] !== []) {
            return self::pickedLine(
                $in,
                $forced,
                'Swap ' . self::swapNames($in, $forced),
                'Lets you push to CTR ' . $plan['ctr'],
                Money::short($forcedSwapCost ?? 0),
                'ink',
            );
        }

        // A part that ends the race below 100% stays on the car: a swap would only spend money.
        if ($forced === []) {
            return self::line('parts', 'Nothing to swap', 'No forced swap this race', '/cockpit#cockpit-wear');
        }

        $tone = $plan === null && self::spendable($in) < ($forcedSwapCost ?? 0) ? 'red' : 'amber';

        return self::pickedLine(
            $in,
            $forced,
            'Swap ' . self::swapNames($in, $forced),
            'Would break this race if kept',
            $forcedSwapCost === null ? null : Money::short($forcedSwapCost),
            $tone,
        );
    }

    /**
     * The parts line, with the reasons of the season picks for $parts after today's reason. A pick that only
     * had a budget-short option left replaces the reason: that is what the manager has to know.
     *
     * @param list<string> $parts
     * @return Line
     */
    private static function pickedLine(
        EngineerInputs $in,
        array $parts,
        string $answer,
        string $reason,
        ?string $figure,
        string $tone,
    ): array {
        $reasons = [];
        foreach ($in->swapPicks as $pick) {
            if (in_array($pick['part'], $parts, true)) {
                $reasons[] = $pick['reason'];
            }
        }
        $reasons = array_values(array_unique($reasons));

        if (in_array('budget_short', $reasons, true)) {
            $reason = SwapLevelPicker::REASONS['budget_short'];
            $tone = $tone === 'red' ? 'red' : 'amber';
        } elseif ($reasons !== []) {
            $reason .= '; ' . implode('; ', array_map(
                static fn (string $code): string => lcfirst(SwapLevelPicker::REASONS[$code]),
                $reasons,
            ));
        }

        return self::line('parts', $answer, $reason, '/cockpit#cockpit-wear', $figure, $tone);
    }

    /**
     * @param list<string> $parts
     */
    private static function swapNames(EngineerInputs $in, array $parts): string
    {
        $picks = array_column($in->swapPicks, null, 'part');

        return implode(', ', array_map(static function (string $part) use ($picks): string {
            $pick = $picks[$part] ?? null;

            return $pick === null
                ? $part
                : $part . ' → L' . $pick['level'] . ' (' . (self::KIND_WORDS[$pick['kind']] ?? $pick['kind']) . ')';
        }, $parts));
    }

    /**
     * What the swaps cost now: a part's season pick at its price, any other part at its listed price.
     *
     * @param list<array{part: string, cost: int}> $swaps
     */
    private static function pickedCost(EngineerInputs $in, array $swaps): int
    {
        $picks = array_column($in->swapPicks, 'cost', 'part');
        $total = 0;
        foreach ($swaps as $swap) {
            $total += $picks[$swap['part']] ?? $swap['cost'];
        }

        return $total;
    }

    /**
     * @return Line
     */
    private static function training(EngineerInputs $in): array
    {
        if ($in->driverTrained) {
            return self::line(
                'training',
                'Driver trained this race',
                'Nothing more to spend on it this race',
                '/cockpit#cockpit-training',
            );
        }

        $pick = $in->trainingPick;
        if ($pick === null) {
            return self::line(
                'training',
                self::NOTHING,
                'No course is worth the money right now',
                '/cockpit#cockpit-training',
            );
        }

        if (!self::affords($in, $pick['cost'], self::STAGE_TRAINING)) {
            return self::line(
                'training',
                'Skip ' . $pick['name'] . ' training',
                self::skipReason($in, self::STAGE_TRAINING),
                '/cockpit#cockpit-training',
                Money::short($pick['cost']),
            );
        }

        return self::line(
            'training',
            'Train ' . $pick['name'],
            self::affordReason($in),
            '/cockpit#cockpit-training',
            Money::short($pick['cost']),
        );
    }

    /**
     * @return Line
     */
    private static function testing(EngineerInputs $in): array
    {
        if ($in->tested) {
            return self::line(
                'testing',
                'Testing done this race',
                'Nothing more to spend on it this race',
                '/cockpit#cockpit-testing',
            );
        }

        $target = $in->testingTarget;
        if (!$in->testingOpen || $target === null) {
            return self::line('testing', self::NOTHING, 'No testing session is open', '/cockpit#cockpit-testing');
        }

        $anchor = '/cockpit#cockpit-testing';
        $where = $in->testingTrack === null ? 'Test this week' : 'Test at ' . $in->testingTrack;
        $skip = $in->testingTrack === null ? 'Skip testing this week' : 'Skip testing at ' . $in->testingTrack;
        $tunes = 'tunes for ' . ($target['track_name'] ?? 'race ' . $target['race'])
            . ($target['track_name'] === null ? '' : ' (race ' . $target['race'] . ')');
        $cost = $in->testingCost;

        if (self::testingSpend($in) === null) {
            // The Cockpit's training laps feed the wear every plan reads; a skipped session should stop them.
            return $in->testingLaps > 0
                ? self::line(
                    'testing',
                    $skip,
                    self::skipReason($in, self::STAGE_TESTING) . '; set training laps to 0 on the Cockpit (it counts '
                        . $in->testingLaps . ' laps of wear)',
                    '/cockpit#cockpit-wear',
                    Money::short($cost),
                )
                : self::line(
                    'testing',
                    $skip,
                    self::skipReason($in, self::STAGE_TESTING),
                    $anchor,
                    Money::short($cost),
                );
        }

        return self::line('testing', $where, self::affordReason($in) . '; ' . $tunes, $anchor, Money::short($cost));
    }

    /**
     * What the testing line spends: the session cost when a session is open, aimed and affordable; null when
     * the line spends nothing.
     */
    private static function testingSpend(EngineerInputs $in): ?int
    {
        $cost = $in->testingCost;
        if ($in->tested || !$in->testingOpen || $in->testingTarget === null) {
            return null;
        }

        return self::affords($in, $cost, self::STAGE_TESTING) ? $cost : null;
    }

    /**
     * What is left of the plan's envelope for car upgrades, after the lines that draw first: the season
     * picks, training, then testing.
     */
    public static function upgradeBudget(EngineerInputs $in): int
    {
        if ($in->plan === null) {
            return 0;
        }

        return max(0, self::envelopeLeft($in, self::STAGE_TESTING) - (self::testingSpend($in) ?? 0));
    }

    /**
     * @return ?Line
     */
    private static function upgrades(EngineerInputs $in): ?array
    {
        $plan = $in->plan;
        if ($in->carUpdated || $plan === null || !isset($plan['upgrades'])) {
            return null;
        }

        $upgrades = $plan['upgrades'];
        if ($upgrades === [] && ($plan['infeasible'] ?? false)) {
            return self::line(
                'upgrades',
                'No upgrade fits the budget',
                'The season plan already runs short',
                self::UPGRADES_ANCHOR,
            );
        }
        if ($upgrades === []) {
            return self::line(
                'upgrades',
                'Nothing worth buying',
                'No level above yours adds PHA for the remaining tracks',
                self::UPGRADES_ANCHOR,
            );
        }

        $races = count($plan['rows']);
        foreach ($upgrades as $upgrade) {
            if ($upgrade['affordable']) {
                return self::line(
                    'upgrades',
                    'Upgrade ' . $upgrade['part'] . ' to L' . $upgrade['to'],
                    'Most PHA per $ over the ' . $races . ($races === 1 ? ' race' : ' races') . ' left'
                        . self::carClause($in),
                    self::UPGRADES_ANCHOR,
                    $upgrade['net_cost'] > 0 ? Money::short($upgrade['net_cost']) . ' net' : 'no extra cost',
                );
            }
        }

        $best = $upgrades[0];
        $budget = self::upgradeBudget($in);
        // A net that fits was refused for its price: paid now, while the repair it saves lands later.
        $cost = $best['net_cost'] <= $budget
            ? ' needs ' . Money::short($best['price']) . ' up front'
            : ' for a net ' . Money::short($best['net_cost']);

        return self::line(
            'upgrades',
            'No upgrade fits the budget',
            'Best: ' . $best['part'] . ' L' . $best['to'] . $cost
                . ($budget > 0 ? '; ' . Money::short($budget) . ' left' : '; nothing left'),
            self::UPGRADES_ANCHOR,
        );
    }

    private static function carClause(EngineerInputs $in): string
    {
        $car = $in->carStanding;

        return $car === null ? '' : '; car level ' . $car['level'] . ', ' . Ordinal::of($car['rank'])
            . ' of ' . $car['of'] . ' in your group';
    }

    /**
     * @return ?Line
     */
    private static function staff(EngineerInputs $in): ?array
    {
        $staff = $in->staffStanding;
        if ($staff === null || !$staff['nudge']) {
            return null;
        }

        return self::line(
            'staff',
            'Staff and facilities trail your group',
            'Staff OA ' . Ordinal::of($staff['rank']) . ' of ' . $staff['of'] . '; the ' . $staff['band']
                . ' third averages ' . $staff['band_average'] . ', you have ' . $staff['own'],
            tone: 'amber',
        );
    }

    /**
     * @return ?Line
     */
    private static function risk(EngineerInputs $in): ?array
    {
        if ($in->risk === null) {
            return null;
        }

        $strategy = ['label' => 'Settings in Strategy', 'href' => '/strategy'];
        if ($in->weatherStale) {
            return self::line('risk', self::STALE, 'The advice reads the saved setup and weather', action: $strategy);
        }

        $stay = self::objectiveIs($in, SeasonObjective::STAY);
        $overtake = $stay ? max(0, $in->risk['overtake'] - self::STAY_OVERTAKE_STEP) : $in->risk['overtake'];

        return self::line(
            'risk',
            'Overtake ' . $overtake . ' · Defend ' . $in->risk['defend'],
            $stay
                ? 'Overtake lowered by ' . self::STAY_OVERTAKE_STEP . ' to cut the cost of mistakes'
                : 'Set for this track and driver',
            action: $strategy,
        );
    }

    /**
     * @return ?Line
     */
    private static function boost(EngineerInputs $in): ?array
    {
        if ($in->boostLitres === null) {
            return null;
        }

        $strategy = ['label' => 'Laps in Strategy', 'href' => '/strategy'];
        if ($in->weatherStale) {
            return self::line('boost', self::STALE, 'The fuel figure reads the saved setup', action: $strategy);
        }
        if (self::objectiveIs($in, SeasonObjective::STAY)) {
            return self::line('boost', 'No boost — save the fuel', 'Stay: skip the extra fuel');
        }

        return self::line(
            'boost',
            'Boost: ' . self::BOOST_SETS . ' sets · +' . $in->boostLitres . ' L',
            'Extra fuel for ' . self::BOOST_SETS . ' sets of ' . self::BOOST_LAPS_PER_SET
                . ' laps; Strategy picks the laps',
            action: $strategy,
        );
    }

    /**
     * What the season picks for the parts the plan replaces add to it: each pick's net cost over the
     * same-level part the plan already pays for. A pick that saves money frees nothing up front.
     */
    private static function forcedPickSpend(EngineerInputs $in): int
    {
        $planned = self::planned($in);
        $spend = 0;
        foreach ($in->swapPicks as $pick) {
            if (in_array($pick['part'], $planned, true)) {
                $spend += max(0, $pick['net_cost']);
            }
        }

        return $spend;
    }

    /**
     * The plan's envelope once the season picks' net cost is paid for.
     */
    private static function afterPicks(EngineerInputs $in): int
    {
        return ($in->plan['envelope'] ?? 0) - self::forcedPickSpend($in);
    }

    /**
     * @return list<string>
     */
    private static function planned(EngineerInputs $in): array
    {
        return $in->plan === null ? [] : array_column($in->plan['rows'][0]['replaced'], 'part');
    }

    /**
     * What is left of the plan's envelope when a discretionary line is judged: the season picks first, then
     * training, then testing.
     */
    private static function envelopeLeft(EngineerInputs $in, string $stage): int
    {
        $left = self::afterPicks($in);
        $pick = $in->trainingPick;
        if ($stage === self::STAGE_TESTING && !$in->driverTrained && $pick !== null && $pick['cost'] <= $left) {
            $left -= $pick['cost'];
        }

        return $left;
    }

    private static function affords(EngineerInputs $in, int $cost, string $stage): bool
    {
        if ($in->plan !== null) {
            return $cost <= self::envelopeLeft($in, $stage);
        }

        $reserve = self::objectiveIs($in, SeasonObjective::STAY) ? $in->forcedSwapCost ?? 0 : 0;

        return self::spendable($in) >= $cost + $reserve;
    }

    private static function skipReason(EngineerInputs $in, string $stage): string
    {
        if ($in->plan !== null) {
            $left = self::envelopeLeft($in, $stage);

            return $left > 0 ? 'Only ' . Money::short($left) . ' left in the budget' : 'Nothing left in the budget';
        }

        $known = $in->outlook['known'];
        if (self::objectiveIs($in, SeasonObjective::STAY)) {
            return $known
                ? 'Stay: repairs come before anything optional'
                : 'Stay: your balance must cover repairs first; season costs unknown';
        }

        return $known ? 'Free cash does not cover it' : 'Your balance does not cover it; season costs unknown';
    }

    private static function affordReason(EngineerInputs $in): string
    {
        return ucfirst(self::affordClause($in, 'it'));
    }

    private static function affordClause(EngineerInputs $in, string $what): string
    {
        if ($in->plan !== null) {
            return 'the budget covers ' . $what;
        }

        $subject = $in->outlook['known'] ? 'free cash' : 'your balance';
        $tail = self::objectiveIs($in, SeasonObjective::STAY) ? ' and the forced repairs' : '';
        $note = $in->outlook['known'] ? '' : '; season costs unknown';

        return $subject . ' covers ' . $what . $tail . $note;
    }

    private static function spendable(EngineerInputs $in): int
    {
        return self::free($in) ?? $in->cash;
    }

    private static function free(EngineerInputs $in): ?int
    {
        return $in->outlook['known'] ? $in->outlook['free_cash'] : null;
    }

    private static function objectiveIs(EngineerInputs $in, string $objective): bool
    {
        return $in->objective['objective'] === $objective;
    }

    /**
     * @param ?array{label: string, href: string} $action
     * @return Line
     */
    private static function line(
        string $key,
        string $answer,
        string $reason,
        ?string $anchor = null,
        ?string $figure = null,
        string $tone = 'ink',
        ?array $action = null,
    ): array {
        return [
            'key' => $key,
            'answer' => $answer,
            'figure' => $figure,
            'reason' => $reason,
            'anchor' => $anchor,
            'tone' => $tone,
            'action' => $action,
            'step' => self::STEPS[$key],
        ];
    }
}
