<?php

declare(strict_types=1);

namespace App\Tests\Unit\Standings;

use App\Engineer\SeasonObjective;
use App\Standings\SeasonStandings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SeasonStandings::class)]
final class SeasonStandingsTest extends TestCase
{
    private const array POINTS = [1 => 10, 2 => 8, 3 => 6, 4 => 5, 5 => 4, 6 => 3, 7 => 2, 8 => 1];

    /**
     * A manager row as GPRO sends it: finishing positions per race run
     * ('-' for no result), padded with empty slots to a full season.
     *
     * @param list<int|string> $finishes
     * @return array<string, mixed>
     */
    private static function manager(int $pos, string $name, int $idm, array $finishes, string $colour = ''): array
    {
        $results = [];
        $total = 0;
        foreach ($finishes as $finish) {
            $pts = is_int($finish) ? (self::POINTS[$finish] ?? 0) : 0;
            $total += $pts;
            $results[] = ['pos' => (string) $finish, 'pts' => is_int($finish) ? (string) $pts : '-', 'grid' => '-'];
        }
        while (count($results) < SeasonObjective::RACES) {
            $results[] = ['pos' => '-', 'pts' => '-', 'grid' => '-'];
        }

        return [
            'name' => $name, 'pos' => (string) $pos, 'IDM' => $idm, 'pts' => (string) $total,
            'manBgColor' => $colour, 'manArrow' => 'equals', 'results' => $results,
        ];
    }

    /** @param list<array<string, mixed>> $managers */
    private static function payload(array $managers, string $group = 'Rookie - 1'): array
    {
        return ['group' => $group, 'managers' => $managers];
    }

    /** @param array<string, mixed> $view */
    private static function line(array $view, string $key): array
    {
        foreach ($view['lines'] as $line) {
            if ($line['key'] === $key) {
                return $line;
            }
        }
        self::fail("No {$key} line");
    }

    public function testCountsRacesRunAndPointsOnOffer(): void
    {
        $view = SeasonStandings::build(self::payload([
            self::manager(1, 'A', 1, [1, 1, 1]),
            self::manager(2, 'B', 2, ['-', 2, 2]),
        ]), 1);

        self::assertTrue($view['available']);
        self::assertSame(3, $view['races_run']);
        self::assertSame(14, $view['races_left']);
        self::assertSame(140, $view['points_on_offer']);
        self::assertSame(30 + 140, $view['own']['max']);
        self::assertSame(2, $view['rows'][1]['started']);
    }

    public function testPointsToSecureUsesTheLineThBestMaximumAmongOthers(): void
    {
        // Top 2: the second-best other maximum is 50, so 51 beats it outright.
        self::assertSame(11, SeasonStandings::pointsToSecure(40, [60, 50, 30], 2));
        // Already above every possible second place.
        self::assertSame(0, SeasonStandings::pointsToSecure(70, [80, 69, 10], 2));
        // A tie is not safe: matching 50 still needs one more point.
        self::assertSame(1, SeasonStandings::pointsToSecure(50, [60, 50], 2));
        // Fewer rivals than places: nothing to do.
        self::assertSame(0, SeasonStandings::pointsToSecure(0, [99], 2));
    }

    public function testIsOutOnlyWhenEnoughRivalsAreBeyondReach(): void
    {
        self::assertTrue(SeasonStandings::isOut(50, [60, 55, 10], 2));
        self::assertFalse(SeasonStandings::isOut(50, [60, 50, 10], 2), 'A tie can still go your way on countback');
    }

    public function testPromotionReachableWithPointsPerRace(): void
    {
        $finishes = array_fill(0, 11, 1);
        $view = SeasonStandings::build(self::payload([
            self::manager(1, 'Leader', 1, $finishes, SeasonObjective::GREEN),
            self::manager(2, 'Me', 2, array_fill(0, 11, 2), SeasonObjective::GREEN),
            self::manager(3, 'Third', 3, array_fill(0, 11, 3)),
            self::manager(4, 'Fourth', 4, array_fill(0, 11, 4)),
        ]), 2);

        $promote = self::line($view, 'promote');
        self::assertSame(2, $promote['line_pos']);
        // Others' maxima: 170, 126 (66+60), 115. Second best 126; I have 88.
        self::assertSame(SeasonStandings::REACHABLE, $promote['status']);
        self::assertSame(39, $promote['needed']);
        self::assertSame(6.5, $promote['per_race']);
        self::assertSame(22, $promote['margin'], '88 against third place 66');
        self::assertTrue($promote['projected_in']);
    }

