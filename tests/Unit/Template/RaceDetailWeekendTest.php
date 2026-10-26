<?php

declare(strict_types=1);

namespace App\Tests\Unit\Template;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The Weekend accordion names parts and people the way the changes table does.
 */
#[CoversNothing]
final class RaceDetailWeekendTest extends TestCase
{
    private function text(): string
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'));
        $html = $twig->render('debrief/_race_detail.twig', [
            'sort' => 'recent',
            'race_options' => [],
            'detail' => [
                'season' => 107, 'race' => 13, 'track_name' => 'Testring',
                'weekend' => [
                    'driver' => ['id' => 9, 'name' => 'B. Newcomer', 'profile' => ['techInsight' => 70]],
                    'td' => null,
                    'staff' => null,
                    'facilities' => null,
                    'car' => ['power' => 10, 'parts' => [
                        'fWing' => ['level' => 4, 'wear' => 0],
                        'susp' => ['level' => 5, 'wear' => 0],
                        'gear' => ['level' => 6, 'wear' => 0],
                    ]],
                    'cash' => 1,
                    'changes' => [
                        ['group' => 'driver', 'key' => 'id', 'label' => 'Driver', 'from' => 'A. Driver', 'to' => 'B. Newcomer'],
                    ],
                    'changed' => ['driver.id' => true, 'car.susp' => true],
                ],
            ],
        ]);

        return (string) preg_replace('/\s+/', ' ', strip_tags($html));
    }

    public function testCarPartsUseTheSameLabelsAsTheChangesTable(): void
    {
        $text = $this->text();

        $this->assertStringContainsString('Front wing level', $text);
        $this->assertStringContainsString('Suspension level · changed', $text);
        $this->assertStringContainsString('Gearbox level', $text);
        $this->assertStringNotContainsString('fWing', $text);
        $this->assertStringNotContainsString('susp level', $text);
    }

    public function testDriverAttributesUseTheDiffLabels(): void
    {
        $this->assertStringContainsString('Technical insight', $this->text());
    }

    public function testAHireShowsNamesInTheChangesTable(): void
    {
        $this->assertMatchesRegularExpression('/Driver A\. Driver B\. Newcomer/', $this->text());
    }

    public function testEconomyAndFuelTilesStackTwoAcrossOnAPhone(): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'));
        $html = $twig->render('debrief/_race_detail.twig', [
            'sort' => 'recent',
            'race_options' => [],
            'detail' => [
                'season' => 107, 'race' => 13, 'track_name' => 'Testring',
                'economy' => ['lines' => [], 'income' => 15000000, 'costs' => 2250000, 'net' => 12750000],
                'calibration' => [
                    'wear' => null,
                    'fuel' => ['wet' => false, 'predicted' => 2.0, 'actual' => 2.126, 'gap_pct' => 6.3, 'flagged' => true],
                ],
            ],
        ]);

        $this->assertStringContainsString('$15,000,000', $html);
        $this->assertSame(2, preg_match_all('/class="grid grid-cols-2 sm:grid-cols-3 gap-2/', $html));
        $this->assertStringNotContainsString('grid grid-cols-3 gap-2 mb-4', $html);
    }

    /**
     * @param array<string, mixed> $detail
     */
    private function calibrationText(array $detail): string
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'));
        $html = $twig->render('debrief/_race_detail.twig', [
            'sort' => 'recent',
            'race_options' => [],
            'detail' => [
                'season' => 107, 'race' => 13, 'track_name' => 'Testring',
                'calibration' => [
                    'wear' => null,
                    'fuel' => ['wet' => false, 'predicted' => 2.0, 'actual' => 2.126, 'gap_pct' => 6.3, 'flagged' => true],
                ],
                ...$detail,
            ],
        ]);

        return (string) preg_replace('/\s+/', ' ', strip_tags($html));
    }

    public function testARetiredRaceSaysWhyWearWasNotCompared(): void
    {
        $text = $this->calibrationText(['dnf' => 1, 'laps_completed' => 23, 'laps_total' => 60]);

        $this->assertStringContainsString('Retired on lap 23 — wear not compared.', $text);
        $this->assertStringContainsString('Fuel per km', $text);
    }

    public function testAFinishedRaceWithoutWearSaysNothingAboutRetiring(): void
    {
        $this->assertStringNotContainsString('Retired on lap', $this->calibrationText(['dnf' => 0]));
    }

    public function testTheWetNoteFollowsTheRacesOwnWetFlag(): void
    {
        $this->assertStringContainsString('not counted in the season bias', $this->calibrationText(['was_wet' => 1]));
        $this->assertStringNotContainsString('not counted in the season bias', $this->calibrationText(['was_wet' => 0]));
    }
}
