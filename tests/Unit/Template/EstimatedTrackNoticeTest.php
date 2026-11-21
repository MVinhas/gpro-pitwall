<?php

declare(strict_types=1);

namespace App\Tests\Unit\Template;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * A new GPRO track's row is a best guess: every screen reading one says so at the top, naming it once.
 */
#[CoversNothing]
final class EstimatedTrackNoticeTest extends TestCase
{
    /**
     * @param list<?string> $names
     * @param ?list<string> $estimated null leaves the global unset, as in a screen test that never sets it
     */
    private function render(array $names, ?array $estimated): string
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'), ['strict_variables' => true]);
        if ($estimated !== null) {
            $twig->addGlobal('estimated_tracks', $estimated);
        }

        return $twig->render('partials/_estimated_track.twig', ['names' => $names]);
    }

    public function testNamesTheFirstEstimatedTrackOnce(): void
    {
        $html = $this->render(['Kyalami', null, 'Hanoi', 'Hanoi'], ['Hanoi']);

        self::assertSame(1, substr_count($html, 'is a new track'));
        self::assertStringContainsString('Hanoi is a new track.', $html);
        self::assertStringContainsString('best guesses from the other tracks until its races tune them', $html);
    }

    public function testMatchesARaceNameWithItsCountry(): void
    {
        self::assertStringContainsString(
            'Hanoi (Vietnam) is a new track.',
            $this->render(['Hanoi (Vietnam)'], ['Hanoi']),
        );
    }

    public function testSaysNothingForKnownTracks(): void
    {
        self::assertSame('', trim($this->render(['Kyalami', null], ['Hanoi'])));
    }

    public function testSaysNothingWithoutTheGlobal(): void
    {
        self::assertSame('', trim($this->render(['Hanoi'], null)));
    }
}
