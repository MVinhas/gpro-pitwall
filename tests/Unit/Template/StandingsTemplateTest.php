<?php

declare(strict_types=1);

namespace App\Tests\Unit\Template;

use App\Engineer\SeasonObjective;
use App\Standings\SeasonStandings;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

/**
 * The Standings screen renders from SeasonStandings' view data, including the
 * cases the class alone cannot show: a table with no row for the manager.
 */
#[CoversNothing]
final class StandingsTemplateTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $this->twig = new Environment(new ChainLoader([
            new ArrayLoader([
                'layout.twig' => '{% block content %}{% endblock %}',
                'partials/_nav.twig' => '',
            ]),
            new FilesystemLoader(dirname(__DIR__, 3) . '/templates'),
        ]));
    }

    /** @return array<string, mixed> */
    private static function payload(): array
    {
        $manager = static fn (int $pos, int $idm, array $finishes, string $colour = ''): array => [
            'name' => 'Manager ' . $pos, 'pos' => (string) $pos, 'IDM' => $idm, 'manBgColor' => $colour,
            'pts' => (string) array_sum(array_map(static fn (int $f): int => [1 => 10, 2 => 8][$f] ?? 6, $finishes)),
            'results' => array_pad(
                array_map(static fn (int $f): array => ['pos' => (string) $f, 'pts' => '0', 'grid' => '-'], $finishes),
                SeasonObjective::RACES,
                ['pos' => '-', 'pts' => '-', 'grid' => '-'],
            ),
        ];

        return ['group' => 'Rookie - 1', 'managers' => [
            $manager(1, 1, [1, 1, 1], SeasonObjective::GREEN),
            $manager(2, 2, [2, 2, 2], SeasonObjective::GREEN),
            $manager(3, 3, [3, 3, 3]),
        ]];
    }

    /** @param array<string, mixed> $view */
    private function render(array $view): string
    {
        return $this->twig->render('standings/index.twig', $view + ['fetched_at' => null]);
    }

    public function testATableWithoutTheManagersRowRendersWithANotice(): void
    {
        // Three races run: the chart would draw, and used to fail on an empty series.
        $view = SeasonStandings::build(self::payload(), null);
        self::assertNull($view['own']);
        self::assertSame(3, $view['races_run']);

        $html = $this->render($view);

        self::assertStringContainsString('data-standings-no-own', $html);
        self::assertStringContainsString('Race by race', $html);
        self::assertStringNotContainsString('id="stChart"', $html);
    }

    public function testTheFinalTableOfASeasonSaysSo(): void
    {
        $view = SeasonStandings::build(self::payload(), 2);

        self::assertStringNotContainsString('data-standings-final', $this->render($view));
        $html = $this->render(['final_table' => true, 'season' => 113] + $view);
        self::assertStringContainsString('data-standings-final', $html);
        self::assertStringContainsString('final table of Season 113', $html);
    }

    public function testColourMarksStateOnly(): void
    {
        // Blue is interactive or selected, emerald best, amber caution, red fails; the rest is ink.
        // Manager 2 can secure promotion alone (135 of the 140 points left), so the row shows that badge.
        $html = $this->render(SeasonStandings::build(self::payload(), 2));

        self::assertStringContainsString('bg-slate-100 text-slate-700">In your hands', $html);
        self::assertStringNotContainsString('bg-blue-100', $html, 'a status badge must not read as clickable');
        self::assertStringNotContainsString('text-red-700', $html, 'a signed gap needs no colour');
        self::assertStringContainsString('backdrop:bg-scrim', $html);
        self::assertStringNotContainsString('backdrop:bg-slate', $html);
    }

    public function testTheChartTakesItsColoursFromThemeTokens(): void
    {
        $html = $this->render(SeasonStandings::build(self::payload(), 2));
        $chart = substr($html, (int) strpos($html, 'id="stChart"'));

        self::assertStringContainsString('stroke-chart-you', $chart);
        self::assertStringContainsString('stroke-chart-1', $chart);
        self::assertDoesNotMatchRegularExpression('/(stroke|fill)="#[0-9a-f]{3,6}"/i', $chart);
        self::assertDoesNotMatchRegularExpression("/'#[0-9a-f]{6}'/i", $chart, 'no hex colour in the chart script');
    }

    public function testTheManagersOwnTableShowsGoalsAndChart(): void
    {
        $html = $this->render(SeasonStandings::build(self::payload(), 2));

        self::assertStringNotContainsString('data-standings-no-own', $html);
        self::assertStringContainsString('What it takes', $html);
        self::assertStringContainsString('id="stChart"', $html);
    }
}
