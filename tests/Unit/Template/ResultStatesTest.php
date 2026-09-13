<?php

declare(strict_types=1);

namespace App\Tests\Unit\Template;

use App\Controller\StrategyController;
use App\Controller\TestingController;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The Strategy results panel must be in exactly one state at a time.
 *
 * It used to render the error notice and then, as an unconditional second
 * block, the "Click Calculate Strategy" empty prompt — two contradictory
 * states, and the empty prompt's full-height box ran under the page footer.
 * An *expected* outcome (season over, no tyre supplier) also rendered as
 * "calculation failed", while Testing showed the same real failure in the
 * caution tone.
 */
#[CoversNothing]
final class ResultStatesTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $this->twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'));
        $this->twig->addGlobal('no_pilot_message', StrategyController::NO_PILOT_MESSAGE);
        $this->twig->addGlobal('generic_error_message', StrategyController::GENERIC_ERROR_MESSAGE);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function strategyStates(): iterable
    {
        $results = ['track' => 'Monza', 'best_compound' => 'Soft', 'tyres' => [], 'inputs' => []];

        yield 'no driver'           => [['strategy_error' => StrategyController::NO_PILOT_MESSAGE], 'no-driver'];
        yield 'failed'              => [['strategy_error' => StrategyController::GENERIC_ERROR_MESSAGE], 'failed'];
        yield 'season over'         => [['strategy_error' => StrategyController::END_OF_SEASON_MESSAGE], 'unavailable'];
        yield 'no supplier'         => [['strategy_error' => StrategyController::NO_SUPPLIER_MESSAGE], 'unavailable'];
        yield 'results'             => [['strategy_results' => $results], 'results'];
        yield 'empty'               => [[], 'empty'];
        // A failed recalculation must not sit on top of the previous success.
        yield 'error beats results' => [
            ['strategy_error' => StrategyController::GENERIC_ERROR_MESSAGE, 'strategy_results' => $results],
            'failed',
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    #[DataProvider('strategyStates')]
    public function testStrategyPanelRendersExactlyOneState(array $context, string $expected): void
    {
        $html = $this->twig->render('partials/_strategy_results.twig', $context);

        preg_match_all('/data-result-state="([a-z-]+)"/', $html, $m);
        $this->assertSame([$expected], $m[1], 'exactly one result state may render');

        if ($expected !== 'empty') {
            $this->assertStringNotContainsString('Calculate Strategy', $html);
        }
    }

    public function testStrategyToneSeparatesFailureFromExpectedUnavailability(): void
    {
        $failed = $this->twig->render('partials/_strategy_results.twig', [
            'strategy_error' => StrategyController::GENERIC_ERROR_MESSAGE,
        ]);
        $seasonOver = $this->twig->render('partials/_strategy_results.twig', [
            'strategy_error' => StrategyController::END_OF_SEASON_MESSAGE,
        ]);

        $this->assertStringContainsString('notice-error', $failed);
        $this->assertStringNotContainsString('notice-error', $seasonOver);
        $this->assertStringNotContainsString('failed', strtolower(strip_tags($seasonOver)));
    }

    public function testTestingUsesTheSameToneSplit(): void
    {
        $failed = $this->twig->render('partials/_tab_testing.twig', [
            'testing_error' => StrategyController::GENERIC_ERROR_MESSAGE,
        ]);
        $closed = $this->twig->render('partials/_tab_testing.twig', [
            'testing_error' => TestingController::CLOSED_MESSAGE,
        ]);

        $this->assertStringContainsString('notice-error', $failed);
        $this->assertStringContainsString('notice-warn', $closed);
        $this->assertStringNotContainsString('notice-error', $closed);
    }
}
