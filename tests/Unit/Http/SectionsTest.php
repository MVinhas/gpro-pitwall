<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\Sections;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Sections::class)]
final class SectionsTest extends TestCase
{
    public function testEverySectionInTheAppConfigHasAPath(): void
    {
        /** @var array{main_sections: list<string>} $config */
        $config = require dirname(__DIR__, 3) . '/config/game_constants.php';

        foreach ($config['main_sections'] as $section) {
            $this->assertNotNull(
                Sections::pathFor($section),
                "section '{$section}' has no URL path — the nav would link it to /",
            );
        }
    }

    public function testPathsAndSectionsRoundTrip(): void
    {
        foreach (Sections::all() as $section => $path) {
            $this->assertSame($section, Sections::sectionFor($path));
            $this->assertSame($path, Sections::pathFor($section));
        }
    }

    public function testPathsAreUnique(): void
    {
        $paths = array_values(Sections::all());

        // Two sections sharing a path would make one unreachable.
        $this->assertSame($paths, array_unique($paths));
    }

    #[DataProvider('aliases')]
    public function testShortLabelsAndRetiredTabsStillResolve(string $given, string $expected): void
    {
        $this->assertSame($expected, Sections::canonical($given));
    }

    /** @return list<array{0: string, 1: string}> */
    public static function aliases(): array
    {
        return [
            ['Strategy', 'Race Strategy'],
            ['Training', 'Training Planner'],
            ['Recruitment', 'Recruitment Analyzer'],
            // Retired in 1.15.9; its bookmarks belong on the cockpit.
            ['Car Wear', 'Cockpit'],
            // A canonical name is already canonical.
            ['Cockpit', 'Cockpit'],
            ['Division Baseline', 'Division Baseline'],
        ];
    }

    public function testAnAliasResolvesToTheTargetsPath(): void
    {
        $this->assertSame('/strategy', Sections::pathFor('Strategy'));
        $this->assertSame('/cockpit', Sections::pathFor('Car Wear'));
    }

    public function testAnUnknownNameHasNoPath(): void
    {
        $this->assertNull(Sections::pathFor('Nonsense'));
        $this->assertNull(Sections::pathFor(''));
    }

    public function testAnUnknownPathSelectsNoSection(): void
    {
        $this->assertNull(Sections::sectionFor('/nope'));
        $this->assertNull(Sections::sectionFor('/'));
        $this->assertNull(Sections::sectionFor(''));
    }

    public function testATrailingSlashStillSelectsTheSection(): void
    {
        $this->assertSame('Cockpit', Sections::sectionFor('/cockpit/'));
    }

    public function testUrlAppendsOnlyTheParametersThatHaveValues(): void
    {
        $this->assertSame('/cockpit', Sections::url('Cockpit'));
        $this->assertSame('/cockpit', Sections::url('Cockpit', ['track' => null, 'page' => '']));
        $this->assertSame(
            '/divisions/baseline?division_tab=Rookie',
            Sections::url('Division Baseline', ['division_tab' => 'Rookie']),
        );
    }

    public function testUrlEncodesParameterValues(): void
    {
        $url = Sections::url('Race Strategy', ['track' => 'Buenos Aires']);

        $this->assertStringStartsWith('/strategy?', $url);
        $this->assertStringContainsString('track=Buenos+Aires', $url);
    }

    public function testUrlFallsBackToRootForANameThatIsNotASection(): void
    {
        $this->assertSame('/', Sections::url('Nonsense'));
    }

    public function testPathsAreLowercaseAndCarryNoQueryString(): void
    {
        foreach (Sections::all() as $section => $path) {
            $this->assertStringStartsWith('/', $path, $section);
            $this->assertSame(strtolower($path), $path, $section);
            $this->assertStringNotContainsString('?', $path, $section);
            $this->assertStringNotContainsString(' ', $path, $section);
        }
    }
}
