<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\EarlyQualifyingBonusService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EarlyQualifyingBonusService::class)]
final class EarlyQualifyingBonusServiceTest extends TestCase
{
    private EarlyQualifyingBonusService $svc;

    protected function setUp(): void
    {
        $this->svc = new EarlyQualifyingBonusService([2, 5]);
    }

    private static function utc(string $at): DateTimeImmutable
    {
        return new DateTimeImmutable($at, new DateTimeZone('UTC'));
    }

    /** @return array<string, mixed> */
    private static function office(array $overrides = []): array
    {
        return $overrides + [
            'seasonNb'        => '113',
            'endOfSeason'     => 0,
            'secondsLeft'     => '187322',
            'secondsLeftQual' => '181922',
            'doneQ1'          => '0',
            'doneQ2'          => '0',
        ];
    }

    public function testBeforeSeason113ItPreviewsTheBonusForTheClass(): void
    {
        $r = $this->svc->assess(self::office(['seasonNb' => '112']), 'Amateur', self::utc('2026-09-21 12:00'), null);

        $this->assertNotNull($r);
        $this->assertSame('preview', $r['state']);
        $this->assertSame(400000, $r['per_session']);
        $this->assertSame(113, $r['first_season']);
    }

    public function testPreviewSurvivesTheEndOfSeasonBreak(): void
    {
        $r = $this->svc->assess(self::office(['seasonNb' => '112', 'endOfSeason' => 1]), 'Rookie', self::utc('2026-12-28 12:00'), null);

        $this->assertNotNull($r);
        $this->assertSame('preview', $r['state']);
        $this->assertSame(200000, $r['per_session']);
    }

    public function testMoreThanADayOutEverySessionPaysInFull(): void
    {
        // Tue 22 Sep 2026 race: 20:00 Paris (CEST) = 18:00 UTC.
        $r = $this->svc->assess(self::office(), 'Elite', self::utc('2026-09-21 12:00'), null);

        $this->assertNotNull($r);
        $this->assertSame('full', $r['state']);
        $this->assertSame(1000000, $r['per_session']);
        $this->assertSame(1.0, $r['share']);
        $this->assertSame('2026-09-21 18:00:00', $r['full_until']);
        $this->assertSame('2026-09-22 16:30:00', $r['closes_at']);
        $this->assertSame(['q1' => false, 'q2' => false], $r['sessions']);
    }

    public function testInsideTheLastDayTheBonusFallsInAStraightLineToQualifyingClose(): void
    {
        // 12 h before the race: 10.5 h of a 22.5 h taper left, "around half".
        $r = $this->svc->assess(self::office(), 'Elite', self::utc('2026-09-22 06:00'), null);

        $this->assertNotNull($r);
        $this->assertSame('shrinking', $r['state']);
        $this->assertEqualsWithDelta(0.4667, $r['share'], 0.0001);
        $this->assertSame(470000, $r['now_per_session']);
    }

    public function testQualifyingCloseComesFromTheOfficeCountdownGap(): void
    {
        $r = $this->svc->assess(
            self::office(['secondsLeft' => '10000', 'secondsLeftQual' => '6400']),
            'Pro',
            self::utc('2026-09-21 12:00'),
            null,
        );

        $this->assertNotNull($r);
        $this->assertSame('2026-09-22 17:00:00', $r['closes_at']);
    }

    public function testMissingCountdownFallsBackToNinetyMinutes(): void
    {
        $r = $this->svc->assess(
            self::office(['secondsLeft' => '', 'secondsLeftQual' => '']),
            'Pro',
            self::utc('2026-09-21 12:00'),
            null,
        );

        $this->assertNotNull($r);
        $this->assertSame('2026-09-22 16:30:00', $r['closes_at']);
    }

    public function testBothSessionsDoneIsBanked(): void
    {
        $r = $this->svc->assess(
            self::office(['doneQ1' => '1', 'doneQ2' => '1']),
            'Master',
            self::utc('2026-09-22 06:00'),
            '2026-09-21 10:00:00',
        );

        $this->assertNotNull($r);
        $this->assertSame('banked', $r['state']);
        $this->assertSame(['q1' => true, 'q2' => true], $r['sessions']);
    }

    public function testOneSessionDoneStillCountsDownTheOther(): void
    {
        $r = $this->svc->assess(self::office(['doneQ1' => '1']), 'Master', self::utc('2026-09-21 12:00'), null);

        $this->assertNotNull($r);
        $this->assertSame('full', $r['state']);
        $this->assertSame(['q1' => true, 'q2' => false], $r['sessions']);
    }

    public function testSessionFlagsFromBeforeTheLastRaceAreNotTrusted(): void
    {
        // Synced Tue morning, now Wed: Tuesday's race has run since, so the
        // done flags describe the previous race, not Friday's.
        $r = $this->svc->assess(
            self::office(['doneQ1' => '1', 'doneQ2' => '1']),
            'Master',
            self::utc('2026-09-23 12:00'),
            '2026-09-22 08:00:00',
        );

        $this->assertNotNull($r);
        $this->assertSame('full', $r['state']);
        $this->assertNull($r['sessions']);
    }

    public function testNothingToShowOnceQualifyingHasClosed(): void
    {
        $this->assertNull($this->svc->assess(self::office(), 'Elite', self::utc('2026-09-22 17:00'), null));
    }

    public function testNothingToShowWhileTheRaceIsRunning(): void
    {
        // 21:00 Paris: the Office still describes tonight's race until it ends.
        $this->assertNull($this->svc->assess(self::office(), 'Elite', self::utc('2026-09-22 19:00'), '2026-09-22 10:00:00'));
    }

    public function testOnceTheRaceHasRunItLooksAheadToTheNextOne(): void
    {
        $r = $this->svc->assess(
            self::office(['doneQ1' => '1', 'doneQ2' => '1']),
            'Elite',
            self::utc('2026-09-22 20:30'),
            '2026-09-22 10:00:00',
        );

        $this->assertNotNull($r);
        $this->assertSame('full', $r['state']);
        $this->assertSame('2026-09-24 18:00:00', $r['full_until']);
        $this->assertNull($r['sessions']);
    }

    public function testWinterRacesStartAtNineteenUtc(): void
    {
        // Fri 15 Jan 2027: 20:00 Paris (CET) = 19:00 UTC.
        $r = $this->svc->assess(self::office(), 'Elite', self::utc('2027-01-14 12:00'), null);

        $this->assertNotNull($r);
        $this->assertSame('2027-01-14 19:00:00', $r['full_until']);
    }

    public function testNoRowWithoutAKnownClassOrOutsideARaceWeekend(): void
    {
        $now = self::utc('2026-09-21 12:00');
        $this->assertNull($this->svc->assess(self::office(), null, $now, null));
        $this->assertNull($this->svc->assess(self::office(['endOfSeason' => 1]), 'Elite', $now, null));
        $this->assertNull($this->svc->assess(self::office(['seasonNb' => '']), 'Elite', $now, null));
        $this->assertNull((new EarlyQualifyingBonusService([]))->assess(self::office(), 'Elite', $now, null));
    }
}
