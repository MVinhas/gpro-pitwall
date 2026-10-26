<?php

declare(strict_types=1);

namespace App\Engineer;

use App\Service\CarWearPlannerService;

/**
 * @phpstan-type Line array{
 *     key: string,
 *     answer: string,
 *     figure: ?string,
 *     reason: string,
 *     anchor: ?string,
 *     tone: string,
 *     action: ?array{label: string, href: string}
 * }
 */
final class RaceEngineer
{
    /** Tunable: how much a Survive weekend lowers the advisor's overtake risk. */
    public const int SURVIVE_OVERTAKE_STEP = 15;

    /** Boost sets per race; the caller's extra-fuel litres must use BOOST_SETS * BOOST_LAPS_PER_SET laps. */
    public const int BOOST_SETS = 3;
    public const int BOOST_LAPS_PER_SET = 3;

    private const string SKIP = 'Skip — keep it for repairs';
    private const string NOTHING = 'Nothing to do';
    private const string STALE = 'After you save the race setup';

    /**
     * @return list<Line>
     */
    public static function brief(EngineerInputs $in): array
    {
        $lines = [self::objective($in)];
        if ($in->objective['over']) {
            return $lines;
        }

        $optional = [
            self::cash($in),
            self::ctr($in),
            self::parts($in),
            self::training($in),
            self::testing($in),
            self::risk($in),
            self::boost($in),
        ];

        return [...$lines, ...array_values(array_filter($optional))];
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
            $o['objective'] === null => "Your position in the group isn't known yet; treated as Hold",
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
     * @return ?Line
     */
    private static function ctr(EngineerInputs $in): ?array
    {
        if ($in->ctrCeiling === null) {
            return null;
        }

        $survive = self::objectiveIs($in, SeasonObjective::SURVIVE);
        $push = self::objectiveIs($in, SeasonObjective::PROMOTE)
            && $in->pushAdvice === CarWearPlannerService::ADVICE_PUSH;
        $ceiling = $survive ? ($in->ctrSurvive ?? $in->ctrCeiling) : $in->ctrCeiling;
        $target = $push ? $ceiling : min($in->currentCtr, $ceiling);

        $rated = $in->pushAdvice === null ? '' : 'Race rated ' . $in->pushAdvice . ': ';
        $reason = match (true) {
            $push => $rated . 'the highest CTR where no part breaks',
            $survive && $in->alwaysRed !== [] => self::alwaysRedReason($in->alwaysRed),
            $survive => $rated . 'capped at the highest CTR that keeps parts out of the red',
            default => $rated . 'never above your CTR, and no part breaks',
        };

        return self::line(
            'ctr',
            'CTR ' . $target,
            $reason,
            '#cockpit-wear',
            'On ' . $in->currentCtr . ' now',
            action: $target === $in->currentCtr
                ? null
                : ['label' => 'Use ' . $target, 'href' => '/cockpit?cockpit_risk=' . $target],
        );
    }

    /**
     * @param list<string> $parts
     */
    private static function alwaysRedReason(array $parts): string
    {
        return implode(', ', $parts) . (count($parts) === 1 ? ' stays' : ' stay')
            . ' in the red at any CTR; capped where nothing breaks';
    }

    /**
     * @return Line
     */
    private static function parts(EngineerInputs $in): array
    {
        $forced = array_column($in->wearAdvice['swap'] ?? [], 'part');
        $optional = self::offeredOptionals($in);

        if ($forced === [] && $optional === []) {
            return self::line('parts', 'Nothing to swap', 'No forced swap this race', '#cockpit-wear');
        }

        $forcedCost = $in->forcedSwapCost ?? 0;
        $total = $forcedCost + array_sum(array_column($optional, 'cost'));
        $figure = $forced !== [] && $in->forcedSwapCost === null && $optional === [] ? null : Money::short($total);
        $optionalNames = implode(', ', array_column($optional, 'part'));

        if ($forced === []) {
            return self::line(
                'parts',
                'Swap ' . $optionalNames . ' (optional)',
                'In the red zone, and ' . self::affordClause($in, 'it'),
                '#cockpit-wear',
                $figure,
            );
        }

        $tone = self::spendable($in) < $forcedCost ? 'red' : 'amber';
        $answer = 'Swap ' . implode(', ', $forced) . ($optional === [] ? '' : ', optionally ' . $optionalNames);
        $reason = $optional === []
            ? 'Would break this race if kept'
            : 'Forced swaps break this race; the optional ones are in the red and '
                . self::affordClause($in, 'them');

        return self::line('parts', $answer, $reason, '#cockpit-wear', $figure, $tone);
    }

    /**
     * @return Line
     */
    private static function training(EngineerInputs $in): array
    {
        $pick = $in->trainingPick;
        if ($pick === null) {
            return self::line('training', self::NOTHING, 'No course is worth the money right now', '#cockpit-training');
        }

        if (!self::affords($in, $pick['cost'])) {
            return self::line('training', self::SKIP, self::skipReason($in), '#cockpit-training');
        }

        return self::line(
            'training',
            'Train ' . $pick['name'],
            self::affordReason($in),
            '#cockpit-training',
            Money::short($pick['cost']),
        );
    }

    /**
     * @return Line
     */
    private static function testing(EngineerInputs $in): array
    {
        $target = $in->testingTarget;
        if (!$in->testingOpen || $target === null) {
            return self::line('testing', self::NOTHING, 'No testing session is open', '#cockpit-testing');
        }

        $anchor = '#cockpit-testing';
        $where = $in->testingTrack === null ? 'Test this week' : 'Test at ' . $in->testingTrack;
        $tunes = 'tunes for ' . ($target['track_name'] ?? 'race ' . $target['race'])
            . ($target['track_name'] === null ? '' : ' (race ' . $target['race'] . ')');
        $cost = $in->testingCost;

        if ($cost === null) {
            return self::objectiveIs($in, SeasonObjective::SURVIVE)
                ? self::line('testing', self::SKIP, 'The session cost is unknown, and repairs come first', $anchor)
                : self::line(
                    'testing',
                    $where . ' — cost unknown',
                    'No earlier session this season to price it; ' . $tunes,
                    $anchor,
                );
        }

        if (!self::affords($in, $cost)) {
            return self::line('testing', self::SKIP, self::skipReason($in), '#cockpit-testing');
        }

        return self::line('testing', $where, self::affordReason($in) . '; ' . $tunes, $anchor, Money::short($cost));
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

        $survive = self::objectiveIs($in, SeasonObjective::SURVIVE);
        $overtake = $survive ? max(0, $in->risk['overtake'] - self::SURVIVE_OVERTAKE_STEP) : $in->risk['overtake'];

        return self::line(
            'risk',
            'Overtake ' . $overtake . ' · Defend ' . $in->risk['defend'],
            $survive
                ? 'Overtake lowered by ' . self::SURVIVE_OVERTAKE_STEP . ' to cut the cost of mistakes'
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
        if (self::objectiveIs($in, SeasonObjective::SURVIVE)) {
            return self::line('boost', 'No boost — save the fuel', 'Survive: skip the extra fuel');
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
     * @return list<array{part: string, cost: int}>
     */
    private static function offeredOptionals(EngineerInputs $in): array
    {
        if (!self::objectiveIs($in, SeasonObjective::PROMOTE)) {
            return [];
        }

        $remaining = self::spendable($in) - ($in->forcedSwapCost ?? 0);
        $offered = [];
        foreach ($in->optionalSwaps as $swap) {
            if ($swap['cost'] <= $remaining) {
                $offered[] = $swap;
                $remaining -= $swap['cost'];
            }
        }

        return $offered;
    }

    private static function affords(EngineerInputs $in, int $cost): bool
    {
        $reserve = self::objectiveIs($in, SeasonObjective::SURVIVE) ? $in->forcedSwapCost ?? 0 : 0;

        return self::spendable($in) >= $cost + $reserve;
    }

    private static function skipReason(EngineerInputs $in): string
    {
        $known = $in->outlook['known'];
        if (self::objectiveIs($in, SeasonObjective::SURVIVE)) {
            return $known
                ? 'Survive: repairs come before anything optional'
                : 'Survive: your balance must cover repairs first; season costs unknown';
        }

        return $known ? 'Free cash does not cover it' : 'Your balance does not cover it; season costs unknown';
    }

    private static function affordReason(EngineerInputs $in): string
    {
        return ucfirst(self::affordClause($in, 'it'));
    }

    private static function affordClause(EngineerInputs $in, string $what): string
    {
        $subject = $in->outlook['known'] ? 'free cash' : 'your balance';
        $tail = self::objectiveIs($in, SeasonObjective::SURVIVE) ? ' and the forced repairs' : '';
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
        ];
    }
}
