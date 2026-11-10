<?php

declare(strict_types=1);

namespace App\Tests\Unit\Template;

use App\Controller\StrategyController;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

#[CoversNothing]
final class EngineerDriverTemplateTest extends TestCase
{
    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private static function candidate(string $name, array $over = []): array
    {
        return $over + [
            'name' => $name, 'rating' => '71.2', 'gain' => '+6.1', 'salary' => '$2.1M', 'to_sign' => '$3.4M',
            'age' => 27, 'overall' => 180, 'favourites' => 2, 'fits' => 'Fits your car and the next two tracks',
        ];
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private static function panel(array $over = []): array
    {
        return $over + [
            'verdict' => 'replace',
            'reason' => 'A better driver is on the market and your contract is nearly up.',
            'answer' => 'Replace Ada Lovelace',
            'figure' => 'save $1.2M',
            'current' => [
                'name' => 'Ada Lovelace', 'rating' => '64.0', 'overall' => '171', 'age' => 31,
                'salary' => '$2.4M', 'races_left' => 3,
            ],
            'group_average' => '$2.2M',
            'extension' => ['fee' => '$4.0M', 'salary' => '$2.5M', 'exact' => true],
            'shortlist' => [
                self::candidate('Grace Hopper'),
                self::candidate('Alan Turing', ['gain' => null, 'rating' => '68.0']),
                self::candidate('Edsger Dijkstra', ['rating' => '66.5', 'gain' => '+2.5']),
            ],
            'out' => [['label' => 'Retiring', 'count' => 2], ['label' => 'Has offers', 'count' => 5]],
            'notes' => ['Offers are not refunded.'],
        ];
    }

    private function twig(): Environment
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'));
        $twig->addGlobal('no_pilot_message', StrategyController::NO_PILOT_MESSAGE);

        return $twig;
    }

    /**
     * @param array<string, mixed> $panel
     */
    private function render(array $panel): string
    {
        return $this->twig()->render('partials/_engineer_driver.twig', [
            'panel' => $panel,
            'section_paths' => ['Recruitment Analyzer' => '/recruitment'],
        ]);
    }

    private static function text(string $html): string
    {
        return trim(preg_replace('#\s+#', ' ', html_entity_decode(strip_tags(str_replace('><', '> <', $html)))) ?? '');
    }

    public function testTheSummaryCarriesTheAnswerAndTheFigure(): void
    {
        $html = $this->render(self::panel());

        $this->assertStringContainsString('Driver', $html);
        $this->assertStringContainsString('id="engineer-driver"', $html);
        $this->assertStringContainsString('Replace Ada Lovelace · save $1.2M', self::text($html));
    }

    public function testTheSummaryOmitsTheSeparatorWithoutAFigure(): void
    {
        $text = self::text($this->render(self::panel(['figure' => null, 'answer' => 'Keep Ada Lovelace'])));

        $this->assertStringContainsString('Keep Ada Lovelace', $text);
        $this->assertStringNotContainsString('Keep Ada Lovelace ·', $text);
    }

