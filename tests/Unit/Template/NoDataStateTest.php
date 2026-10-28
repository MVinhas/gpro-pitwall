<?php

declare(strict_types=1);

namespace App\Tests\Unit\Template;

use App\Controller\StrategyController;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

/**
 * A manager with nothing stored sees one quiet empty state on every team
 * screen, never a "no driver" or "no supplier" claim about a team it has not
 * loaded yet.
 */
#[CoversNothing]
final class NoDataStateTest extends TestCase
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
        $this->twig->addGlobal('no_pilot_message', StrategyController::NO_PILOT_MESSAGE);
        $this->twig->addGlobal('generic_error_message', StrategyController::GENERIC_ERROR_MESSAGE);
        $this->twig->addGlobal('no_data_message', StrategyController::NO_DATA_MESSAGE);
    }

    /** @return iterable<string, array{string}> */
    public static function teamScreens(): iterable
    {
        yield 'cockpit' => ['Cockpit'];
        yield 'engineer' => ['Race Engineer'];
        yield 'strategy' => ['Race Strategy'];
        yield 'testing' => ['Testing'];
        yield 'car wear' => ['Car Wear Planner'];
    }

    #[DataProvider('teamScreens')]
    public function testATeamScreenWithNoDataShowsOnlyTheWayToLoadIt(string $section): void
    {
        $html = $this->twig->render('index.twig', ['active_main_tab' => $section, 'no_data' => true]);

        $this->assertNoDataState($html);
    }

    public function testTheCockpitWithNothingLoadedSaysTheSame(): void
    {
        $this->assertNoDataState($this->twig->render('partials/_tab_cockpit.twig', []));
    }

    public function testTheEngineerWithNothingLoadedSaysTheSame(): void
    {
        $this->assertNoDataState($this->twig->render('partials/_tab_engineer.twig', []));
    }

    private function assertNoDataState(string $html): void
    {
        $text = (string) preg_replace('/\s+/', ' ', strip_tags($html));

        $text = html_entity_decode($text, ENT_QUOTES);

        $this->assertTrue(str_contains($text, "No data yet Re-sync to load your team's data."), 'the no-data state');
        $this->assertDoesNotMatchRegularExpression('/class="[^"]*\\bnotice\\b/', $html, 'ink, not a coloured notice');
        $this->assertFalse(str_contains($text, 'No driver under contract'), 'a no-driver claim');
        $this->assertFalse(str_contains($text, 'No synced data yet'), 'the old copy');
    }
}
