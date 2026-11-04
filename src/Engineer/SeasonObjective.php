<?php

declare(strict_types=1);

namespace App\Engineer;

/**
 * @phpstan-type Numbers array{
 *     pos: ?int,
 *     pts: ?int,
 *     line_pos: ?int,
 *     gap: ?int,
 *     races_left: ?int,
 *     points_on_offer: ?int,
 *     projected_pos: ?int,
 *     end_cash: ?int,
 *     relegation_pos: ?int
 * }
 * @phpstan-type Proposal array{
 *     objective: ?string,
 *     label: string,
 *     early: bool,
 *     cash_alarm: bool,
 *     over: bool,
 *     elite: bool,
 *     numbers: Numbers,
 *     reasons: list<string>
 * }
 * @phpstan-type InForce array{
 *     objective: ?string,
 *     label: string,
 *     pinned: bool,
 *     proposed: ?string,
 *     warning: ?string,
 *     early: bool,
 *     cash_alarm: bool,
 *     over: bool,
 *     elite: bool,
 *     numbers: Numbers,
 *     reasons: list<string>
 * }
 */
final readonly class SeasonObjective
{
    public const int RACES = 17;
    public const int POINTS_PER_RACE = 10;
    public const int PROMOTE_GAP_PER_RACE = 2;
    public const int EARLY_RACES = 3;
    public const string GREEN = '#007700';
    public const string RED = '#990000';
    public const string PROMOTE = 'promote';
    public const string STAY = 'stay';

    private const string ELITE_PROMOTE_LABEL = 'Win the group';
    private const string UNKNOWN_LABEL = 'Objective unknown';

    /**
     * @param array<string, mixed>|null $standingsRow a user_standings row, managers_json undecoded
     * @param array{known: bool, committed: ?int, income: ?int, free_cash: ?int, end_committed: ?int} $outlook
     * @return Proposal
     */
    public static function propose(
        ?array $standingsRow,
        int $raceNb,
        array $outlook,
        int $cash,
        ?int $ownCarLevelRank,
        bool $elite,
        bool $rookie = false,
    ): array {
        $proposal = self::derive($standingsRow, $raceNb, $outlook, $cash, $ownCarLevelRank, $elite);

        // A Rookie cannot be relegated, so there is nothing to defend: the only goal is up.
        if ($rookie && !$proposal['over']) {
            $proposal['objective'] = self::PROMOTE;
            $proposal['label'] = self::promoteLabel($elite);
        }

        return $proposal;
    }

    /**
     * Folds the retired Hold and Survive objectives into Stay, so a pin or a stored row from before the merge
     * still reads.
     */
    public static function normalise(?string $objective): ?string
    {
        return match ($objective) {
            self::PROMOTE => self::PROMOTE,
            self::STAY, 'hold', 'survive' => self::STAY,
            default => null,
        };
    }

    /**
     * @param array<string, mixed>|null $standingsRow
     * @param array{known: bool, committed: ?int, income: ?int, free_cash: ?int, end_committed: ?int} $outlook
     * @return Proposal
     */
    private static function derive(
        ?array $standingsRow,
        int $raceNb,
        array $outlook,
        int $cash,
        ?int $ownCarLevelRank,
        bool $elite,
    ): array {
        $managers = self::managers($standingsRow);
        [$ownPos, $ownPts, $ownColour] = self::own($standingsRow, $managers);

        if ($raceNb > self::RACES) {
            return self::proposal(null, self::overLabel($ownPos, $ownColour), over: true, elite: $elite);
        }

        $endCash = $outlook['known'] ? $outlook['end_committed'] : null;
        $cashAlarm = $cash < 0 || ($endCash !== null && $endCash < 0);

        $racesRun = $standingsRow === null ? null : max(0, self::int($standingsRow['race'] ?? null) - 1);
        $racesLeft = $racesRun === null ? null : max(0, self::RACES - $racesRun);
        $numbers = [
            'pos' => $ownPos,
            'pts' => $ownPts,
            'line_pos' => null,
            'gap' => null,
            'races_left' => $racesLeft,
            'points_on_offer' => $racesLeft === null ? null : $racesLeft * self::POINTS_PER_RACE,
            'projected_pos' => null,
            'end_cash' => $endCash,
            'relegation_pos' => null,
        ];

        $cashReasons = [];
        if ($cash < 0) {
            $cashReasons[] = 'The balance is negative';
        }
        if ($endCash !== null && $endCash < 0) {
            $cashReasons[] = 'Cash is projected to end the season at ' . Money::full($endCash);
        }

        $early = $racesRun === null || $racesRun < self::EARLY_RACES;
        if ($early) {
            return self::earlyProposal($numbers, $cashAlarm, $cashReasons, $ownCarLevelRank, $elite);
        }

        if ($ownPos === null || $ownPts === null) {
            return $cashAlarm
                ? self::proposal(
                    self::STAY,
                    'Stay',
                    cashAlarm: true,
                    numbers: $numbers,
                    reasons: $cashReasons,
                    elite: $elite,
                )
                : self::proposal(null, self::UNKNOWN_LABEL, numbers: $numbers, elite: $elite);
        }

        $linePos = self::linePos($managers, $elite);
        $relegationPos = self::relegationPos($managers);
        $linePts = $linePos === null ? null : self::ptsAt($managers, $linePos);
        $gap = match (true) {
            $linePos === null => null,
            $ownPos <= $linePos => 0,
            $linePts === null => null,
            default => max(0, $linePts - $ownPts),
        };
        $projectedPos = self::projectedPos($managers, $ownPos, $ownPts, $racesRun, $racesLeft);

        $numbers['line_pos'] = $linePos;
        $numbers['relegation_pos'] = $relegationPos;
        $numbers['gap'] = $gap;
        $numbers['projected_pos'] = $projectedPos;

        $reasons = $cashReasons;
        if ($projectedPos !== null) {
            $reasons[] = 'Projected to finish P' . $projectedPos;
        }
        if ($gap !== null && $gap > 0 && $linePos !== null) {
            $reasons[] = $gap . ' pts behind P' . $linePos . ' with ' . $racesLeft
                . ' races left (' . $numbers['points_on_offer'] . ' on offer)';
        }

        $stay = $cashAlarm
            || ($projectedPos !== null && $relegationPos !== null && $projectedPos >= $relegationPos);
        if ($stay) {
            if (!$cashAlarm && $relegationPos !== null) {
                $reasons[] = 'The relegation zone starts at P' . $relegationPos;
            }

            return self::proposal(
                self::STAY,
                'Stay',
                cashAlarm: $cashAlarm,
                numbers: $numbers,
                reasons: $reasons,
                elite: $elite,
            );
        }

        $promote = ($projectedPos !== null && $linePos !== null && $projectedPos <= $linePos)
            || ($gap !== null && $gap <= self::PROMOTE_GAP_PER_RACE * $racesLeft);
        if ($promote) {
            return self::proposal(
                self::PROMOTE,
                self::promoteLabel($elite),
                numbers: $numbers,
                reasons: $reasons,
                elite: $elite,
            );
        }

        return self::proposal(self::STAY, 'Stay', numbers: $numbers, reasons: $reasons, elite: $elite);
    }

    /**
     * @param Proposal $proposal
     * @return InForce
     */
    public static function inForce(array $proposal, ?string $pin): array
    {
        $proposed = $proposal['objective'];
        $pin = self::normalise($pin);

        if ($pin === null || $proposal['over']) {
            return $proposal + ['pinned' => false, 'proposed' => $proposed, 'warning' => null];
        }

        $pinLabel = $pin === self::PROMOTE && $proposal['elite'] ? self::ELITE_PROMOTE_LABEL : ucfirst($pin);
        $note = match (true) {
            $proposed === null => 'engineer has no proposal',
            $proposed === $pin => 'same as the engineer',
            default => 'engineer proposes ' . $proposal['label'],
        };
        $warning = $proposal['cash_alarm'] && $proposed === self::STAY && $pin !== $proposed
            ? 'Negative balance relegates; the engineer proposes Stay'
            : null;

        return [
            'objective' => $pin,
            'label' => 'Your call: ' . $pinLabel . ' (' . $note . ')',
            'pinned' => true,
            'proposed' => $proposed,
            'warning' => $warning,
        ] + $proposal;
    }

    /**
     * @param Numbers $numbers
     * @param list<string> $cashReasons
     * @return Proposal
     */
    private static function earlyProposal(
        array $numbers,
        bool $cashAlarm,
        array $cashReasons,
        ?int $carRank,
        bool $elite,
    ): array {
        if ($cashAlarm) {
            return self::proposal(
                self::STAY,
                'Stay',
                early: true,
                cashAlarm: true,
                numbers: $numbers,
                reasons: $cashReasons,
                elite: $elite,
            );
        }
        if ($carRank === null) {
            return self::proposal(null, self::UNKNOWN_LABEL, early: true, numbers: $numbers, elite: $elite);
        }

        $reasons = ['Too early for the standings; the car ranks P' . $carRank . ' in the group'];

        return $carRank <= 3
            ? self::proposal(
                self::PROMOTE,
                self::promoteLabel($elite),
                early: true,
                numbers: $numbers,
                reasons: $reasons,
                elite: $elite,
            )
            : self::proposal(self::STAY, 'Stay', early: true, numbers: $numbers, reasons: $reasons, elite: $elite);
    }

    /**
     * @param Numbers|null $numbers
     * @param list<string> $reasons
     * @return Proposal
     */
    private static function proposal(
        ?string $objective,
        string $label,
        bool $early = false,
        bool $cashAlarm = false,
        bool $over = false,
        ?array $numbers = null,
        array $reasons = [],
        bool $elite = false,
    ): array {
        return [
            'objective' => $objective,
            'label' => $label,
            'early' => $early,
            'cash_alarm' => $cashAlarm,
            'over' => $over,
            'elite' => $elite,
            'numbers' => $numbers ?? [
                'pos' => null,
                'pts' => null,
                'line_pos' => null,
                'gap' => null,
                'races_left' => null,
                'points_on_offer' => null,
                'projected_pos' => null,
                'end_cash' => null,
                'relegation_pos' => null,
            ],
            'reasons' => $reasons,
        ];
    }

    private static function promoteLabel(bool $elite): string
    {
        return $elite ? self::ELITE_PROMOTE_LABEL : 'Promote';
    }

    private static function overLabel(?int $pos, string $colour): string
    {
        if ($pos === null) {
            return 'Season over';
        }
        $outcome = match ($colour) {
            self::GREEN => 'promoted',
            self::RED => 'relegated',
            default => 'held',
        };

        return 'Season over — ' . $outcome . ', P' . $pos;
    }

    /**
     * @param array<string, mixed>|null $row
     * @return list<array{pos: int, pts: int, colour: string}>
     */
    private static function managers(?array $row): array
    {
        $decoded = is_string($row['managers_json'] ?? null) ? json_decode($row['managers_json'], true) : null;
        if (!is_array($decoded)) {
            return [];
        }

        $managers = [];
        foreach ($decoded as $manager) {
            if (!is_array($manager) || !is_numeric($manager['pos'] ?? null)) {
                continue;
            }
            $colour = $manager['manBgColor'] ?? '';
            $managers[] = [
                'pos' => (int) $manager['pos'],
                'pts' => self::int($manager['pts'] ?? null),
                'colour' => is_string($colour) ? $colour : '',
            ];
        }

        return $managers;
    }

    /**
     * @param array<string, mixed>|null $row
     * @param list<array{pos: int, pts: int, colour: string}> $managers
     * @return array{0: ?int, 1: ?int, 2: string}
     */
    private static function own(?array $row, array $managers): array
    {
        if ($row === null || !is_numeric($row['own_pos'] ?? null)) {
            return [null, null, ''];
        }

        $pos = (int) $row['own_pos'];
        foreach ($managers as $manager) {
            if ($manager['pos'] === $pos) {
                return [$pos, $manager['pts'], $manager['colour']];
            }
        }

        return [$pos, is_numeric($row['own_pts'] ?? null) ? (int) $row['own_pts'] : null, ''];
    }

    /**
     * @param list<array{pos: int, pts: int, colour: string}> $managers
     */
    private static function linePos(array $managers, bool $elite): ?int
    {
        if ($elite) {
            return 1;
        }
        $greenRows = array_filter($managers, static fn (array $m): bool => $m['colour'] === self::GREEN);
        $green = array_column($greenRows, 'pos');

        return $green === [] ? null : max($green);
    }

    /**
     * @param list<array{pos: int, pts: int, colour: string}> $managers
     */
    private static function relegationPos(array $managers): ?int
    {
        $red = array_column(array_filter($managers, static fn (array $m): bool => $m['colour'] === self::RED), 'pos');

        return $red === [] ? null : min($red);
    }

    /**
     * @param list<array{pos: int, pts: int, colour: string}> $managers
     */
    private static function ptsAt(array $managers, int $pos): ?int
    {
        foreach ($managers as $manager) {
            if ($manager['pos'] === $pos) {
                return $manager['pts'];
            }
        }

        return null;
    }

    /**
     * @param list<array{pos: int, pts: int, colour: string}> $managers
     */
    private static function projectedPos(array $managers, int $ownPos, int $ownPts, int $racesRun, int $racesLeft): ?int
    {
        $others = array_filter($managers, static fn (array $m): bool => $m['pos'] !== $ownPos);
        if ($others === [] || $racesRun === 0) {
            return null;
        }

        $project = static fn (int $pts): float => $pts + $pts / $racesRun * $racesLeft;
        $own = $project($ownPts);
        $ahead = count(array_filter($others, static function (array $m) use ($project, $own, $ownPos): bool {
            $projected = $project($m['pts']);

            return $projected > $own || ($projected === $own && $m['pos'] < $ownPos);
        }));

        return 1 + $ahead;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
