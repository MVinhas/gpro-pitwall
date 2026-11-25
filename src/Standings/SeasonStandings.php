<?php

declare(strict_types=1);

namespace App\Standings;

use App\Engineer\SeasonObjective;

/**
 * The end-of-season arithmetic behind the Standings screen, read from one raw
 * GPRO Standings payload (the group table plus every manager's per-race
 * results). Pure and static: no I/O, so every rule is unit-testable.
 *
 * Points are GPRO's 10-8-6-5-4-3-2-1, so a race is worth at most
 * SeasonObjective::POINTS_PER_RACE. Zones come from the row colour GPRO paints
 * (green promoted, red relegated), the same source SeasonObjective reads.
 *
 * "Safe" figures are deliberately conservative: a manager is safe above a
 * line only if they stay ahead even when every rival wins every race left. A
 * tie is never counted as safe, since GPRO's countback could go either way.
 *
 * @phpstan-type RaceCell array{pos: ?int, label: string, pts: int, run: bool}
 * @phpstan-type Row array{
 *     pos: int,
 *     name: string,
 *     idm: ?int,
 *     pts: int,
 *     max: int,
 *     zone: string,
 *     arrow: string,
 *     own: bool,
 *     results: list<RaceCell>,
 *     started: int,
 *     wins: int,
 *     podiums: int,
 *     avg_pos: ?float,
 *     last3: int,
 *     pace: float,
 *     projected_pts: int,
 *     projected_pos: int,
 *     cumulative: list<int>
 * }
 * @phpstan-type Line array{
 *     key: string,
 *     label: string,
 *     line_pos: int,
 *     status: string,
 *     needed: ?int,
 *     per_race: ?float,
 *     margin: ?int,
 *     holder: ?string,
 *     projected_in: bool,
 *     pace_needed: ?float,
 *     scenarios: list<Scenario>,
 *     ways: list<Way>,
 *     pace_target: ?int,
 *     pace_points: ?int,
 *     pace_rival: ?string
 * }
 * @phpstan-type Way array{
 *     best: int,
 *     best_count: int,
 *     other: ?int,
 *     other_count: int,
 *     total: int
 * }
 * @phpstan-type Scenario array{
 *     finish: int,
 *     total: int,
 *     need: int,
 *     threats: list<array{name: string, at_most: int, finish: ?int}>
 * }
 * @phpstan-type Rival array{
 *     row: Row,
 *     gap: int,
 *     h2h_ahead: int,
 *     h2h_behind: int
 * }
 */
final class SeasonStandings
{
    public const int MAX_RIVALS = 8;
    public const int FORM_RACES = 3;
    /** The most a rival can score in a race the manager wins: second place. */
    public const int SECOND_PLACE_POINTS = 8;
    /** GPRO race points by finishing position; nothing outside the top 8. */
    public const array RACE_POINTS = [1 => 10, 2 => 8, 3 => 6, 4 => 5, 5 => 4, 6 => 3, 7 => 2, 8 => 1];
    public const int MAX_SCENARIOS = 3;
    public const int MAX_WAYS = 3;
    /** A finish outside the points, for "ways" like "4 wins and 2 non-scoring races". */
    public const int NO_POINTS = 9;

    public const string ZONE_PROMOTE = 'promote';
    public const string ZONE_RELEGATE = 'relegate';

    public const string CLINCHED = 'clinched';
    public const string REACHABLE = 'reachable';
    public const string NEEDS_HELP = 'needs_help';
    public const string OUT = 'out';

