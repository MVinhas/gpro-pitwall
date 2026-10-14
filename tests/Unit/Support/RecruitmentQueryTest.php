<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\RecruitmentQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecruitmentQuery::class)]
final class RecruitmentQueryTest extends TestCase
{
    public function testARequestThatNamesItsFiltersIsAnsweredAndRemembered(): void
    {
        $session = [];
        $query = ['division_tab' => 'Pro', 'min_OA' => '60', 'no_offers' => '1'];

        $this->assertSame($query, RecruitmentQuery::resolve($query, $session));
        $this->assertSame($query, $session[RecruitmentQuery::SESSION_KEY]);
    }

    public function testABareVisitGetsTheRememberedFiltersBack(): void
    {
        $session = [RecruitmentQuery::SESSION_KEY => ['division_tab' => 'Pro', 'max_AGE' => '25']];

        $this->assertSame(
            ['division_tab' => 'Pro', 'max_AGE' => '25'],
            RecruitmentQuery::resolve([], $session),
        );
    }

    public function testTheSkeletonFollowUpAndFragmentFlagsDoNotCountAsFilters(): void
    {
        $session = [RecruitmentQuery::SESSION_KEY => ['division_tab' => 'Elite']];

        $this->assertSame(
            ['division_tab' => 'Elite'],
            RecruitmentQuery::resolve(['wait' => '1', 'fragment' => 'recruitment_results'], $session),
        );
    }

    public function testEmptyAndNonStringValuesAreDropped(): void
    {
        $session = [];
        $resolved = RecruitmentQuery::resolve(
            ['division_tab' => 'Pro', 'min_OA' => '', 'max_OA' => ['1'], 'page' => '2', 'evil' => 'x'],
            $session,
        );

        $this->assertSame(['division_tab' => 'Pro', 'page' => '2'], $resolved);
    }

    public function testAClearedFormStillReplacesTheRememberedFilters(): void
    {
        // The form always sends the division, so clearing every range is a
        // real answer — not a bare visit that restores the old ranges.
        $session = [RecruitmentQuery::SESSION_KEY => ['division_tab' => 'Pro', 'min_OA' => '60']];

        $this->assertSame(
            ['division_tab' => 'Pro'],
            RecruitmentQuery::resolve(['division_tab' => 'Pro', 'min_OA' => ''], $session),
        );
        $this->assertSame(['division_tab' => 'Pro'], $session[RecruitmentQuery::SESSION_KEY]);
    }

    public function testACorruptRememberedValueIsIgnored(): void
    {
        $session = [RecruitmentQuery::SESSION_KEY => 'not-an-array'];

        $this->assertSame([], RecruitmentQuery::resolve([], $session));
    }

    public function testSortFallsBackToRatingDescending(): void
    {
        $this->assertSame('rating', RecruitmentQuery::sortColumn(null));
        $this->assertSame('rating', RecruitmentQuery::sortColumn('RET'));
        $this->assertSame('AGE', RecruitmentQuery::sortColumn('AGE'));
        $this->assertSame('desc', RecruitmentQuery::sortOrder(null));
        $this->assertSame('desc', RecruitmentQuery::sortOrder('sideways'));
        $this->assertSame('asc', RecruitmentQuery::sortOrder('asc'));
    }

    public function testParamsCarryEveryActiveSettingInCanonicalForm(): void
    {
        $this->assertSame(
            [
                'division_tab' => 'Pro',
                'no_offers'    => '1',
                'min_OA'       => '60',
                'max_OA'       => '80',
                'max_AGE'      => '25',
                'sort'         => 'OA',
                'order'        => 'asc',
            ],
            RecruitmentQuery::params('Pro', true, ['OA' => ['min' => 60, 'max' => 80], 'AGE' => ['max' => 25]], 'OA', 'asc'),
        );
        $this->assertSame(
            ['division_tab' => 'Rookie', 'sort' => 'rating', 'order' => 'desc'],
            RecruitmentQuery::params('Rookie', false, [], 'rating', 'desc'),
        );
    }

    public function testEachActiveFilterBecomesAChipThatKnowsHowToDropItself(): void
    {
        $ranges = ['OA' => ['min' => 60, 'max' => 80], 'AGE' => ['max' => 25], 'SAL' => ['min' => 1500000]];
        $params = RecruitmentQuery::params('Pro', true, $ranges, 'rating', 'desc');

        $chips = RecruitmentQuery::chips($ranges, true, $params);

        $this->assertSame(
            ['Overall ability 60–80', 'Age ≤ 25', 'Salary ≥ $1,500,000', 'Hiding drivers with offers'],
            array_column($chips, 'label'),
        );

        $dropOa = $chips[0]['params'];
        $this->assertArrayNotHasKey('min_OA', $dropOa);
        $this->assertArrayNotHasKey('max_OA', $dropOa);
        $this->assertSame('25', $dropOa['max_AGE']);
        $this->assertSame('Pro', $dropOa['division_tab']);

        $this->assertArrayNotHasKey('no_offers', $chips[3]['params']);
        $this->assertSame('60', $chips[3]['params']['min_OA']);
    }

    public function testNoFiltersMeansNoChips(): void
    {
        $this->assertSame([], RecruitmentQuery::chips([], false, ['division_tab' => 'Pro']));
    }

    public function testClearedParamsKeepOnlyTheDivisionAndSort(): void
    {
        $params = RecruitmentQuery::params('Pro', true, ['OA' => ['min' => 60]], 'AGE', 'asc');

        $this->assertSame(
            ['division_tab' => 'Pro', 'sort' => 'AGE', 'order' => 'asc'],
            RecruitmentQuery::cleared($params),
        );
    }
}