    public function testAKeepPanelIsClosedAndEveryOtherVerdictIsOpen(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '#<details id="engineer-driver"[^>]*\sopen#',
            $this->render(self::panel(['verdict' => 'keep'])),
        );
        foreach (['replace', 'extend', 'hire'] as $verdict) {
            $this->assertMatchesRegularExpression(
                '#<details id="engineer-driver"[^>]*\sopen#',
                $this->render(self::panel(['verdict' => $verdict])),
                $verdict,
            );
        }
    }

    public function testTheShortlistHasOneRowPerDriverAndTheFirstIsThePick(): void
    {
        $html = $this->render(self::panel());

        $this->assertSame(3, substr_count($html, 'Grace Hopper') + substr_count($html, 'Alan Turing')
            + substr_count($html, 'Edsger Dijkstra'));
        $this->assertSame(3, preg_match_all('#<tbody.*?</tbody>#s', $html, $body) ? substr_count($body[0][0], '<tr') : 0);
        $this->assertSame(1, substr_count($html, 'Pick'));
        $this->assertMatchesRegularExpression('#Grace Hopper.*?Pick.*?Alan Turing#s', $html);
        $this->assertMatchesRegularExpression('#font-semibold text-emerald-700[^>]*>\s*Pick\s*<#', $html);
        $this->assertSame(1, substr_count($html, 'text-emerald-700'));
    }

    public function testTheScoreShowsTheGainOnlyWhenThereIsOne(): void
    {
        $text = self::text($this->render(self::panel()));

        $this->assertStringContainsString('71.2 (+6.1)', $text);
        $this->assertStringContainsString('66.5 (+2.5)', $text);
        $this->assertStringContainsString('68.0', $text);
        $this->assertStringNotContainsString('68.0 (', $text);
    }

    public function testTheCurrentDriverLineSaysEverything(): void
    {
        $text = self::text($this->render(self::panel()));

        foreach (['Ada Lovelace', 'Score 64.0', 'Overall 171', 'Age 31', 'Salary $2.4M · group average $2.2M',
            '3 races left'] as $part) {
            $this->assertStringContainsString($part, $text);
        }
    }

    public function testTheCurrentLineDropsWhatIsUnknown(): void
    {
        $panel = self::panel(['group_average' => null]);
        $panel['current']['rating'] = null;
        $panel['current']['races_left'] = 1;
        $text = self::text($this->render($panel));

        $this->assertStringNotContainsString('Score 64.0', $text);
        $this->assertStringNotContainsString('group average', $text);
        $this->assertStringContainsString('Salary $2.4M', $text);
        $this->assertStringContainsString('1 race left', $text);
        $this->assertStringNotContainsString('1 races left', $text);
    }

    public function testThereIsNoCurrentLineWithoutADriver(): void
    {
        $text = self::text($this->render(self::panel(['current' => null, 'extension' => null, 'verdict' => 'hire'])));

        $this->assertStringNotContainsString('races left', $text);
        $this->assertStringNotContainsString('Extension', $text);
        $this->assertStringContainsString('Grace Hopper', $text);
    }

    public function testAnInexactExtensionSaysAbout(): void
    {
        $exact = self::text($this->render(self::panel()));
        $inexact = self::text($this->render(self::panel(['extension' => ['fee' => '$4.0M', 'salary' => '$2.5M', 'exact' => false]])));

        $this->assertStringContainsString('Extension, 17 races: $4.0M fee, then $2.5M a race', $exact);
        $this->assertStringNotContainsString('about', $exact);
        $this->assertStringContainsString('Extension, 17 races: about $4.0M fee, then $2.5M a race', $inexact);
    }

    public function testNotesAndLeftOutCountsAreListed(): void
    {
        $text = self::text($this->render(self::panel()));

        $this->assertStringContainsString('Offers are not refunded.', $text);
        $this->assertStringContainsString('Left out: Retiring 2 · Has offers 5', $text);
    }

    public function testAnEmptyShortlistAndNoOutsDropTheirBlocks(): void
    {
        $html = $this->render(self::panel(['shortlist' => [], 'out' => [], 'notes' => []]));

        $this->assertStringNotContainsString('<table', $html);
        $this->assertStringNotContainsString('Left out', $html);
        $this->assertStringContainsString('A better driver is on the market', $html);
    }

    public function testTheMarketLinkPointsAtTheRecruitmentAnalyzer(): void
    {
        $this->assertMatchesRegularExpression(
            '#<a href="/recruitment"[^>]*>\s*See the whole market\s*</a>#',
            $this->render(self::panel()),
        );
    }

    public function testOnlyTheLessCriticalColumnsDieAtPhoneWidth(): void
    {
        $html = $this->render(self::panel());

        foreach (['To sign' => 'sm', 'Age' => 'md', 'Overall' => 'md', 'Favourites' => 'md'] as $head => $bp) {
            $this->assertMatchesRegularExpression(
                '#<th class="[^"]*hidden ' . $bp . ':table-cell[^"]*">\s*' . $head . '\s*</th>#',
                $html,
                $head,
            );
        }
        $this->assertDoesNotMatchRegularExpression('#<th class="[^"]*hidden[^"]*">\s*(Driver|Score|Salary|Fits)#', $html);
    }

    public function testNothingIsColouredAmberOrRedAndNumbersAreNeverMono(): void
    {
        $html = $this->render(self::panel());

        $this->assertStringNotContainsString('amber', $html);
        $this->assertStringNotContainsString('red-', $html);
        $this->assertStringNotContainsString('font-mono', $html);
        $this->assertStringNotContainsString('bg-white', $html);
        $this->assertStringNotContainsString('dark:', $html);
    }

    public function testEscapesWhatItPrints(): void
    {
        $html = $this->render(self::panel(['shortlist' => [self::candidate('<b>Evil</b>')]]));

        $this->assertStringNotContainsString('<b>Evil</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;Evil&lt;/b&gt;', $html);
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function tab(array $vars): string
    {
        return $this->twig()->render('partials/_tab_engineer.twig', $vars + [
            'csrf_token' => 'tok',
            'section_paths' => ['Recruitment Analyzer' => '/recruitment'],
        ]);
    }

    public function testTheNoPilotTabShowsTheNoticeAndThePanel(): void
    {
        $html = $this->tab([
            'cockpit_error' => StrategyController::NO_PILOT_MESSAGE,
            'engineer_driver' => ['line' => ['text' => 'Hire a driver'], 'panel' => self::panel(['current' => null])],
        ]);

        $this->assertStringContainsString('No driver under contract.', $html);
        $this->assertStringContainsString('id="engineer-driver"', $html);
        $this->assertStringContainsString('Grace Hopper', $html);
        $this->assertSame(1, substr_count($html, 'id="engineer-driver"'));
    }

    public function testTheNoPilotTabWithoutAPanelShowsTheNoticeAlone(): void
    {
        $html = $this->tab(['cockpit_error' => StrategyController::NO_PILOT_MESSAGE]);
        $nulled = $this->tab(['cockpit_error' => StrategyController::NO_PILOT_MESSAGE, 'engineer_driver' => null]);

        foreach ([$html, $nulled] as $page) {
            $this->assertStringContainsString('No driver under contract.', $page);
            $this->assertStringNotContainsString('engineer-driver', $page);
        }
    }

    public function testTheNormalTabShowsThePanelOnlyWhenTheEngineerHasOne(): void
    {
        $engineer = [
            'objective' => [
                'objective' => 'promote', 'label' => 'Promote', 'pinned' => false, 'proposed' => 'promote',
                'warning' => null,
            ],
            'lines' => [], 'season' => 107, 'tyres' => null,
        ];

        $with = $this->tab(['cockpit_data_loaded' => true, 'engineer' => $engineer + ['driver' => self::panel()]]);
        $without = $this->tab(['cockpit_data_loaded' => true, 'engineer' => $engineer + ['driver' => null]]);

        $this->assertStringContainsString('id="engineer-driver"', $with);
        $this->assertStringNotContainsString('engineer-driver', $without);
    }
}
