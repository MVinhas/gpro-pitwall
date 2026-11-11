<?php

declare(strict_types=1);

namespace App\Engineer;

use App\Service\WearAdvisorService;
use App\Support\Ordinal;

/**
 * @phpstan-import-type Plan from CtrPlanner
 * @phpstan-import-type CtrValueFigures from CtrPlanner
 * @phpstan-import-type Upgrade from UpgradeRanker
 * @phpstan-import-type SwapPick from EngineerInputs
 * @phpstan-import-type SmokeQuote from EngineerInputs
 * @phpstan-import-type TyreAdvice from TyreSupplierAdvisor
 * @phpstan-import-type DriverAdvice from DriverHiringAdvisor
 * @phpstan-type Line array{
 *     key: string,
 *     answer: string,
 *     figure: ?string,
 *     reason: string,
 *     anchor: ?string,
 *     tone: string,
 *     action: ?array{label: string, href: string},
 *     step: 'season'|'car'|'prep'|'race',
 *     choice?: Choice
 * }
 * @phpstan-type Choice array{item: string, chosen: ?string, season: int, race: int}
 */
final class RaceEngineer
{
    private const string SMOKE_COST = 'expect smoke and lost time, and a retirement if it comes early';
    private const string SMOKE_BREAK = 'halves the race money and upsets sponsors';
    /** GPRO blocks testing above 90 %: a part let smoke from above it would cost the session. */
    private const int TESTING_WEAR_LIMIT = 90;

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
    private const string NOT_RANKED = 'No division baseline: drivers not ranked';
    private const string SKIP_CALL = 'Your call; the money stays in the budget';
    private const string AMATEUR_TESTING = 'Testing is weak in Amateur without a technical director; it pays mainly '
        . 'the season before a promotion push';

