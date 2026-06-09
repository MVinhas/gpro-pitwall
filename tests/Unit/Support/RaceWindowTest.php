<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\RaceWindow;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RaceWindow::class)]
final class RaceWindowTest extends TestCase
{
    private const array TUE_FRI = [2, 5];

    private function at(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone('Europe/London'));
    }

    public function testIdIsTheMostRecentRaceDayBoundary(): void
    {
        // 2026-06-10 is a Wednesday; the most recent race day is Tue 2026-06-09.
        $this->assertSame(
            '2026-06-09',
            RaceWindow::idFor($this->at('2026-06-10 14:00'), self::TUE_FRI, 0, 'Europe/London'),
        );
    }

    public function testWindowRollsAtTheNextRaceDay(): void
    {
        // Thursday still belongs to Tuesday's window…
        $thu = RaceWindow::idFor($this->at('2026-06-11 23:59'), self::TUE_FRI, 0, 'Europe/London');
        // …Friday opens a new one.
        $fri = RaceWindow::idFor($this->at('2026-06-12 00:01'), self::TUE_FRI, 0, 'Europe/London');

        $this->assertSame('2026-06-09', $thu);
        $this->assertSame('2026-06-12', $fri);
        $this->assertNotSame($thu, $fri, 'a new race day must open a new window');
    }

    public function testRaceDayMorningAlreadySitsInTheNewWindow(): void
    {
        // A manager doing Tuesday-morning qualifying gets Tuesday's window,
        // not the previous Friday's — the whole point of the boundary.
        $this->assertSame(
            '2026-06-09',
            RaceWindow::idFor($this->at('2026-06-09 08:00'), self::TUE_FRI, 0, 'Europe/London'),
        );
    }

    public function testBoundaryHourDefersTheRoll(): void
    {
        // With a midday boundary, race-day morning is still the prior window.
        $before = RaceWindow::idFor($this->at('2026-06-09 09:00'), self::TUE_FRI, 12, 'Europe/London');
        $after  = RaceWindow::idFor($this->at('2026-06-09 13:00'), self::TUE_FRI, 12, 'Europe/London');

        $this->assertSame('2026-06-05', $before, 'before the boundary hour, still Friday\'s window');
        $this->assertSame('2026-06-09', $after);
    }

    public function testEmptyRaceDaysDisablesWindowing(): void
    {
        $this->assertSame('', RaceWindow::idFor($this->at('2026-06-10 14:00'), [], 0, 'Europe/London'));
    }

    public function testIdIsStableWithinAWindow(): void
    {
        $a = RaceWindow::idFor($this->at('2026-06-09 08:00'), self::TUE_FRI, 0, 'Europe/London');
        $b = RaceWindow::idFor($this->at('2026-06-10 20:00'), self::TUE_FRI, 0, 'Europe/London');
        $this->assertSame($a, $b, 'reads within one window must hit the same key');
    }
}
