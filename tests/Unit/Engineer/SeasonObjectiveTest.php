<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\SeasonObjective;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SeasonObjective::class)]
final class SeasonObjectiveTest extends TestCase
{
    private const array UNKNOWN_OUTLOOK = ['known' => false, 'committed' => null, 'income' => null, 'free_cash' => null, 'end_committed' => null];

    /**
     * @return array<string, mixed>
     */
    private function manager(int|string $pos, int|string $pts, ?string $colour = ''): array
    {
        $manager = ['name' => 'M' . $pos, 'pos' => (string) $pos, 'pts' => (string) $pts, 'IDM' => 100 + (int) $pos];
        if ($colour !== null) {
            $manager['manBgColor'] = $colour;
        }

        return $manager;
    }

    /**
     * @param list<array<string, mixed>> $managers
     * @return array<string, mixed>
     */
    private function row(int $race, ?int $ownPos, ?int $ownPts, array $managers): array
    {
        return [
            'season' => 80,
            'race' => $race,
            'own_pos' => $ownPos,
            'own_pts' => $ownPts,
            'group_label' => 'Pro - 12',
            'managers_json' => json_encode($managers),
        ];
    }

    /**
     * Green P1-P3, red P9-P10; the pts of P4..P8 and the own manager's are
     * overridden per test through $pts (position => points).
     *
     * @param array<int, int> $pts
     * @return list<array<string, mixed>>
     */
    private function group(array $pts, int $greenUpTo = 3, int $redFrom = 9): array
    {
        $managers = [];
        foreach ($pts as $pos => $points) {
            $colour = $pos <= $greenUpTo ? SeasonObjective::GREEN : ($pos >= $redFrom ? SeasonObjective::RED : '');
            $managers[] = $this->manager($pos, $points, $colour);
        }

        return $managers;
    }

    /**
     * @return array<int, int>
     */
    private function ladder(): array
    {
        return [1 => 50, 2 => 40, 3 => 37, 4 => 33, 5 => 31, 6 => 25, 7 => 20, 8 => 15, 9 => 10, 10 => 5];
    }

    public function testInsidePromotionPlacesProposesPromote(): void
    {
        $proposal = SeasonObjective::propose($this->row(9, 2, 40, $this->group($this->ladder())), 9, self::UNKNOWN_OUTLOOK, 1_000_000, null, false);

        self::assertSame(SeasonObjective::PROMOTE, $proposal['objective']);
        self::assertSame('Promote', $proposal['label']);
        self::assertFalse($proposal['early']);
        self::assertFalse($proposal['cash_alarm']);
        self::assertFalse($proposal['over']);
        self::assertSame(3, $proposal['numbers']['line_pos']);
        self::assertSame(0, $proposal['numbers']['gap']);
    }

    public function testGapWithinTwoPointsPerRaceProposesPromote(): void
    {
        $proposal = SeasonObjective::propose($this->row(9, 5, 31, $this->group($this->ladder())), 9, self::UNKNOWN_OUTLOOK, 1_000_000, null, false);

        self::assertSame(SeasonObjective::PROMOTE, $proposal['objective']);
        self::assertSame(5, $proposal['numbers']['pos']);
        self::assertSame(31, $proposal['numbers']['pts']);
        self::assertSame(3, $proposal['numbers']['line_pos']);
        self::assertSame(6, $proposal['numbers']['gap']);
        self::assertSame(9, $proposal['numbers']['races_left']);
        self::assertSame(90, $proposal['numbers']['points_on_offer']);
        self::assertContains('6 pts behind P3 with 9 races left (90 on offer)', $proposal['reasons']);
    }

    public function testFarBehindProposesHold(): void
    {
        $pts = [1 => 90, 2 => 85, 3 => 80, 4 => 40, 5 => 20, 6 => 18, 7 => 15, 8 => 10, 9 => 5, 10 => 2];
        $proposal = SeasonObjective::propose($this->row(9, 5, 20, $this->group($pts)), 9, self::UNKNOWN_OUTLOOK, 1_000_000, null, false);

        self::assertSame(SeasonObjective::HOLD, $proposal['objective']);
        self::assertSame('Hold', $proposal['label']);
        self::assertSame(60, $proposal['numbers']['gap']);
        self::assertSame(5, $proposal['numbers']['projected_pos']);
    }