    /**
     * @param array<string, mixed> $payload a raw GPRO Standings payload
     * @return array{
     *     available: bool,
     *     group: string,
     *     races: int,
     *     races_run: int,
     *     races_left: int,
     *     points_on_offer: int,
     *     points_per_race: int,
     *     rows: list<Row>,
     *     own: ?Row,
     *     lines: list<Line>,
     *     positions: list<Line>,
     *     rivals: list<Rival>
     * }
     */
    public static function build(array $payload, ?int $ownIdm): array
    {
        $raw = self::rawManagers($payload);
        $racesRun = self::racesRun($raw);
        $racesLeft = max(0, SeasonObjective::RACES - $racesRun);
        $onOffer = $racesLeft * SeasonObjective::POINTS_PER_RACE;

        $rows = [];
        foreach ($raw as $manager) {
            $rows[] = self::row($manager, $ownIdm, $racesRun, $onOffer);
        }
        usort($rows, static fn (array $a, array $b): int => $a['pos'] <=> $b['pos']);
        $rows = self::withProjectedPos($rows);

        $own = null;
        foreach ($rows as $row) {
            if ($row['own']) {
                $own = $row;
                break;
            }
        }

        $group = $payload['group'] ?? '';

        return [
            'available' => $rows !== [],
            'group' => is_string($group) ? html_entity_decode($group, ENT_QUOTES | ENT_HTML5, 'UTF-8') : '',
            'races' => SeasonObjective::RACES,
            'races_run' => $racesRun,
            'races_left' => $racesLeft,
            'points_on_offer' => $onOffer,
            'points_per_race' => SeasonObjective::POINTS_PER_RACE,
            'rows' => $rows,
            'own' => $own,
            'lines' => $own === null ? [] : self::lines($rows, $own, $racesLeft),
            'positions' => $own === null ? [] : self::positions($rows, $own, $racesLeft),
            'rivals' => $own === null ? [] : self::rivals($rows, $own),
        ];
    }

    /**
     * Points still to score to be sure of finishing in the top $line, however
     * the others do: one more than the $line-th best maximum among them.
     * 0 means already safe.
     *
     * @param list<int> $otherMaxes
     */
    public static function pointsToSecure(int $ownPts, array $otherMaxes, int $line): int
    {
        rsort($otherMaxes);
        if (count($otherMaxes) < $line) {
            return 0;
        }

        return max(0, $otherMaxes[$line - 1] - $ownPts + 1);
    }

