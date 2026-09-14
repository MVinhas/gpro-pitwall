<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\RaceSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RaceSettings::class)]
final class RaceSettingsTest extends TestCase
{
    public function testAValueSetOnOneScreenIsWhatTheNextScreenOpensWith(): void
    {
        $session = [];

        $this->assertSame(35, RaceSettings::resolve($session, RaceSettings::CTR, '35', 100));
        $this->assertSame(35, RaceSettings::resolve($session, RaceSettings::CTR, null, 100));
        $this->assertSame(35, RaceSettings::remembered($session, RaceSettings::CTR));
    }

    public function testNothingChosenYetMeansZero(): void
    {
        $session = [];

        $this->assertSame(0, RaceSettings::resolve($session, RaceSettings::CTR, null, 100));
        $this->assertSame(0, RaceSettings::resolve($session, RaceSettings::CTR, '', 100));
        $this->assertNull(RaceSettings::remembered($session, RaceSettings::CTR));
    }

    public function testAnOutOfRangeRequestIsClampedBeforeItIsRemembered(): void
    {
        $session = [];

        $this->assertSame(100, RaceSettings::resolve($session, RaceSettings::CTR, '250', 100));
        $this->assertSame(100, RaceSettings::remembered($session, RaceSettings::CTR));
        $this->assertSame(0, RaceSettings::resolve($session, RaceSettings::CTR, '-4', 100));
        $this->assertSame(0, RaceSettings::remembered($session, RaceSettings::CTR));
    }

    public function testSettingsAreRememberedIndependently(): void
    {
        $session = [];

        RaceSettings::resolve($session, RaceSettings::CTR, '20', 100);
        RaceSettings::resolve($session, RaceSettings::TRAINING_LAPS, '40', 100);

        $this->assertSame(20, RaceSettings::resolve($session, RaceSettings::CTR, null, 100));
        $this->assertSame(40, RaceSettings::resolve($session, RaceSettings::TRAINING_LAPS, null, 100));
    }

    public function testATamperedSessionValueIsClampedOnTheWayOut(): void
    {
        $session = ['race_settings' => ['ctr' => 900]];

        $this->assertSame(100, RaceSettings::resolve($session, RaceSettings::CTR, null, 100));
    }
}