    public function testProjectedIntoRelegationProposesSurvive(): void
    {
        $pts = [1 => 90, 2 => 85, 3 => 80, 4 => 40, 5 => 30, 6 => 28, 7 => 20, 8 => 8, 9 => 10, 10 => 5];
        $proposal = SeasonObjective::propose($this->row(9, 8, 8, $this->group($pts)), 9, self::UNKNOWN_OUTLOOK, 1_000_000, null, false);

        self::assertSame(SeasonObjective::SURVIVE, $proposal['objective']);
        self::assertSame('Survive', $proposal['label']);
        self::assertSame(9, $proposal['numbers']['projected_pos']);
        self::assertFalse($proposal['cash_alarm']);
    }

    public function testNegativeCashNowProposesSurviveWithAlarm(): void
    {
        $proposal = SeasonObjective::propose($this->row(9, 2, 40, $this->group($this->ladder())), 9, self::UNKNOWN_OUTLOOK, -1, null, false);

        self::assertSame(SeasonObjective::SURVIVE, $proposal['objective']);
        self::assertTrue($proposal['cash_alarm']);
    }

    public function testNegativeEndCashProposesSurviveWithAlarm(): void
    {
        $outlook = ['known' => true, 'committed' => 1, 'income' => 1, 'free_cash' => -5, 'end_committed' => -5];
        $proposal = SeasonObjective::propose($this->row(9, 2, 40, $this->group($this->ladder())), 9, $outlook, 500_000, null, false);

        self::assertSame(SeasonObjective::SURVIVE, $proposal['objective']);
        self::assertTrue($proposal['cash_alarm']);
        self::assertSame(-5, $proposal['numbers']['end_cash']);
    }

    public function testUnknownOutlookCannotTriggerCashSurvive(): void
    {
        $proposal = SeasonObjective::propose($this->row(9, 2, 40, $this->group($this->ladder())), 9, self::UNKNOWN_OUTLOOK, 500_000, null, false);

        self::assertFalse($proposal['cash_alarm']);
        self::assertNull($proposal['numbers']['end_cash']);
        self::assertSame(SeasonObjective::PROMOTE, $proposal['objective']);
    }

    public function testExtraGreenPlacesMoveTheLine(): void
    {
        $proposal = SeasonObjective::propose($this->row(9, 5, 31, $this->group($this->ladder(), 5)), 9, self::UNKNOWN_OUTLOOK, 1_000_000, null, false);

        self::assertSame(5, $proposal['numbers']['line_pos']);
        self::assertSame(0, $proposal['numbers']['gap']);
        self::assertSame(SeasonObjective::PROMOTE, $proposal['objective']);
    }

    public function testRookieHasNoRelegation(): void
    {
        $pts = [1 => 90, 2 => 85, 3 => 80, 4 => 40, 5 => 30, 6 => 28, 7 => 20, 8 => 10, 9 => 5, 10 => 2];
        $proposal = SeasonObjective::propose($this->row(9, 10, 2, $this->group($pts, 3, 99)), 9, self::UNKNOWN_OUTLOOK, 1_000_000, null, false);

        self::assertSame(SeasonObjective::HOLD, $proposal['objective']);
        self::assertSame(10, $proposal['numbers']['projected_pos']);
    }