    /**
     * Out of the top $line for good: at least $line others already hold more
     * points than this manager can still reach.
     *
     * @param list<int> $otherPts
     */
    public static function isOut(int $ownMax, array $otherPts, int $line): bool
    {
        $above = array_filter($otherPts, static fn (int $pts): bool => $pts > $ownMax);

        return count($above) >= $line;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private static function rawManagers(array $payload): array
    {
        $managers = $payload['managers'] ?? null;
        if (!is_array($managers)) {
            return [];
        }

        $out = [];
        foreach ($managers as $manager) {
            if (is_array($manager) && is_numeric($manager['pos'] ?? null)) {
                /** @var array<string, mixed> $manager */
                $out[] = $manager;
            }
        }

        return $out;
    }

    /**
     * Races run so far: the last race slot any manager has a result in.
     *
     * @param list<array<string, mixed>> $managers
     */
    private static function racesRun(array $managers): int
    {
        $run = 0;
        foreach ($managers as $manager) {
            foreach (self::resultList($manager) as $i => $result) {
                if (self::cellLabel($result) !== '') {
                    $run = max($run, $i + 1);
                }
            }
        }

        return min($run, SeasonObjective::RACES);
    }

    /**
     * @param array<string, mixed> $manager
     * @return list<mixed>
     */
    private static function resultList(array $manager): array
    {
        $results = $manager['results'] ?? null;

        return is_array($results) ? array_values($results) : [];
    }

    /** The cell's raw position text; '' for an empty slot ("-"). */
    private static function cellLabel(mixed $result): string
    {
        if (!is_array($result)) {
            return '';
        }
        $pos = $result['pos'] ?? null;
        $label = is_scalar($pos) ? trim((string) $pos) : '';

        return $label === '-' ? '' : $label;
    }

    /**
     * @param array<string, mixed> $manager
     * @return Row
     */
    private static function row(array $manager, ?int $ownIdm, int $racesRun, int $onOffer): array
    {
        $results = self::resultList($manager);
        $cells = [];
        $cumulative = [];
        $running = 0;
        $started = $wins = $podiums = $posSum = $posCount = 0;
        for ($i = 0; $i < SeasonObjective::RACES; $i++) {
            $result = $results[$i] ?? null;
            $label = self::cellLabel($result);
            $pos = ctype_digit($label) ? (int) $label : null;
            $pts = is_array($result) && is_numeric($result['pts'] ?? null) ? (int) $result['pts'] : 0;
            $cells[] = ['pos' => $pos, 'label' => $label, 'pts' => $pts, 'run' => $i < $racesRun];

            if ($label !== '') {
                $started++;
            }
            if ($pos !== null) {
                $posSum += $pos;
                $posCount++;
                $wins += $pos === 1 ? 1 : 0;
                $podiums += $pos <= 3 ? 1 : 0;
            }
            if ($i < $racesRun) {
                $running += $pts;
                $cumulative[] = $running;
            }
        }

        $last3 = 0;
        for ($i = max(0, $racesRun - self::FORM_RACES); $i < $racesRun; $i++) {
            $last3 += $cells[$i]['pts'];
        }

        $pts = is_numeric($manager['pts'] ?? null) ? (int) $manager['pts'] : 0;
        $pace = $racesRun > 0 ? $pts / $racesRun : 0.0;
        $idm = is_numeric($manager['IDM'] ?? null) ? (int) $manager['IDM'] : null;
        $name = is_string($manager['name'] ?? null) ? $manager['name'] : '';
        $arrow = is_string($manager['manArrow'] ?? null) ? $manager['manArrow'] : '';

        return [
            'pos' => (int) $manager['pos'],
            'name' => html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'idm' => $idm,
            'pts' => $pts,
            'max' => $pts + $onOffer,
            'zone' => match ($manager['manBgColor'] ?? '') {
                SeasonObjective::GREEN => self::ZONE_PROMOTE,
                SeasonObjective::RED => self::ZONE_RELEGATE,
                default => '',
            },
            'arrow' => $arrow,
            'own' => $ownIdm !== null && $idm === $ownIdm,
            'results' => $cells,
            'started' => $started,
            'wins' => $wins,
            'podiums' => $podiums,
            'avg_pos' => $posCount > 0 ? round($posSum / $posCount, 1) : null,
            'last3' => $last3,
            'pace' => round($pace, 2),
            'projected_pts' => (int) round($pts + $pace * ($onOffer / SeasonObjective::POINTS_PER_RACE)),
            'projected_pos' => 0,
            'cumulative' => $cumulative,
        ];
    }

    /**
     * Final position on current pace: projected points, ties kept in today's order.
     *
     * @param list<Row> $rows sorted by pos
     * @return list<Row>
     */
    private static function withProjectedPos(array $rows): array
    {
        $order = array_keys($rows);
        usort($order, static fn (int $a, int $b): int => [$rows[$b]['projected_pts'], $rows[$a]['pos']]
            <=> [$rows[$a]['projected_pts'], $rows[$b]['pos']]);
        $rankOf = array_flip($order);

        return array_map(
            static fn (array $row, int $index): array => ['projected_pos' => $rankOf[$index] + 1] + $row,
            $rows,
            array_keys($rows),
        );
    }

    /**
     * Every finish still open: one "P{n} or better" line from the best place the
     * manager can still reach down to the first one already secured (the worst
     * they can do from here, even scoring nothing more).
     *
     * @param list<Row> $rows
     * @param Row $own
     * @return list<Line>
     */
    private static function positions(array $rows, array $own, int $racesLeft): array
    {
        $positions = [];
        for ($pos = 1, $count = count($rows); $pos <= $count; $pos++) {
            $label = $pos === 1 ? 'P1' : 'P' . $pos . ' or better';
            $line = self::line('pos' . $pos, $label, $pos, $rows, $own, $racesLeft);
            if ($line['status'] === self::OUT) {
                continue;
            }
            $positions[] = $line;
            if ($line['status'] === self::CLINCHED) {
                break;
            }
        }

        return $positions;
    }

    /**
     * @param list<Row> $rows
     * @param Row $own
     * @return list<Line>
     */
    private static function lines(array $rows, array $own, int $racesLeft): array
    {
        $count = count($rows);
        $inZone = static fn (string $zone): array => array_column(
            array_filter($rows, static fn (array $r): bool => $r['zone'] === $zone),
            'pos',
        );
        $green = $inZone(self::ZONE_PROMOTE);
        $red = $inZone(self::ZONE_RELEGATE);

        $lines = [self::line('win', 'Win the group', 1, $rows, $own, $racesLeft)];
        if ($green !== [] && max($green) > 1) {
            $lines[] = self::line('promote', 'Promotion', max($green), $rows, $own, $racesLeft);
        }
        if ($red !== [] && min($red) > 1 && min($red) - 1 < $count) {
            $lines[] = self::line('stay', 'Avoid relegation', min($red) - 1, $rows, $own, $racesLeft);
        }

        return $lines;
    }

    /**
     * @param list<Row> $rows
     * @param Row $own
     * @return Line
     */
    private static function line(
        string $key,
        string $label,
        int $linePos,
        array $rows,
        array $own,
        int $racesLeft,
    ): array {
        $others = array_values(array_filter($rows, static fn (array $r): bool => !$r['own']));
        $onOffer = $racesLeft * SeasonObjective::POINTS_PER_RACE;

        $needed = self::pointsToSecure($own['pts'], array_column($others, 'max'), $linePos);
        $out = self::isOut($own['max'], array_column($others, 'pts'), $linePos);
        $status = match (true) {
            $out => self::OUT,
            $needed === 0 => self::CLINCHED,
            $needed <= $onOffer => self::REACHABLE,
            default => self::NEEDS_HELP,
        };

        // Today's margin: points clear of the first manager outside the line when
        // inside it (positive), points short of the line holder when outside (negative).
        $inside = $own['pos'] <= $linePos;
        $reference = self::rowAt($rows, $inside ? $linePos + 1 : $linePos);
        $margin = $reference === null ? null : $own['pts'] - $reference['pts'];
        $holder = self::rowAt($rows, $linePos);

        // The pace that would beat the manager projected to finish on the line:
        // the $linePos-th best of the others on current pace, the one to stay ahead of.
        $byPace = $others;
        usort($byPace, static fn (array $a, array $b): int => [$b['projected_pts'], $a['pos']]
            <=> [$a['projected_pts'], $b['pos']]);
        $paceRival = $byPace[$linePos - 1] ?? null;
        $target = $paceRival === null ? null : $paceRival['projected_pts'];
        $paceNeeded = $target === null || $racesLeft === 0
            ? null
            : round(max(0, $target - $own['pts'] + 1) / $racesLeft, 1);

        return [
            'key' => $key,
            'label' => $label,
            'line_pos' => $linePos,
            'status' => $status,
            'needed' => $status === self::REACHABLE ? $needed : null,
            'per_race' => $status === self::REACHABLE && $racesLeft > 0 ? round($needed / $racesLeft, 1) : null,
            'margin' => $margin,
            'holder' => $holder === null || $holder['own'] ? null : $holder['name'],
            'projected_in' => $own['projected_pos'] <= $linePos,
            'pace_needed' => $paceNeeded,
            'scenarios' => $status === self::NEEDS_HELP ? self::scenarios($others, $own, $linePos, $racesLeft) : [],
            'ways' => $status === self::REACHABLE ? self::ways($needed, $racesLeft) : [],
            'pace_target' => $target,
            'pace_points' => $target === null ? null : max(0, $target - $own['pts'] + 1),
            'pace_rival' => $paceRival === null ? null : $paceRival['name'],
        ];
    }

    /**
     * "If you finish X every race left": the first few finishes that still keep
     * the line open, each with the manager's total and, for every rival who could
     * otherwise reach it, the most they may score (and roughly where that means
     * finishing each race). A rival can win a race only when the manager does
     * not, so their cap is second place in the win-them-all scenario and a win
     * otherwise. Rivals already sure to finish ahead use up the places the line
     * allows; a finish is left out when too few can still be held behind.
     *
     * @param list<Row> $others
     * @param Row $own
     * @return list<Scenario>
     */
    private static function scenarios(array $others, array $own, int $linePos, int $racesLeft): array
    {
        if ($racesLeft === 0) {
            return [];
        }

        $scenarios = [];
        foreach (self::RACE_POINTS as $finish => $points) {
            $total = $own['pts'] + $points * $racesLeft;
            $cap = ($finish === 1 ? self::SECOND_PLACE_POINTS : self::RACE_POINTS[1]) * $racesLeft;
            $threats = [];
            $ahead = 0;
            foreach ($others as $row) {
                if ($row['pts'] + $cap < $total) {
                    continue;
                }
                $atMost = $total - 1 - $row['pts'];
                if ($atMost < 0) {
                    $ahead++;
                    continue;
                }
                $threats[] = [
                    'name' => $row['name'],
                    'at_most' => $atMost,
                    'finish' => self::finishFor($atMost, $racesLeft),
                ];
            }
            $need = count($threats) + $ahead - ($linePos - 1);
            if ($ahead >= $linePos || $need > count($threats)) {
                continue;
            }
            usort($threats, static fn (array $a, array $b): int => $a['at_most'] <=> $b['at_most']);
            $scenarios[] = ['finish' => $finish, 'total' => $total, 'need' => max(0, $need), 'threats' => $threats];
            if (count($scenarios) === self::MAX_SCENARIOS) {
                break;
            }
        }

        return $scenarios;
    }

    /**
     * Ways to score at least $needed over $races, the way TV puts it: one finish
     * repeated, or two finishes mixed ("one 2nd and five 3rds"). For each pair
     * of finishes only the mix with the fewest better results is kept; the
     * closest to $needed come first, then the ones needing fewer better results.
     *
     * @return list<Way>
     */
    public static function ways(int $needed, int $races): array
    {
        if ($races === 0 || $needed <= 0) {
            return [];
        }

        $points = static fn (int $finish): int => self::RACE_POINTS[$finish] ?? 0;
        $ways = [];
        foreach (array_keys(self::RACE_POINTS) as $best) {
            if ($points($best) * $races >= $needed) {
                $ways[] = ['best' => $best, 'best_count' => $races, 'other' => null, 'other_count' => 0,
                    'total' => $points($best) * $races];
            }
            for ($other = $best + 1; $other <= self::NO_POINTS; $other++) {
                for ($count = 1; $count < $races; $count++) {
                    $total = $count * $points($best) + ($races - $count) * $points($other);
                    if ($total >= $needed) {
                        $ways[] = ['best' => $best, 'best_count' => $count, 'other' => $other,
                            'other_count' => $races - $count, 'total' => $total];
                        break;
                    }
                }
            }
        }

        usort($ways, static fn (array $a, array $b): int => [$a['total'], $a['best_count'], -$a['best']]
            <=> [$b['total'], $b['best_count'], -$b['best']]);

        return array_slice($ways, 0, self::MAX_WAYS);
    }

    /**
     * The best finish a rival can repeat every race and still score no more
     * than $points over $races; null when even eighth would be too much.
     */
    private static function finishFor(int $points, int $races): ?int
    {
        foreach (self::RACE_POINTS as $finish => $value) {
            if ($value * $races <= $points) {
                return $finish;
            }
        }

        return null;
    }

    /**
     * Managers still in play against this one: each can still finish ahead of the other.
     *
     * @param list<Row> $rows
     * @param Row $own
     * @return list<Rival>
     */
    private static function rivals(array $rows, array $own): array
    {
        $rivals = [];
        foreach ($rows as $row) {
            if ($row['own'] || $row['max'] < $own['pts'] || $own['max'] < $row['pts']) {
                continue;
            }
            $ahead = $behind = 0;
            foreach ($row['results'] as $i => $cell) {
                $mine = $own['results'][$i]['pos'];
                if ($cell['pos'] === null || $mine === null) {
                    continue;
                }
                $ahead += $mine < $cell['pos'] ? 1 : 0;
                $behind += $mine > $cell['pos'] ? 1 : 0;
            }
            $rivals[] = [
                'row' => $row,
                'gap' => $row['pts'] - $own['pts'],
                'h2h_ahead' => $ahead,
                'h2h_behind' => $behind,
            ];
        }

        usort($rivals, static fn (array $a, array $b): int => [abs($a['gap']), $a['row']['pos']]
            <=> [abs($b['gap']), $b['row']['pos']]);
        $rivals = array_slice($rivals, 0, self::MAX_RIVALS);
        usort($rivals, static fn (array $a, array $b): int => $a['row']['pos'] <=> $b['row']['pos']);

        return $rivals;
    }

    /**
     * @param list<Row> $rows
     * @return Row|null
     */
    private static function rowAt(array $rows, int $pos): ?array
    {
        foreach ($rows as $row) {
            if ($row['pos'] === $pos) {
                return $row;
            }
        }

        return null;
    }
}
