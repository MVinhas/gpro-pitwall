<?php

declare(strict_types=1);

namespace App\Tests\Unit\Template;

use App\Controller\StrategyController;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The manager can race on a set other than the best one: the Race sheet
 * follows their pick, and the best set stays marked in the comparison.
 */
#[CoversNothing]
final class ChosenCompoundTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $this->twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'));
        $this->twig->addGlobal('no_pilot_message', StrategyController::NO_PILOT_MESSAGE);
        $this->twig->addGlobal('generic_error_message', StrategyController::GENERIC_ERROR_MESSAGE);
    }

    public function testTheRaceSheetFollowsTheBestSetByDefault(): void
    {
        $sheet = $this->raceSheet($this->render([]));

        $this->assertStringContainsString('Medium', $sheet);
        $this->assertStringContainsString('2 stops', $sheet);
        $this->assertStringContainsString('laps 20, 40', $sheet);
        $this->assertStringNotContainsString('slower than', $sheet);
    }

    public function testTheRaceSheetFollowsTheChosenSet(): void
    {
        $sheet = $this->raceSheet($this->render(['chosen_compound' => 'Hard']));

        $this->assertStringContainsString('Hard', $sheet);
        $this->assertStringContainsString('1 stop', $sheet);
        $this->assertStringContainsString('90 L', $sheet);
        $this->assertStringContainsString('lap 30', $sheet);
        $this->assertStringContainsString('10s slower than Medium', $sheet);
    }

    public function testTheBestSetStaysMarkedWhenAnotherIsChosen(): void
    {
        $html = $this->render(['chosen_compound' => 'Hard']);

        $this->assertMatchesRegularExpression('/value="Hard"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/<tr[^>]*aria-current="true"[^>]*>\s*<th[^>]*>.*?Medium.*?Best/s', $html);
    }

    public function testTheBestRowFollowsTheBestWhateverItBecomes(): void
    {
        // The best row posts an empty choice, so an untouched sheet keeps
        // following the best set as the settings change.
        $html = $this->render([]);

        $this->assertMatchesRegularExpression('/name="compound" value=""[^>]*checked/', $html);
        $this->assertStringContainsString('value="Hard"', $html);
    }

    public function testAWetRaceOffersNoChoice(): void
    {
        $html = $this->render([
            'best_compound'  => 'Rain',
            'weather_inputs' => ['Race' => ['weather' => 'Wet']],
        ]);

        $this->assertStringNotContainsString('name="compound"', $html);
    }

    private function raceSheet(string $html): string
    {
        $start = strpos($html, 'id="race-sheet-title"');
        $end = strpos($html, 'id="tyre-comparison-title"');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return (string) preg_replace('/\s+/', ' ', strip_tags(substr($html, $start, $end - $start)));
    }

    /** @param array<string, mixed> $overrides */
    private function render(array $overrides): string
    {
        $tyre = static fn(int $stops, int $fuel, array $pitLaps, float $lost): array => [
            'stops'            => $stops,
            'fuel_recommended' => $fuel,
            'fuel_load'        => $fuel - 3,
            'pit_laps'         => $pitLaps,
            'pit_time_est'     => 24.0,
            'lost_pits'        => 0.0,
            'lost_fuel'        => 0.0,
            'lost_tcd'         => 0.0,
            'total_lost'       => $lost,
            'net_lost'         => $lost,
        ];

        return $this->twig->render('partials/_strategy_results.twig', [
            'strategy_results' => $overrides + [
                'track'           => 'Monza',
                'best_compound'   => 'Medium',
                'ctr_gain_per_lap' => 0,
                'fuel'            => ['l_per_lap' => 3.0, 'dry' => 180, 'wet' => 180, 'wet_estimated' => false],
                'inputs'          => ['laps' => 60, 'first_stop' => 'even'],
                'weather_inputs'  => ['Race' => ['weather' => 'Dry']],
                'tyres'           => [
                    'Medium' => $tyre(2, 60, [20, 40], 100.0),
                    'Hard'   => $tyre(1, 90, [30], 110.0),
                    'Rain'   => $tyre(2, 60, [20, 40], 140.0),
                ],
            ],
        ]);
    }
}
