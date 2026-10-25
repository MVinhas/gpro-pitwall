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
}