    public function testEliteUsesFirstPlaceAndWinLabel(): void
    {
        $contender = SeasonObjective::propose($this->row(9, 2, 46, $this->group([1 => 50, 2 => 46, 3 => 20, 4 => 10], 3, 99)), 9, self::UNKNOWN_OUTLOOK, 1_000_000, null, true);

        self::assertSame(SeasonObjective::PROMOTE, $contender['objective']);
        self::assertSame('Win the group', $contender['label']);
        self::assertSame(1, $contender['numbers']['line_pos']);
        self::assertSame(4, $contender['numbers']['gap']);

        $outsider = SeasonObjective::propose($this->row(9, 3, 20, $this->group([1 => 90, 2 => 70, 3 => 20, 4 => 10], 3, 99)), 9, self::UNKNOWN_OUTLOOK, 1_000_000, null, true);

        self::assertSame(SeasonObjective::HOLD, $outsider['objective']);
        self::assertSame('Hold', $outsider['label']);
        self::assertSame(70, $outsider['numbers']['gap']);
    }

    public function testEarlySeasonUsesCarRank(): void
    {
        $row = $this->row(2, 4, 8, $this->group([1 => 10, 2 => 9, 3 => 8, 4 => 8, 5 => 6, 6 => 5, 7 => 4, 8 => 3, 9 => 2, 10 => 1]));

        $strong = SeasonObjective::propose($row, 2, self::UNKNOWN_OUTLOOK, 1_000_000, 2, false);
        self::assertSame(SeasonObjective::PROMOTE, $strong['objective']);
        self::assertTrue($strong['early']);

        $weak = SeasonObjective::propose($row, 2, self::UNKNOWN_OUTLOOK, 1_000_000, 7, false);
        self::assertSame(SeasonObjective::HOLD, $weak['objective']);
        self::assertTrue($weak['early']);
    }

    public function testEarlySeasonCashStillProposesSurvive(): void
    {
        $row = $this->row(2, 4, 8, $this->group([1 => 10, 2 => 9, 3 => 8, 4 => 8]));
        $proposal = SeasonObjective::propose($row, 2, self::UNKNOWN_OUTLOOK, -10, 1, false);

        self::assertSame(SeasonObjective::SURVIVE, $proposal['objective']);
        self::assertTrue($proposal['early']);
        self::assertTrue($proposal['cash_alarm']);
    }

    public function testNoRowNoRankIsUnknown(): void
    {
        $proposal = SeasonObjective::propose(null, 5, self::UNKNOWN_OUTLOOK, 1_000_000, null, false);

        self::assertNull($proposal['objective']);
        self::assertSame('Objective unknown', $proposal['label']);
        self::assertTrue($proposal['early']);
        self::assertNull($proposal['numbers']['pos']);
        self::assertNull($proposal['numbers']['races_left']);
    }

    public function testNoRowUsesCarRankWhenKnown(): void
    {
        $proposal = SeasonObjective::propose(null, 5, self::UNKNOWN_OUTLOOK, 1_000_000, 8, false);

        self::assertSame(SeasonObjective::HOLD, $proposal['objective']);
    }

    public function testStringFieldsAndMissingColourAreTolerated(): void
    {
        $managers = [
            ['pos' => '1', 'pts' => '50', 'manBgColor' => SeasonObjective::GREEN],
            ['pos' => '2', 'pts' => '40'],
            ['pos' => '3', 'pts' => '35', 'manBgColor' => ''],
            ['pos' => '4', 'pts' => '10', 'manBgColor' => SeasonObjective::RED],
        ];
        $proposal = SeasonObjective::propose($this->row(9, 2, 40, $managers), 9, self::UNKNOWN_OUTLOOK, 1_000_000, null, false);

        self::assertSame(1, $proposal['numbers']['line_pos']);
        self::assertSame(2, $proposal['numbers']['pos']);
        self::assertSame(40, $proposal['numbers']['pts']);
        self::assertSame(10, $proposal['numbers']['gap']);
        self::assertSame(SeasonObjective::PROMOTE, $proposal['objective']);
    }