    /** Which step of the weekend each line belongs to; brief() keeps lines of one step together. */
    public const array STEPS = [
        'objective' => 'season',
        'tyres' => 'season',
        'driver' => 'season',
        'cash' => 'season',
        'staff' => 'season',
        'stale' => 'season',
        'parts' => 'car',
        'smoke' => 'car',
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
        if ($in->driverAdvice !== null) {
            $lines[] = self::driverLine($in->driverAdvice);
        }

        $optional = [
            self::cash($in),
            self::staff($in),
            self::parts($in),
            self::smoke($in),
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
     * @param DriverAdvice $advice
     * @return Line
     */
    public static function driverLine(array $advice): array
    {
        $verdict = $advice['verdict'];
        $needsShortlist = $verdict === 'replace' || $verdict === 'hire';
        $name = $advice['current']['name'] ?? '';
        $left = $advice['current']['races_left'] ?? 0;
        $pick = $advice['shortlist'][0] ?? null;
        $figure = null;
        $suffixed = true;

        if ($needsShortlist && !$advice['market_known']) {
            $answer = 'Driver market unavailable';
            $reason = 'Open Recruitment to retry';
            $tone = 'amber';
        } elseif ($needsShortlist && !$advice['ranked']) {
            $answer = $verdict === 'hire' ? 'Sign a driver' : 'Replace ' . $name;
            $reason = self::NOT_RANKED;
            $tone = $verdict === 'hire' ? 'red' : 'amber';
            $suffixed = false;
        } elseif ($needsShortlist && $pick === null) {
            $answer = 'No driver passes your rules';
            $reason = match ($advice['top_out']) {
                'retiring' => 'Most are retiring',
                'cap' => "Most are over your division's cap",
                'age' => 'Most are 35 or older',
                'offers' => 'Most already have offers',
                'salary_average' => "Most cost more than your group's average",
                'salary_plan' => "Most salaries don't fit the plan",
                'switch_cost' => 'Most cost too much to sign',
                default => 'The market has nobody for your division',
            };
            $tone = $verdict === 'hire' ? 'red' : 'amber';
        } elseif ($verdict === 'keep') {
            $answer = 'Keep ' . $name;
            $figure = self::races($left) . ' left';
            [$reason, $tone] = match ($advice['reason']) {
                'over_cap' => [
                    'Over the ' . ($advice['cap'] ?? 0) . ' cap: cannot be extended, contract ends in '
                        . self::races($left),
                    'amber',
                ],
                'no_extension' => ['GPRO offers no extension now', 'ink'],
                'retiring_later' => ['Retires at the end of the season', 'amber'],
                default => [match (true) {
                    !$advice['market_known'] => 'Driver market unavailable: nobody was compared',
                    !$advice['ranked'] => self::NOT_RANKED,
                    default => 'No clearly better driver you can afford',
                }, 'ink'],
            };
            $suffixed = $advice['reason'] !== 'none';
        } elseif ($verdict === 'extend') {
            $answer = 'Extend ' . $name . ' by ' . DriverHiringAdvisor::CONTRACT_RACES . ' races';
            $extension = $advice['extension'];
            if ($extension !== null) {
                $figure = ($extension['exact'] ? '' : 'about ') . Money::short($extension['fee']) . ' + '
                    . Money::short($extension['salary']) . ' a race';
            }
            if ($advice['reason'] === 'extension_budget_short') {
                $reason = 'Budget short: no race without a driver';
                $tone = 'red';
            } elseif ($advice['urgent']) {
                $reason = 'Last chance: ' . ($left > 0 ? self::races($left) . ' left' : 'his contract has run out');
                $tone = 'red';
            } else {
                $reason = self::races($left) . ' left on his contract';
                $tone = 'amber';
            }
        } else {
            $answer = ($verdict === 'hire' ? 'Sign ' : 'Replace ' . $name . ' with ') . ($pick['name'] ?? '');
            $figure = Money::short($pick['salary'] ?? 0) . ' a race';
            $reason = match ($advice['reason']) {
                'leaving_cap' => 'Over the ' . ($advice['cap'] ?? 0) . ' cap: he leaves in ' . self::races($left),
                'leaving_retiring' => 'He retires at the end of the season',
                'better' => 'Scores ' . number_format($pick['gain'] ?? 0.0, 1) . ' points higher than ' . $name,
                'extension_over_budget' => 'His extension does not fit the plan',
                default => 'No driver under contract',
            };
            $tone = 'amber';
        }

        if ($suffixed && $advice['market_known'] && !$advice['ranked']) {
            $reason .= '; no division baseline, drivers not ranked';
        }
        if ($suffixed && $verdict !== 'keep' && !$advice['finances_known']) {
            $reason .= '; finances unknown';
        }

        return self::line(
            'driver',
            $answer,
            $reason,
            anchor: '/engineer#engineer-driver',
            figure: $figure,
            tone: $tone,
        );
    }

    private static function races(int $count): string
    {
        return $count === 1 ? '1 race' : $count . ' races';
    }

    /**
     * What the plan's envelope has left after everything the card advises spending on the car: the card buys one
     * upgrade, the recommended one, and the envelope already carries its net cost (see afterTheUpdate).
     */
    public static function spare(EngineerInputs $in): int
    {
        if ($in->plan === null) {
            return 0;
        }

        return max(0, self::upgradeBudget($in) - max(0, self::recommendedUpgrade($in)['net_cost'] ?? 0));
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

        return self::reaches($in->stuckSmoke) . ' and the car is already updated: CTR 0 keeps '
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
        $underBar = $next === null ? '' : 'CTR ' . $next['ctr'] . ' would net only ' . Money::short($next['net_more'])
            . ' more, under the ' . Money::short(CtrValue::MARGIN) . ' bar';
        if ($ctr === 0) {
            return match (true) {
                !$value['pace_known'] => 'No pace figures for the remaining tracks: the lowest wear wins',
                $next === null => 'A higher CTR earns less than its repairs cost',
                $next['net_more'] > 0 => "A higher CTR isn't worth the wear: " . $underBar,
                default => 'A higher CTR earns less than its repairs cost (CTR ' . $next['ctr'] . ': '
                    . self::signed($next['gain_more']) . ', repairs ' . self::signed($next['cost_more']) . ')',
            };
        }

        $pick = 'About +' . number_format($value['places'], 1) . ' places a race (' . self::signed($value['gain'])
            . ' this season); ';

        return $pick . match (true) {
            $next === null => 'no higher CTR fits the budget',
            $next['net_more'] > 0 => $underBar,
            default => 'CTR ' . $next['ctr'] . ' would earn ' . Money::short($next['gain_more']) . ' more but cost '
                . Money::short($next['cost_more']) . ' more in repairs',
        };
    }

    /**
     * A money delta with its sign: Money::short already carries the minus.
     */
    private static function signed(int $amount): string
    {
        return ($amount < 0 ? '' : '+') . Money::short($amount);
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
            . match (true) {
                $above > 1 => $above . ' places above the relegation zone',
                $above === 1 => '1 place above the relegation zone',
                default => 'inside the relegation zone',
            }
            . ': the highest CTR the budget and energy allow';
    }

    /**
     * @param list<string> $parts
     */
    private static function reaches(array $parts): string
    {
        return implode(', ', $parts) . (count($parts) === 1 ? ' reaches' : ' reach') . ' 100 % this race';
    }

    /**
     * @param list<string> $parts
     */
    private static function possessive(array $parts): string
    {
        return count($parts) === 1 ? 'its' : 'their';
    }

    /**
     * Null when every forced part is let smoke and nothing unlocks testing: the smoke line stands in for it.
     *
     * @return ?Line
     */
    private static function parts(EngineerInputs $in): ?array
    {
        if ($in->carUpdated) {
            return self::updatedParts($in);
        }

        $plan = $in->plan;
        $smoked = self::letSmoke($in);
        $replaced = array_values(array_filter(
            $plan === null ? [] : $plan['rows'][0]['replaced'],
            static fn (array $swap): bool => !in_array($swap['part'], $smoked, true),
        ));
        $forced = array_values(array_diff(self::forced($in), $smoked));
        $forcedSwapCost = $plan === null
            ? ($in->forcedSwapCost === null ? null : $in->forcedSwapCost - self::freedBy($in, $smoked))
            : self::pickedCost($in, $replaced);
        $unlocked = array_column($in->unlockPicks, 'part');
        $unlockCost = array_sum(array_column($in->unlockPicks, 'cost'));
        $swapped = [...$forced, ...$unlocked];
        $clauses = self::unlockClauses($in, count($swapped) > 1);
        $unlock = $clauses === [] ? '' : '; ' . implode('; ', $clauses);

        if ($forced === [] && $unlocked !== []) {
            return self::pickedLine(
                $in,
                $swapped,
                'Swap ' . self::swapNames($in, $swapped),
                ucfirst(implode('; ', $clauses)),
                Money::short($unlockCost),
                'ink',
            );
        }

        if ($forced === [] && $smoked !== []) {
            return null;
        }

        // A part that ends the race below 100 % stays on the car: a swap would only spend money.
        if ($forced === []) {
            return self::line('parts', 'Nothing to swap', 'No forced swap this race', '/cockpit#cockpit-wear');
        }

        // A push swap let smoke no longer buys the push: the line falls back to the forced reasons.
        $pushing = $plan !== null && $plan['mode'] === 'push'
            && array_diff(array_column($plan['swaps'], 'part'), $smoked) !== [];
        if ($pushing) {
            return self::pickedLine(
                $in,
                $swapped,
                'Swap ' . self::swapNames($in, $swapped),
                'Lets you push to CTR ' . $plan['ctr'] . $unlock,
                Money::short(($forcedSwapCost ?? 0) + $unlockCost),
                'ink',
            );
        }

        $breaks = false;
        $lapped = false;
        $reasons = [];
        foreach ($forced as $part) {
            $risk = $in->smoke[$part]['risk'] ?? null;
            $lapped = $lapped || $risk !== null;
            $name = count($swapped) > 1 ? $part : null;
            $breaks = $breaks || ($risk?->likelyBreak() ?? false);
            $reasons[] = $risk?->describe($name)
                ?? ($name === null ? 'Reaches' : $name . ' reaches') . ' 100 % this race';
        }
        $short = $plan === null && self::spendable($in) < ($forcedSwapCost ?? 0);

        return self::pickedLine(
            $in,
            $swapped,
            'Swap ' . self::swapNames($in, $swapped),
            implode('; ', $reasons) . $unlock,
            $forcedSwapCost === null ? null : Money::short($forcedSwapCost + $unlockCost),
            $breaks || $short ? 'red' : 'amber',
            $lapped,
        );
    }

    /**
     * @return Line
     */
    private static function updatedParts(EngineerInputs $in): array
    {
        $stuck = $in->stuckSmoke;
        if ($stuck === []) {
            return self::line(
                'parts',
                'Car updated',
                'One update per race; the plan reads the car as it is now',
                '/cockpit#cockpit-wear',
            );
        }

        $breaks = false;
        $described = [];
        foreach ($stuck as $part) {
            $risk = $in->smoke[$part]['risk'] ?? null;
            if ($risk === null) {
                $described = null;
                break;
            }
            $described[] = $risk->describe($part);
            $breaks = $breaks || $risk->likelyBreak();
        }
        $reason = $described === null ? self::reaches($stuck) . ': ' . self::SMOKE_COST : implode('; ', $described);

        return self::line(
            'parts',
            'Car updated',
            $reason . '; no more changes until the next race',
            '/cockpit#cockpit-wear',
            tone: $described !== null && $breaks ? 'red' : 'amber',
        );
    }

    /**
     * The parts this race forces out: the plan's replacements, or the Cockpit's swaps without a plan.
     *
     * @return list<string>
     */
    private static function forced(EngineerInputs $in): array
    {
        $rows = $in->plan === null ? $in->wearAdvice['swap'] ?? [] : $in->plan['rows'][0]['replaced'];

        return array_column($rows, 'part');
    }

    /**
     * The forced parts the engineer lets smoke this race instead of swapping, in the forced order: every one
     * whose deferral saves more than its smoke costs, then, while the forced swaps don't fit the money, the
     * least likely to break until the shortfall is freed. A likely break, an unpriced smoke, or a part that
     * would block an open testing session is never let smoke.
     *
     * @return list<string>
     */
    public static function letSmoke(EngineerInputs $in): array
    {
        if ($in->carUpdated) {
            return [];
        }

        $testing = $in->testingOpen && $in->testingTarget !== null;
        $candidates = [];
        foreach (self::forced($in) as $part) {
            $quote = $in->smoke[$part] ?? null;
            if (
                $quote === null
                || $quote['risk']->likelyBreak()
                || $quote['price'] === null
                || ($testing && WearAdvisorService::percent($quote['start']) > self::TESTING_WEAR_LIMIT)
            ) {
                continue;
            }
            $freed = self::freed($in, $part, $quote);
            // A free swap (a spare, a free downgrade) frees nothing and saves nothing by waiting.
            if ($freed !== null && $freed > 0) {
                $candidates[$part] = ['quote' => $quote, 'freed' => $freed];
            }
        }

        $taken = array_filter($candidates, static fn (array $c): bool => self::valueSmoke($c['quote']));
        $rest = array_diff_key($candidates, $taken);
        uksort($rest, static fn (string $a, string $b): int => [
            $rest[$a]['quote']['risk']->breakChance, $rest[$b]['freed'], $a,
        ] <=> [
            $rest[$b]['quote']['risk']->breakChance, $rest[$a]['freed'], $b,
        ]);
        $shortfall = self::shortfall($in) - array_sum(array_column($taken, 'freed'));
        foreach ($rest as $part => $candidate) {
            if ($shortfall <= 0) {
                break;
            }
            $taken[$part] = $candidate;
            $shortfall -= $candidate['freed'];
        }

        return array_values(array_filter(
            self::forced($in),
            static fn (string $part): bool => isset($taken[$part]),
        ));
    }

    /**
     * @param SmokeQuote $quote
     */
    private static function valueSmoke(array $quote): bool
    {
        return $quote['saving'] !== null && $quote['price'] !== null && $quote['saving'] > $quote['price']['total'];
    }

    /**
     * What swapping $part would cost now, and so what letting it smoke frees: its season pick, else its
     * replacement in the plan; without a plan, the Cockpit's recommended option.
     *
     * @param SmokeQuote $quote
     */
    private static function freed(EngineerInputs $in, string $part, array $quote): ?int
    {
        if ($in->plan === null) {
            return $quote['cost'];
        }
        $pick = array_column($in->swapPicks, 'cost', 'part')[$part] ?? null;

        return $pick ?? array_column($in->plan['rows'][0]['replaced'], 'cost', 'part')[$part] ?? null;
    }

    /**
     * @param list<string> $parts
     */
    private static function freedBy(EngineerInputs $in, array $parts): int
    {
        $total = 0;
        foreach ($parts as $part) {
            $quote = $in->smoke[$part] ?? null;
            $total += $quote === null ? 0 : self::freed($in, $part, $quote) ?? 0;
        }

        return $total;
    }

    /**
     * How far the forced swaps overrun the money: the plan's envelope after its picks, plus what a short plan
     * already misses its target by; without a plan, the swaps over what can be spent. Zero or less fits.
     */
    private static function shortfall(EngineerInputs $in): int
    {
        if ($in->plan !== null) {
            $short = $in->plan['mode'] === 'short' ? $in->plan['shortfall'] ?? 0 : 0;

            return $short - self::afterPicks($in);
        }

        return ($in->forcedSwapCost ?? 0) - self::spendable($in);
    }

    /**
     * @return ?Line
     */
    private static function smoke(EngineerInputs $in): ?array
    {
        $parts = self::letSmoke($in);
        if ($parts === []) {
            return null;
        }

        /** @var array<string, SmokeQuote> $quotes */
        $quotes = array_intersect_key($in->smoke, array_flip($parts));
        $value = array_filter($quotes, self::valueSmoke(...));
        $figure = count($value) === count($quotes)
            ? 'saves ~' . Money::short(array_sum(array_map(
                static fn (array $q): int => ($q['saving'] ?? 0) - ($q['price']['total'] ?? 0),
                $quotes,
            )))
            : 'frees ' . Money::short(self::freedBy($in, $parts)) . ' this race';

        $last = array_pop($parts);
        $names = $parts === [] ? $last : implode(', ', $parts) . ' and ' . $last;
        $parts[] = $last;

        return self::line(
            'smoke',
            'Let the ' . $names . ' smoke this race',
            count($parts) === 1 ? self::smokeReason($quotes[$last], null) : self::smokeReasons($quotes),
            '/cockpit#cockpit-wear',
            $figure,
            'amber',
        );
    }

    /**
     * @param SmokeQuote $quote
     */
    private static function smokeReason(array $quote, ?string $part): string
    {
        $odds = self::breaks($quote) ? self::odds($quote) . ', which ' . self::SMOKE_BREAK : self::odds($quote);

        return self::smokeClause($quote, $part) . '; ' . $odds . '; ' . self::smokeTail($quote, $part);
    }

    /**
     * @param array<string, SmokeQuote> $quotes
     */
    private static function smokeReasons(array $quotes): string
    {
        $clauses = [];
        $tails = [];
        $breaks = false;
        foreach ($quotes as $part => $quote) {
            $clauses[] = self::smokeClause($quote, $part) . ', ' . self::odds($quote);
            $tails[] = self::smokeTail($quote, $part);
            $breaks = $breaks || self::breaks($quote);
        }
        $break = $breaks ? '; a break ' . self::SMOKE_BREAK : '';

        return implode('; ', $clauses) . $break . '; ' . implode('; ', $tails);
    }

    /**
     * @param SmokeQuote $quote
     */
    private static function smokeClause(array $quote, ?string $part): string
    {
        $risk = $quote['risk'];

        return ($part === null ? 'Reaches' : $part . ' reaches') . ' 100 % on lap ' . $risk->lap . ' of '
            . $risk->laps . ': about ' . $risk->smokeLaps . ($risk->smokeLaps === 1 ? ' lap' : ' laps')
            . ' of smoke (~' . Money::short($quote['price']['time'] ?? 0) . ' in places)';
    }

    /**
     * @param SmokeQuote $quote
     */
    private static function pct(array $quote): int
    {
        return (int) round($quote['risk']->breakChance * 100);
    }

    /**
     * @param SmokeQuote $quote
     */
    private static function breaks(array $quote): bool
    {
        return self::pct($quote) > 0;
    }

    /**
     * @param SmokeQuote $quote
     */
    private static function odds(array $quote): string
    {
        return self::breaks($quote) ? self::pct($quote) . ' % chance it breaks' : 'too short to break';
    }

    /**
     * @param SmokeQuote $quote
     */
    private static function smokeTail(array $quote, ?string $part): string
    {
        $it = $part === null ? 'it' : 'the ' . $part;

        return match (true) {
            !self::valueSmoke($quote) => 'replace ' . $it . ' next race',
            $quote['last'] => $part === null
                ? 'no replacement is due before the season ends'
                : 'no replacement for the ' . $part . ' is due before the season ends',
            default => 'fitting ' . $it . ' next race saves ' . Money::short($quote['saving'] ?? 0)
                . ' in parts this season',
        };
    }

    /**
     * Why each unlock swap is on the parts line; the part is named when the line swaps more than one.
     *
     * @return list<string>
     */
    private static function unlockClauses(EngineerInputs $in, bool $named): array
    {
        $wear = array_column(self::gate($in)->blocking, 'wear', 'part');

        return array_map(static function (array $pick) use ($wear, $named): string {
            $at = isset($wear[$pick['part']]) ? ' (it is at ' . $wear[$pick['part']] . ' %)' : '';

            return ($named ? lcfirst($pick['part']) . ' ' : '') . 'to unlock testing' . $at;
        }, $in->unlockPicks);
    }

    /**
     * The parts line, with the reasons of the season picks for $parts after today's reason. A pick that only
     * had a budget-short option left replaces the reason: that is what the manager has to know. Next to an
     * unlock swap, or after the lap a part reaches 100 % ($keepReason), it is appended instead.
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
        bool $keepReason = false,
    ): array {
        $reasons = [];
        foreach ([...$in->swapPicks, ...$in->unlockPicks] as $pick) {
            if (in_array($pick['part'], $parts, true)) {
                $reasons[] = $pick['reason'];
            }
        }
        $reasons = array_values(array_unique($reasons));

        $unlocking = array_intersect(array_column($in->unlockPicks, 'part'), $parts) !== [];
        if (in_array('budget_short', $reasons, true)) {
            $reason = $unlocking || $keepReason
                ? $reason . '; ' . lcfirst(SwapLevelPicker::REASONS['budget_short'])
                : SwapLevelPicker::REASONS['budget_short'];
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
        $picks = array_column([...$in->swapPicks, ...$in->unlockPicks], null, 'part');

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

        $anchor = '/cockpit#cockpit-training';
        $figure = Money::short($pick['cost']);
        $train = 'Train ' . $pick['name'];
        $skip = 'Skip ' . $pick['name'] . ' training';
        $choice = self::choice($in, 'training', $in->trainingChoice);

        if ($in->trainingChoice === 'do') {
            [$reason, $tone] = self::doReason($in, $pick['cost'], self::STAGE_TRAINING);

            return self::line('training', $train, $reason, $anchor, $figure, $tone, choice: $choice);
        }
        if ($in->trainingChoice === 'skip') {
            return self::line('training', $skip, self::SKIP_CALL, $anchor, $figure, choice: $choice);
        }
        if (!self::affords($in, $pick['cost'], self::STAGE_TRAINING)) {
            return self::line(
                'training',
                $skip,
                self::skipReason($in, self::STAGE_TRAINING),
                $anchor,
                $figure,
                choice: $choice,
            );
        }

        return self::line('training', $train, self::affordReason($in), $anchor, $figure, choice: $choice);
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

        $anchor = '/cockpit#cockpit-testing';
        $target = $in->testingTarget;
        $gate = self::gate($in);
        if ($gate->state === TestingGate::CLOSED || $target === null) {
            return self::line('testing', self::NOTHING, 'No testing session is open', $anchor);
        }
        if ($gate->state === TestingGate::ROOKIE) {
            return self::line('testing', 'No testing in Rookie', "Testing doesn't pay in Rookie", $anchor);
        }
        if ($gate->state === TestingGate::BLOCKED) {
            // Once the car is updated the Cockpit zeroes the laps itself; before, it keeps them for a possible unlock.
            $laps = !$in->carUpdated && $in->testingLaps > 0
                ? '; set training laps to 0 on the Cockpit (it counts ' . $in->testingLaps . ' laps of wear)'
                : '';

            return self::line('testing', 'Testing blocked', self::blockedReason($in, $gate) . $laps, $anchor);
        }

        $where = $in->testingTrack === null ? 'Test this week' : 'Test at ' . $in->testingTrack;
        $skip = $in->testingTrack === null ? 'Skip testing this week' : 'Skip testing at ' . $in->testingTrack;
        $tunes = 'tunes for ' . ($target['track_name'] ?? 'race ' . $target['race'])
            . ($target['track_name'] === null ? '' : ' (race ' . $target['race'] . ')');
        $cost = $in->testingCost;
        $figure = Money::short($cost);
        $choice = self::choice($in, 'testing', $in->testingChoice);

        if ($in->testingChoice === 'do') {
            [$reason, $tone] = self::doReason($in, $cost, self::STAGE_TESTING);

            return self::line('testing', $where, $reason, $anchor, $figure, $tone, choice: $choice);
        }
        if ($in->testingChoice === 'skip') {
            return self::line('testing', $skip, self::SKIP_CALL, $anchor, $figure, choice: $choice);
        }
        $own = self::testingSkip($in);
        if ($own !== null) {
            $laps = $in->testingLaps > 0
                ? '; press Decline so the Cockpit stops counting ' . $in->testingLaps . ' testing laps'
                : '';

            return self::line('testing', $skip, $own . $laps, $anchor, $figure, choice: $choice);
        }

        $unlock = $gate->state === TestingGate::UNLOCK
            ? ' after swapping ' . implode(' and ', array_map('lcfirst', array_column($gate->blocking, 'part')))
            : '';

        return self::line(
            'testing',
            $where,
            self::affordReason($in) . $unlock . '; ' . $tunes,
            $anchor,
            $figure,
            choice: $choice,
        );
    }

    private static function blockedReason(EngineerInputs $in, TestingGate $gate): string
    {
        $parts = array_map(
            static fn (array $b): string => $b['part'] . ' at ' . $b['wear'] . ' %',
            $gate->blocking,
        );

        return implode(', ', $parts) . '; GPRO allows no testing above 90 %'
            . ($in->unlockUnaffordable ? "; the swap plus the session doesn't fit the budget" : '');
    }

    /**
     * The reason and tone of a line the manager chose to spend on: an overrun of the budget is flagged.
     *
     * @return array{string, string}
     */
    private static function doReason(EngineerInputs $in, int $cost, string $stage): array
    {
        if ($in->plan === null) {
            return self::affords($in, $cost, $stage)
                ? ['Your call', 'ink']
                : ["Your call; your balance doesn't cover it", 'amber'];
        }

        $over = $cost - self::envelopeLeft($in, $stage);

        return $over > 0
            ? ['Your call; ' . Money::short($over) . ' over the budget, so upgrades get nothing', 'amber']
            : ['Your call', 'ink'];
    }

    /**
     * What the testing line spends: the session cost when GPRO lets the car test and the manager chose it, or
     * left it to the engineer and it is affordable; null when the line spends nothing.
     */
    private static function testingSpend(EngineerInputs $in): ?int
    {
        $cost = $in->testingCost;
        if ($in->tested || $in->testingTarget === null || !self::gate($in)->runs()) {
            return null;
        }

        return match ($in->testingChoice) {
            'do' => $cost,
            'skip' => null,
            default => $in->testingSkip === null
                && !self::amateurStay($in)
                && self::affords($in, $cost, self::STAGE_TESTING) ? $cost : null,
        };
    }

    /**
     * Why the engineer skips a session GPRO would run when the manager left it the call; null when it tests,
     * when the manager decided, or when no session can run.
     */
    public static function testingSkip(EngineerInputs $in): ?string
    {
        if (
            $in->testingChoice !== null
            || $in->tested
            || $in->testingTarget === null
            || !self::gate($in)->runs()
            || self::testingSpend($in) !== null
        ) {
            return null;
        }

        return $in->testingSkip
            ?? (self::amateurStay($in) ? self::AMATEUR_TESTING : self::skipReason($in, self::STAGE_TESTING));
    }

    public static function testingDrawn(EngineerInputs $in): bool
    {
        return self::testingSpend($in) !== null;
    }

    /**
     * What the plan's envelope has left when testing is judged, after the season picks and training; negative
     * when a chosen course overran it.
     */
    public static function testingHeadroom(EngineerInputs $in): int
    {
        return self::envelopeLeft($in, self::STAGE_TESTING);
    }

    private static function gate(EngineerInputs $in): TestingGate
    {
        return $in->testingGate ?? new TestingGate(
            $in->testingOpen && $in->testingTarget !== null ? TestingGate::OPEN : TestingGate::CLOSED,
        );
    }

    private static function amateurStay(EngineerInputs $in): bool
    {
        return $in->division === 'Amateur' && self::objectiveIs($in, SeasonObjective::STAY);
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
        if ($in->carUpdated || $plan === null) {
            return null;
        }
        if (self::letSmoke($in) !== []) {
            return self::line(
                'upgrades',
                'No upgrade this race',
                'Letting a part smoke to save money; upgrades wait for the next race',
                self::UPGRADES_ANCHOR,
            );
        }
        if (!isset($plan['upgrades'])) {
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

        $upgrades = self::worthBuying($in, $upgrades);
        if ($upgrades === []) {
            return self::line(
                'upgrades',
                'Nothing worth buying',
                'Stay: no upgrade pays for itself while your car is out of the group\'s weakest quarter',
                self::UPGRADES_ANCHOR,
            );
        }

        $upgrade = self::recommendedUpgrade($in);
        if ($upgrade !== null) {
            $races = count($plan['rows']);

            return self::line(
                'upgrades',
                'Upgrade ' . $upgrade['part'] . ' to L' . $upgrade['to'],
                'Most PHA per $ over the ' . $races . ($races === 1 ? ' race' : ' races') . ' left'
                    . self::carClause($in),
                self::UPGRADES_ANCHOR,
                $upgrade['net_cost'] > 0 ? Money::short($upgrade['net_cost']) . ' net' : 'no extra cost',
            );
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

    /**
     * The upgrade the upgrades line recommends: the first affordable one the objective lets the car buy. Null
     * when the car is updated, there is no ranking, or nothing worth buying fits.
     *
     * @return ?Upgrade
     */
    public static function recommendedUpgrade(EngineerInputs $in): ?array
    {
        if ($in->carUpdated || $in->plan === null || self::letSmoke($in) !== []) {
            return null;
        }
        foreach (self::worthBuying($in, $in->plan['upgrades'] ?? []) as $upgrade) {
            if ($upgrade['affordable']) {
                return $upgrade;
            }
        }

        return null;
    }

    /**
     * The ranking the objective lets the car buy from: under Stay, while the car is out of the group's weakest
     * quarter, only the upgrades that pay for themselves.
     *
     * @param list<Upgrade> $upgrades
     * @return list<Upgrade>
     */
    private static function worthBuying(EngineerInputs $in, array $upgrades): array
    {
        if (
            !self::objectiveIs($in, SeasonObjective::STAY)
            || !StrengthFloor::fromGroup($in->carStanding)->outOfBottomQuarter()
        ) {
            return $upgrades;
        }

        return array_values(array_filter($upgrades, static fn (array $u): bool => $u['net_cost'] <= 0));
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
     * same-level part the plan already pays for, plus the swaps that unlock testing. A pick that saves money
     * frees nothing up front.
     */
    public static function pickSpend(EngineerInputs $in): int
    {
        $planned = self::planned($in);
        $spend = 0;
        foreach ($in->swapPicks as $pick) {
            if (in_array($pick['part'], $planned, true)) {
                $spend += max(0, $pick['net_cost']);
            }
        }
        foreach ($in->unlockPicks as $pick) {
            $spend += max(0, $pick['net_cost']);
        }

        return $spend;
    }

    /**
     * The plan's envelope once the season picks' net cost is paid for.
     */
    private static function afterPicks(EngineerInputs $in): int
    {
        return ($in->plan['envelope'] ?? 0) - self::pickSpend($in);
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
        if ($stage !== self::STAGE_TESTING || $in->driverTrained || $pick === null) {
            return $left;
        }
        $drawn = match ($in->trainingChoice) {
            'do' => true,
            'skip' => false,
            default => $pick['cost'] <= $left,
        };

        return $drawn ? $left - $pick['cost'] : $left;
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
     * @param ?Choice $choice
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
        ?array $choice = null,
    ): array {
        $line = [
            'key' => $key,
            'answer' => $answer,
            'figure' => $figure,
            'reason' => $reason,
            'anchor' => $anchor,
            'tone' => $tone,
            'action' => $action,
            'step' => self::STEPS[$key],
        ];

        return $choice === null ? $line : $line + ['choice' => $choice];
    }

    /**
     * @return Choice
     */
    private static function choice(EngineerInputs $in, string $item, ?string $chosen): array
    {
        return ['item' => $item, 'chosen' => $chosen, 'season' => $in->season, 'race' => $in->race];
    }
}