    public function testClinchedAndOut(): void
    {
        // 16 races run, one left (10 on offer). Me 128, third 16 (max 26): promotion is secured.
        $view = SeasonStandings::build(self::payload([
            self::manager(1, 'Leader', 1, array_fill(0, 16, 1), SeasonObjective::GREEN),
            self::manager(2, 'Me', 2, array_fill(0, 16, 2), SeasonObjective::GREEN),
            self::manager(3, 'Far', 3, array_fill(0, 16, 8)),
        ]), 2);

        self::assertSame(SeasonStandings::CLINCHED, self::line($view, 'promote')['status']);
        // The leader has 160 and my maximum is 138: the title is gone.
        self::assertSame(SeasonStandings::OUT, self::line($view, 'win')['status']);
    }

    public function testNeedsHelpWhenSafetyNeedsMoreThanIsOnOffer(): void
    {
        // 16 run, 10 on offer. Leader 130 (max 140), me 126: 15 needed to be sure, yet not out.
        $view = SeasonStandings::build(self::payload([
            self::manager(1, 'Leader', 1, array_merge(array_fill(0, 15, 2), [1]), SeasonObjective::GREEN),
            self::manager(2, 'Me', 2, array_merge(array_fill(0, 15, 2), [3])),
        ]), 2);

        $win = self::line($view, 'win');
        self::assertSame(SeasonStandings::NEEDS_HELP, $win['status']);
        self::assertNull($win['needed']);
        self::assertSame(-4, $win['margin']);
        // If I win the last race I reach 136; the leader can still score 8 (138), so must score 5 or less.
        self::assertSame(['need' => 1, 'threats' => [['name' => 'Leader', 'at_most' => 5]]], $win['condition']);
    }

    public function testWinningEveryRaceLeftCanBeEnoughWhenRivalsCannotAlsoWin(): void
    {
        // 14 run, 3 left. Me 120 (max 150), leader 125: 36 to be sure on the blunt bound,
        // but if I win all three the leader scores at most 3 × 8 = 24 and ends on 149.
        $view = SeasonStandings::build(self::payload([
            self::manager(1, 'Leader', 1, array_merge(array_fill(0, 12, 1), [4, 9]), SeasonObjective::GREEN),
            self::manager(2, 'Me', 2, array_merge(array_fill(0, 12, 1), [9, 9])),
        ]), 2);

        $win = self::line($view, 'win');
        self::assertSame(SeasonStandings::NEEDS_HELP, $win['status']);
        self::assertSame(['need' => 0, 'threats' => []], $win['condition']);
    }

    public function testConditionCountsHowManyThreatsMustFallBehind(): void
    {
        // 15 run, 2 left, top 2 promoted. Three on 100, me on 90 (max 110): each could reach 116.
        // One of them may still finish ahead of me, so two must score 9 or less.
        $ahead = array_merge(array_fill(0, 10, 1), array_fill(0, 5, 9));
        $view = SeasonStandings::build(self::payload([
            self::manager(1, 'A', 1, $ahead, SeasonObjective::GREEN),
            self::manager(2, 'B', 2, $ahead, SeasonObjective::GREEN),
            self::manager(3, 'C', 3, $ahead),
            self::manager(4, 'Me', 4, array_merge(array_fill(0, 9, 1), array_fill(0, 6, 9))),
        ]), 4);

        $promote = self::line($view, 'promote');
        self::assertSame(SeasonStandings::NEEDS_HELP, $promote['status']);
        self::assertSame(2, $promote['condition']['need']);
        self::assertSame([9, 9, 9], array_column($promote['condition']['threats'], 'at_most'));
        // To win the group, all three must stay behind.
        self::assertSame(3, self::line($view, 'win')['condition']['need']);
    }

