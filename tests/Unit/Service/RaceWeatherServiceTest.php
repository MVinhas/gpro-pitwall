<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\RaceWeatherService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RaceWeatherService::class)]
final class RaceWeatherServiceTest extends TestCase
{
    private RaceWeatherService $svc;

    protected function setUp(): void
    {
        $this->svc = new RaceWeatherService();
    }

    public function testDetectsWetQualifying(): void
    {
        $r = $this->svc->assess(['q1Weather' => 'Light Rain', 'q2Weather' => 'Dry']);
        $this->assertTrue($r['q1_wet']);
        $this->assertFalse($r['q2_wet']);
    }

    public function testRaceRainAverageAndWetThreshold(): void
    {
        // Every quarter 60/80 → avg of eight values = 70 → wet.
        $w = [];
        foreach (['Q1', 'Q2', 'Q3', 'Q4'] as $q) {
            $w["race{$q}RainPLow"] = 60;
            $w["race{$q}RainPHigh"] = 80;
        }
        $r = $this->svc->assess($w);
        $this->assertSame(70.0, $r['race_rain_avg']);
        $this->assertTrue($r['race_start_wet']);
    }

    public function testDryRaceBelowThreshold(): void
    {
        $w = [];
        foreach (['Q1', 'Q2', 'Q3', 'Q4'] as $q) {
            $w["race{$q}RainPLow"] = 10;
            $w["race{$q}RainPHigh"] = 20;
        }
        $r = $this->svc->assess($w);
        $this->assertSame(15.0, $r['race_rain_avg']);
        $this->assertFalse($r['race_start_wet']);
    }

    public function testExactlyFiftyIsWet(): void
    {
        // Boundary: avg == 50 counts as wet (>=).
        $w = [];
        foreach (['Q1', 'Q2', 'Q3', 'Q4'] as $q) {
            $w["race{$q}RainPLow"] = 50;
            $w["race{$q}RainPHigh"] = 50;
        }
        $r = $this->svc->assess($w);
        $this->assertTrue($r['race_start_wet']);
    }

    public function testMissingDataIsDry(): void
    {
        $r = $this->svc->assess([]);
        $this->assertFalse($r['q1_wet']);
        $this->assertFalse($r['q2_wet']);
        $this->assertSame(0.0, $r['race_rain_avg']);
        $this->assertFalse($r['race_start_wet']);
    }
}
