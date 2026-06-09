<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\PageController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PageController::class)]
final class PageControllerTest extends TestCase
{
    private const TRACKS = ['Buenos Aires', 'Monte Carlo', 'Monza'];

    public function testDefaultsToTheNextRaceTrackWhenKnown(): void
    {
        $this->assertSame(
            'Monte Carlo',
            PageController::resolveDefaultTrack(self::TRACKS, 'Monte Carlo'),
        );
    }

    public function testFallsBackToFirstTrackWhenNoNextRaceCached(): void
    {
        // Pre-first-sync: no Office data, so trackName is empty.
        $this->assertSame(
            'Buenos Aires',
            PageController::resolveDefaultTrack(self::TRACKS, ''),
        );
    }

    public function testFallsBackToFirstTrackWhenNextRaceIsUnknown(): void
    {
        // Defensive: a trackName that isn't in our config must not leak through.
        $this->assertSame(
            'Buenos Aires',
            PageController::resolveDefaultTrack(self::TRACKS, 'Nowhere Speedway'),
        );
    }

    public function testEmptyTrackListYieldsEmptyString(): void
    {
        $this->assertSame('', PageController::resolveDefaultTrack([], 'Monte Carlo'));
    }
}