    public function testOwnRowMissingFallsBackToColumnsThenUnknown(): void
    {
        $managers = $this->group([1 => 90, 2 => 85, 3 => 80, 4 => 40, 6 => 28, 9 => 10, 10 => 5]);

        $fallback = SeasonObjective::propose($this->row(9, 5, 30, $managers), 9, self::UNKNOWN_OUTLOOK, 1_000_000, null, false);
        self::assertSame(5, $fallback['numbers']['pos']);
        self::assertSame(30, $fallback['numbers']['pts']);
        self::assertSame(SeasonObjective::HOLD, $fallback['objective']);

        $unknown = SeasonObjective::propose($this->row(9, null, null, $managers), 9, self::UNKNOWN_OUTLOOK, 1_000_000, null, false);
        self::assertNull($unknown['objective']);
        self::assertSame('Objective unknown', $unknown['label']);
        self::assertNull($unknown['numbers']['pos']);
    }

    public function testMalformedManagersJsonFallsBackToColumns(): void
    {
        $row = $this->row(9, 5, 31, []);
        $row['managers_json'] = '[';

        $proposal = SeasonObjective::propose($row, 9, self::UNKNOWN_OUTLOOK, 1_000_000, null, false);

        self::assertSame(SeasonObjective::HOLD, $proposal['objective']);
        self::assertSame(5, $proposal['numbers']['pos']);
        self::assertNull($proposal['numbers']['line_pos']);
        self::assertNull($proposal['numbers']['projected_pos']);

        $early = $this->row(2, 5, 31, []);
        $early['managers_json'] = '[';
        self::assertSame(SeasonObjective::PROMOTE, SeasonObjective::propose($early, 2, self::UNKNOWN_OUTLOOK, 1_000_000, 1, false)['objective']);
    }

    public function testTiesRankTheManagerLast(): void
    {
        $pts = [1 => 50, 2 => 40, 3 => 31, 4 => 31, 5 => 20, 6 => 15, 7 => 12, 8 => 10, 9 => 5, 10 => 2];
        $proposal = SeasonObjective::propose($this->row(9, 3, 31, $this->group($pts, 2)), 9, self::UNKNOWN_OUTLOOK, 1_000_000, null, false);

        self::assertSame(4, $proposal['numbers']['projected_pos']);
    }

    public function testSeasonOverReadsTheOutcome(): void
    {
        $promoted = SeasonObjective::propose($this->row(18, 2, 140, $this->group($this->ladder())), 18, self::UNKNOWN_OUTLOOK, 1_000_000, null, false);
        self::assertTrue($promoted['over']);
        self::assertNull($promoted['objective']);
        self::assertSame('Season over — promoted, P2', $promoted['label']);
        self::assertSame([], $promoted['reasons']);

        $held = SeasonObjective::propose($this->row(18, 5, 90, $this->group($this->ladder())), 18, self::UNKNOWN_OUTLOOK, 1_000_000, null, false);
        self::assertSame('Season over — held, P5', $held['label']);

        $relegated = SeasonObjective::propose($this->row(18, 10, 20, $this->group($this->ladder())), 18, self::UNKNOWN_OUTLOOK, 1_000_000, null, false);
        self::assertSame('Season over — relegated, P10', $relegated['label']);

        $noRow = SeasonObjective::propose(null, 18, self::UNKNOWN_OUTLOOK, 1_000_000, null, false);
        self::assertTrue($noRow['over']);
        self::assertSame('Season over', $noRow['label']);
    }

    public function testPinOverridesAndKeepsTheProposal(): void
    {
        $proposal = SeasonObjective::propose($this->row(9, 2, 40, $this->group($this->ladder())), 9, self::UNKNOWN_OUTLOOK, 1_000_000, null, false);

        $inForce = SeasonObjective::inForce($proposal, SeasonObjective::HOLD);

        self::assertSame(SeasonObjective::HOLD, $inForce['objective']);
        self::assertTrue($inForce['pinned']);
        self::assertSame(SeasonObjective::PROMOTE, $inForce['proposed']);
        self::assertSame('Your call: Hold (engineer proposes Promote)', $inForce['label']);
        self::assertNull($inForce['warning']);
        self::assertSame($proposal['numbers'], $inForce['numbers']);
        self::assertSame($proposal['reasons'], $inForce['reasons']);
    }

