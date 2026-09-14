<?php

declare(strict_types=1);

namespace App\Tests\Unit\Template;

use App\Controller\StrategyController;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Push signals on the Race sheet: plain verdicts, and tyre signals only where
 * a manager chooses the tyre supplier.
 */
#[CoversNothing]
final class PushSignalsTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $this->twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'));
        $this->twig->addGlobal('no_pilot_message', StrategyController::NO_PILOT_MESSAGE);
        $this->twig->addGlobal('generic_error_message', StrategyController::GENERIC_ERROR_MESSAGE);
    }

    public function testTyreSignalsAreLeftOutWhereTheDivisionHasNoTyreChoice(): void
    {
        $text = $this->chips(['show_tyres' => false]);

        $this->assertStringNotContainsString('Tyres not good', $text);
        $this->assertStringNotContainsString('for the tyres', $text);
        $this->assertStringContainsString('Car suits the track', $text);
    }

    public function testTyreSignalsShowWhereTheManagerPicksTheSupplier(): void
    {
        $text = $this->chips(['show_tyres' => true]);

        $this->assertStringContainsString('Tyres not good for rain', $text);
        $this->assertStringContainsString('Too hot for the tyres', $text);
    }

    public function testStandingIsAWordAndTheRankIsOnlyInTheHoverTitle(): void
    {
        $html = $this->render(['driver_rank' => 20, 'driver_total' => 39, 'driver_above' => false, 'driver_below' => false]);

        $this->assertStringContainsString('Driver mid-group', strip_tags($html));
        $this->assertStringNotContainsString('#20 of 39', strip_tags($html));
        $this->assertStringContainsString('title="#20 of 39', $html);
    }

    /** @param array<string, mixed> $signals */
    private function chips(array $signals): string
    {
        return strip_tags($this->render($signals));
    }

    /** @param array<string, mixed> $signals */
    private function render(array $signals): string
    {
        return $this->twig->render('partials/_strategy_results.twig', [
            'strategy_results' => [
                'track'         => 'Monza',
                'best_compound' => 'Rain',
                'tyres'         => [],
                'inputs'        => [],
                'push_signals'  => $signals + [
                    'pha_match'     => true,
                    'pha_level'     => 'top',
                    'favourite'     => false,
                    'show_tyres'    => true,
                    'tyres_weather' => false,
                    'tyre_perf'     => 3,
                    'race_wet'      => true,
                    'temp_match'    => false,
                    'race_temp'     => 42.0,
                    'ideal_temp'    => 21,
                    'car_rank'      => null,
                    'driver_rank'   => null,
                    'wear_ok'       => null,
                ],
            ],
        ]);
    }
}