    public function testPositionsRunFromBestReachableToFirstSecured(): void
    {
        // 16 run, 10 on offer. Me 60 (max 70). Leader 100 is beyond reach; B 65 and C 55 are in play;
        // D 20 (max 30) can no longer catch me, so P4 is already mine.
        $view = SeasonStandings::build(self::payload([
            self::manager(1, 'Leader', 1, array_merge(array_fill(0, 10, 1), array_fill(0, 6, 9))),
            self::manager(2, 'B', 2, array_merge(array_fill(0, 6, 1), [4], array_fill(0, 9, 9))),
            self::manager(3, 'Me', 3, array_merge(array_fill(0, 6, 1), array_fill(0, 10, 9))),
            self::manager(4, 'C', 4, array_merge(array_fill(0, 5, 1), [4], array_fill(0, 10, 9))),
            self::manager(5, 'D', 5, array_merge(array_fill(0, 2, 1), array_fill(0, 14, 9))),
        ]), 3);

        self::assertSame([2, 3, 4], array_column($view['positions'], 'line_pos'));
        self::assertSame('P2 or better', $view['positions'][0]['label']);
        self::assertSame(SeasonStandings::CLINCHED, $view['positions'][2]['status']);
        self::assertSame(SeasonStandings::REACHABLE, $view['positions'][1]['status'], 'C (max 65): 6 to be sure');
        self::assertSame(6, $view['positions'][1]['needed']);
    }

    public function testRookieGroupHasNoRelegationLineButOthersDo(): void
    {
        $rows = [
            self::manager(1, 'A', 1, [1], SeasonObjective::GREEN),
            self::manager(2, 'B', 2, [2]),
            self::manager(3, 'C', 3, [3], SeasonObjective::RED),
        ];
        $rookie = SeasonStandings::build(self::payload(array_slice($rows, 0, 2)), 2);
        self::assertSame(['win'], array_column($rookie['lines'], 'key'), 'P1 green only: promotion is the win line');

        $pro = SeasonStandings::build(self::payload($rows, 'Pro - 3'), 2);
        $stay = self::line($pro, 'stay');
        self::assertSame(2, $stay['line_pos']);
    }

    public function testRivalsAreThoseStillInPlayWithHeadToHead(): void
    {
        // 16 races run, 10 on offer: only managers within 10 points are in play.
        $view = SeasonStandings::build(self::payload([
            self::manager(1, 'Gone', 1, array_fill(0, 16, 1)),
            self::manager(2, 'Close', 2, array_merge(array_fill(0, 15, 3), [2])),
            self::manager(3, 'Me', 3, array_merge(array_fill(0, 15, 3), [4])),
            self::manager(4, 'Behind', 4, array_fill(0, 16, 9)),
        ]), 3);

        $names = array_map(static fn (array $r): string => $r['row']['name'], $view['rivals']);
        self::assertSame(['Close'], $names);
        self::assertSame(3, $view['rivals'][0]['gap']);
        self::assertSame(0, $view['rivals'][0]['h2h_ahead']);
        self::assertSame(1, $view['rivals'][0]['h2h_behind'], 'Same finish 15 times counts for neither');
    }

    public function testDecodesNamesAndBuildsCumulativePoints(): void
    {
        $view = SeasonStandings::build(self::payload([
            self::manager(1, 'Kimmo R&#228;s&#228;nen', 1, [1, '-', 2]),
        ]), 1);

        self::assertSame('Kimmo Räsänen', $view['own']['name']);
        self::assertSame([10, 10, 18], $view['own']['cumulative']);
        self::assertSame(1, $view['own']['wins']);
        self::assertSame(1.5, $view['own']['avg_pos']);
    }

    public function testUnknownOwnManagerStillShowsTheTable(): void
    {
        $view = SeasonStandings::build(self::payload([self::manager(1, 'A', 1, [1])]), null);

        self::assertTrue($view['available']);
        self::assertNull($view['own']);
        self::assertSame([], $view['lines']);
        self::assertSame([], $view['rivals']);
    }

    public function testEmptyPayloadIsUnavailable(): void
    {
        self::assertFalse(SeasonStandings::build([], 1)['available']);
    }
}