    public function testNoPinFollowsTheProposal(): void
    {
        $proposal = SeasonObjective::propose($this->row(9, 2, 40, $this->group($this->ladder())), 9, self::UNKNOWN_OUTLOOK, 1_000_000, null, false);

        $inForce = SeasonObjective::inForce($proposal, null);

        self::assertSame(SeasonObjective::PROMOTE, $inForce['objective']);
        self::assertFalse($inForce['pinned']);
        self::assertSame(SeasonObjective::PROMOTE, $inForce['proposed']);
        self::assertSame('Promote', $inForce['label']);
        self::assertNull($inForce['warning']);
    }

    public function testCashAlarmWarnsOverAPin(): void
    {
        $proposal = SeasonObjective::propose($this->row(9, 2, 40, $this->group($this->ladder())), 9, self::UNKNOWN_OUTLOOK, -1, null, false);

        $pinned = SeasonObjective::inForce($proposal, SeasonObjective::PROMOTE);
        self::assertSame('Negative balance relegates — the engineer proposes Survive', $pinned['warning']);
        self::assertSame(SeasonObjective::PROMOTE, $pinned['objective']);

        self::assertNull(SeasonObjective::inForce($proposal, SeasonObjective::SURVIVE)['warning']);
    }

    public function testUnknownPinValueIsIgnored(): void
    {
        $proposal = SeasonObjective::propose($this->row(9, 2, 40, $this->group($this->ladder())), 9, self::UNKNOWN_OUTLOOK, 1_000_000, null, false);

        $inForce = SeasonObjective::inForce($proposal, 'win-everything');

        self::assertFalse($inForce['pinned']);
        self::assertSame(SeasonObjective::PROMOTE, $inForce['objective']);
    }

    public function testSeasonOverIgnoresThePin(): void
    {
        $proposal = SeasonObjective::propose($this->row(18, 2, 140, $this->group($this->ladder())), 18, self::UNKNOWN_OUTLOOK, 1_000_000, null, false);

        $inForce = SeasonObjective::inForce($proposal, SeasonObjective::HOLD);

        self::assertFalse($inForce['pinned']);
        self::assertNull($inForce['objective']);
        self::assertSame($proposal['label'], $inForce['label']);
    }

    public function testPinOverEliteWinLabelKeepsTheLabel(): void
    {
        $proposal = SeasonObjective::propose($this->row(9, 2, 46, $this->group([1 => 50, 2 => 46, 3 => 20], 3, 99)), 9, self::UNKNOWN_OUTLOOK, 1_000_000, null, true);

        self::assertSame('Your call: Win the group (same as the engineer)', SeasonObjective::inForce($proposal, SeasonObjective::PROMOTE)['label']);
    }

    public function testProposalCarriesTheEliteFlag(): void
    {
        $row = $this->row(9, 2, 46, $this->group([1 => 50, 2 => 46, 3 => 20], 3, 99));

        self::assertTrue(SeasonObjective::propose($row, 9, self::UNKNOWN_OUTLOOK, 1_000_000, null, true)['elite']);
        self::assertFalse(SeasonObjective::propose($row, 9, self::UNKNOWN_OUTLOOK, 1_000_000, null, false)['elite']);
        self::assertTrue(SeasonObjective::propose(null, 3, self::UNKNOWN_OUTLOOK, 1_000_000, 5, true)['elite']);
    }

    public function testEliteManagerPinningPromoteReadsAsWinTheTitle(): void
    {
        $proposal = SeasonObjective::propose($this->row(9, 3, 20, $this->group([1 => 50, 2 => 46, 3 => 20], 3, 99)), 9, self::UNKNOWN_OUTLOOK, 1_000_000, null, true);

        $label = SeasonObjective::inForce($proposal, SeasonObjective::PROMOTE)['label'];

        self::assertStringNotContainsString('Promote', $label);
        self::assertStringStartsWith('Your call: Win the group (', $label);
    }
}
