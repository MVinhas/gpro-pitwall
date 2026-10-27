<?php

declare(strict_types=1);

namespace App\Tests\Unit\Template;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

#[CoversNothing]
final class SeasonBudgetCardTest extends TestCase
{
    /**
     * @param array<string, mixed>|null $budget
     */
    private function html(?array $budget, int $season = 107, int $latest = 107, bool $over = false): string
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'));

        return $twig->render('season/_budget.twig', [
            'budget' => $budget,
            'budget_over' => $over,
            'season' => $season,
            'seasons' => [$latest, $latest - 1],
        ]);
    }

    /** @param array<string, mixed>|null $budget */
    private function text(?array $budget, int $season = 107, int $latest = 107): string
    {
        return (string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($this->html($budget, $season, $latest)), ENT_QUOTES));
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $spend
     * @param list<string> $partial
     * @return array<string, mixed>
     */
    private static function budget(array $row = [], array $spend = [], array $partial = [], string $target = 'End above $4M'): array
    {
        return [
            'season' => 107,
            'race' => 8,
            'computed_at' => '2026-10-27 10:00:00',
            'plan' => ['ctr' => 41, 'partial' => $partial],
            'view' => [
                'as_of' => 'As of race 8',
                'target' => $target,
                'spend' => array_merge(['value' => '$1.2M', 'tone' => 'ink'], $spend),
                'rows' => [array_merge([
                    'race' => 9, 'track' => 'Monza',
                    'replaced' => [['part' => 'Engine', 'cost' => '$180k'], ['part' => 'Gearbox', 'cost' => '$90k']],
                    'replaced_none' => '',
                    'running' => '$300k', 'income' => '$900k',
                    'balance' => '$4.4M', 'balance_note' => '', 'tone' => 'ink',
                ], $row)],
                'partial' => implode(', ', $partial),
            ],
        ];
    }

    public function testRendersRowsAndHeader(): void
    {
        $html = $this->html(self::budget());
        $text = $this->text(self::budget());

        $this->assertStringContainsString('id="season-budget"', $html);
        $this->assertStringContainsString('Budget to season end', $text);
        $this->assertStringContainsString("As of race 8 · forecast at CTR 41, the engineer's advice", $text);
        $this->assertStringContainsString('End above $4M', $text);
        $this->assertStringContainsString('Safe to spend', $text);
        $this->assertStringContainsString('$1.2M', $text);
        $this->assertStringContainsString('Monza', $text);
        $this->assertStringContainsString('Engine · $180k, Gearbox · $90k', $text);
        $this->assertStringContainsString('$4.4M', $text);
        $this->assertStringContainsString(
            "Repairs at today's part prices; income is the latest sponsor money plus this season's average prize money.",
            $text,
        );
        $this->assertStringNotContainsString('Forecast partial', $text);
        foreach (['Race', 'Track', 'Replaced', 'Running', 'Income', 'Balance after'] as $header) {
            $this->assertStringContainsString($header, $text);
        }
    }

    public function testTrackRunningAndIncomeAreHiddenOnMobile(): void
    {
        $html = $this->html(self::budget());

        $this->assertSame(6, substr_count($html, 'hidden sm:table-cell'));
        $this->assertMatchesRegularExpression('/<th class="[^"]*hidden sm:table-cell[^"]*">Track<\/th>/', $html);
        $this->assertMatchesRegularExpression('/<th class="[^"]*hidden sm:table-cell[^"]*">Running<\/th>/', $html);
        $this->assertMatchesRegularExpression('/<th class="[^"]*hidden sm:table-cell[^"]*">Income<\/th>/', $html);
    }

    public function testReplacedCostsAreHiddenOnMobile(): void
    {
        $html = $this->html(self::budget());

        $this->assertMatchesRegularExpression('/Engine<span class="hidden sm:inline"> · \$180k<\/span>/', $html);
        $this->assertMatchesRegularExpression('/Gearbox<span class="hidden sm:inline"> · \$90k<\/span>/', $html);
    }

    public function testTheSectionClearsTheStickyTabBar(): void
    {
        $this->assertMatchesRegularExpression('/<section id="season-budget" class="[^"]*scroll-mt-16/', $this->html(self::budget()));
    }

    public function testAnotherSeasonsPlanNamesItsSeason(): void
    {
        $budget = self::budget();
        $budget['view']['as_of'] = 'Season 108 · as of race 1';

        $this->assertStringContainsString(
            "Season 108 · as of race 1 · forecast at CTR 41, the engineer's advice",
            $this->text($budget),
        );
    }

    public function testBelowTargetIsAmberWithWords(): void
    {
        $html = $this->html(self::budget(['balance_note' => ' · below target', 'tone' => 'amber']));

        $this->assertMatchesRegularExpression('/text-amber-700[^>]*>\s*\$4\.4M · below target/', $html);
    }

    public function testNegativeIsRedWithWords(): void
    {
        $html = $this->html(self::budget(['balance' => '-$50k', 'balance_note' => ' · negative', 'tone' => 'red']));

        $this->assertMatchesRegularExpression('/text-red-700[^>]*>\s*-\$50k · negative/', $html);
    }

    public function testNothingToSpareIsAmberAndOverBudgetIsRed(): void
    {
        $amber = $this->html(self::budget(spend: ['value' => 'Nothing to spare', 'tone' => 'amber']));
        $red = $this->html(self::budget(spend: ['value' => 'Over budget by $250k', 'tone' => 'red']));

        $this->assertMatchesRegularExpression('/text-amber-700[^>]*>\s*Nothing to spare/', $amber);
        $this->assertMatchesRegularExpression('/text-red-700[^>]*>\s*Over budget by \$250k/', $red);
    }

    public function testPartialForecastIsNamed(): void
    {
        $text = $this->text(self::budget(partial: ['income', 'part prices']));

        $this->assertStringContainsString('Forecast partial: income, part prices', $text);
    }

    public function testEmptyStateWhenNoBudget(): void
    {
        $html = $this->html(null);
        $text = $this->text(null);

        $this->assertStringContainsString('No budget yet', $text);
        $this->assertMatchesRegularExpression('/id="season-budget" class="[^"]*scroll-mt-16/', $html);
        $this->assertStringContainsString("Open the Cockpit once the season's costs are known to build the forecast.", $text);
        $this->assertStringNotContainsString('Budget to season end', $text);
        $this->assertStringNotContainsString('<table', $html);
    }

    public function testAPastSeasonShowsNothing(): void
    {
        $this->assertSame('', trim($this->html(null, 106, 107)));
    }

    public function testASpentPlanShowsNothing(): void
    {
        $this->assertSame('', trim($this->html(null, over: true)));
    }

    public function testWearUnknownRowReadsItsNote(): void
    {
        $text = $this->text(self::budget(['replaced' => [], 'replaced_none' => 'wear unknown']));

        $this->assertStringContainsString('Monza wear unknown $300k', $text);
    }
}
