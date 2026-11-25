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

    public function testTheManagersOwnTableShowsGoalsAndChart(): void
    {
        $html = $this->render(SeasonStandings::build(self::payload(), 2));

        self::assertStringNotContainsString('data-standings-no-own', $html);
        self::assertStringContainsString('What it takes', $html);
        self::assertStringContainsString('id="stChart"', $html);
    }
}
