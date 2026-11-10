<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\RiskAdvisorService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RiskAdvisorService::class)]
final class RiskAdvisorServiceTest extends TestCase
{
    /**
     * Driver attributes use GPRO's 0-250 scale — a solid mid-tier driver.
     *
     * @return array<string, int>
     */
    private function driver(array $overrides = []): array
    {
        return $overrides + [
            'concentration'  => 150,
            'talent'         => 150,
            'aggressiveness' => 125,
            'experience'     => 150,
            'motivation'     => 150,
            'stamina'        => 175,
        ];
    }

    /** @return array<string, mixed> */
    private function track(array $overrides = []): array
    {
        return $overrides + [
            'overtaking' => 'Normal',
            'grip'       => 'Normal',
            'tyre_wear'  => 'Medium',
            'distance'   => 250.0,
        ];
    }

    private function service(): RiskAdvisorService
    {
        return new RiskAdvisorService();
    }

    public function testHardTrackPushesOvertakeAboveDefend(): void
    {
        $r = $this->service()->suggest(
            $this->driver(),
            $this->track(['overtaking' => 'Very Hard']),
            false,
            10.0,
        );

        $this->assertGreaterThan($r['defend'], $r['overtake']);
    }

    public function testEasyTrackPushesDefendAboveOvertake(): void
    {
        $r = $this->service()->suggest(
            $this->driver(),
            $this->track(['overtaking' => 'Very Easy']),
            false,
            10.0,
        );

        $this->assertGreaterThan($r['overtake'], $r['defend']);
    }

    public function testWetRaceTrimsBothRisks(): void
    {
        $dry = $this->service()->suggest($this->driver(), $this->track(), false, 10.0);
        $wet = $this->service()->suggest($this->driver(), $this->track(), true, 80.0);

        $this->assertLessThan($dry['overtake'], $wet['overtake']);
        $this->assertLessThan($dry['defend'], $wet['defend']);
    }

    public function testTalentSoftensTheWetPenalty(): void
    {
        $clumsy = $this->service()->suggest(
            $this->driver(['talent' => 50]),
            $this->track(),
            true,
            80.0,
        );
        $gifted = $this->service()->suggest(
            $this->driver(['talent' => 240]),
            $this->track(),
            true,
            80.0,
        );

        $this->assertGreaterThan($clumsy['overtake'], $gifted['overtake']);
    }

    public function testAggressionBeyondExperienceTrimsSuggestions(): void
    {
        // Aggressiveness held fixed so this isolates the mistake trap: the
        // same appetite backed by experience earns more of it.
        $backed = $this->service()->suggest(
            $this->driver(['aggressiveness' => 240, 'experience' => 200]),
            $this->track(['overtaking' => 'Hard']),
            false,
            10.0,
        );
        $hothead = $this->service()->suggest(
            $this->driver(['aggressiveness' => 240, 'experience' => 50]),
            $this->track(['overtaking' => 'Hard']),
            false,
            10.0,
        );

        $this->assertLessThan($backed['overtake'], $hothead['overtake']);
    }

    public function testExperiencedAggressionLiftsBothDialsAndFavoursOvertake(): void
    {
        $timid = $this->service()->suggest(
            $this->driver(['aggressiveness' => 25, 'experience' => 200]),
            $this->track(['overtaking' => 'Hard']),
            false,
            10.0,
        );
        $attacker = $this->service()->suggest(
            $this->driver(['aggressiveness' => 200, 'experience' => 200]),
            $this->track(['overtaking' => 'Hard']),
            false,
            10.0,
        );

        $this->assertGreaterThan($timid['overtake'], $attacker['overtake']);
        $this->assertGreaterThan($timid['defend'], $attacker['defend']);

        // Experience-backed aggression buys attacking pace on top of the
        // appetite both dials share, so overtake pulls further clear of
        // defend. Compared as a gap, not a ratio — the 5-point snapping
        // distorts ratios badly down at the timid driver's magnitudes.
        $this->assertGreaterThan(
            $timid['overtake'] - $timid['defend'],
            $attacker['overtake'] - $attacker['defend'],
        );
    }

    public function testZeroAggressionNeverAdvisesAboveFifteen(): void
    {
        // The hard rail: whatever the track and however good the driver, a
        // pilot who will not fight is never told to dial risk up.
        foreach (['Very Easy', 'Easy', 'Normal', 'Hard', 'Very Hard'] as $rating) {
            $r = $this->service()->suggest(
                $this->driver([
                    'aggressiveness' => 0, 'concentration' => 250, 'talent' => 250,
                    'experience' => 250, 'motivation' => 250, 'stamina' => 250,
                ]),
                $this->track(['overtaking' => $rating, 'grip' => 'Very High', 'distance' => 250.0]),
                false,
                0.0,
            );

            $this->assertLessThanOrEqual(15, $r['overtake'], $rating);
            $this->assertLessThanOrEqual(15, $r['defend'], $rating);
        }
    }

    public function testAggressivenessAnchorsTheSuggestedBand(): void
    {
        // Micael's calibration, on the neutral reference situation: a passive
        // driver sits around 10, a timid one around 20, and only a maxed-out
        // aggressor is told to run in the sixties.
        $at = function (int $agg): array {
            return $this->service()->suggest(
                $this->driver(['aggressiveness' => $agg, 'experience' => 250]),
                $this->track(['overtaking' => 'Hard']),
                false,
                10.0,
            );
        };

        $this->assertLessThanOrEqual(15, $at(0)['overtake']);
        $this->assertGreaterThanOrEqual(15, $at(50)['overtake']);
        $this->assertLessThanOrEqual(30, $at(50)['overtake']);
        $this->assertGreaterThanOrEqual(55, $at(250)['overtake']);
        $this->assertLessThanOrEqual(65, $at(250)['overtake']);
    }

    public function testBothDialsRiseMonotonicallyWithAggressiveness(): void
    {
        $prev = null;
        foreach ([0, 50, 100, 150, 200, 250] as $agg) {
            $r = $this->service()->suggest(
                $this->driver(['aggressiveness' => $agg, 'experience' => 250]),
                $this->track(),
                false,
                10.0,
            );

            if ($prev !== null) {
                $this->assertGreaterThanOrEqual($prev['overtake'], $r['overtake']);
                $this->assertGreaterThanOrEqual($prev['defend'], $r['defend']);
            }
            $prev = $r;
        }
    }

    public function testLowGripTrimsBothRisks(): void
    {
        $normal = $this->service()->suggest($this->driver(), $this->track(), false, 10.0);
        $slippery = $this->service()->suggest(
            $this->driver(),
            $this->track(['grip' => 'Very Low']),
            false,
            10.0,
        );

        $this->assertLessThan($normal['overtake'], $slippery['overtake']);
        $this->assertLessThan($normal['defend'], $slippery['defend']);
    }

    public function testHighGripDoesNotInflateRisks(): void
    {
        $normal = $this->service()->suggest($this->driver(), $this->track(), false, 10.0);
        $grippy = $this->service()->suggest(
            $this->driver(),
            $this->track(['grip' => 'Very High']),
            false,
            10.0,
        );

        $this->assertSame($normal['overtake'], $grippy['overtake']);
        $this->assertSame($normal['defend'], $grippy['defend']);
    }

    public function testVeryHighTyreWearTrimsRisks(): void
    {
        $medium = $this->service()->suggest($this->driver(), $this->track(), false, 10.0);
        $chewer = $this->service()->suggest(
            $this->driver(),
            $this->track(['tyre_wear' => 'Very High']),
            false,
            10.0,
        );

        $this->assertLessThanOrEqual($medium['overtake'], $chewer['overtake']);
    }

    public function testLowStaminaCostsRiskOnLongRaces(): void
    {
        $short = $this->service()->suggest(
            $this->driver(['stamina' => 50]),
            $this->track(['distance' => 200.0]),
            false,
            10.0,
        );
        $long = $this->service()->suggest(
            $this->driver(['stamina' => 50]),
            $this->track(['distance' => 330.0]),
            false,
            10.0,
        );

        $this->assertLessThanOrEqual($short['overtake'], $long['overtake']);
    }

    public function testEliteDriverDoesNotPinEverythingAtTheCap(): void
    {
        // Concentration 216 / experience 160 is strong but not maxed — the
        // 0-250 normalisation must keep suggestions off the 70 ceiling.
        $r = $this->service()->suggest(
            $this->driver([
                'concentration' => 216, 'experience' => 160,
                'talent' => 150, 'motivation' => 180, 'aggressiveness' => 120,
            ]),
            $this->track(['overtaking' => 'Hard']),
            false,
            10.0,
        );

        $this->assertLessThan(70, $r['overtake']);
        $this->assertLessThan(45, $r['defend']);
    }

    public function testSuggestionsStayInsideBandAndSnapToFives(): void
    {
        $extremes = [
            $this->service()->suggest($this->driver([
                'concentration' => 0, 'talent' => 0, 'experience' => 0,
                'motivation' => 0, 'aggressiveness' => 250, 'stamina' => 0,
            ]), $this->track(['overtaking' => 'Very Easy', 'grip' => 'Very Low', 'distance' => 350.0]), true, 90.0),
            $this->service()->suggest($this->driver([
                'concentration' => 250, 'talent' => 250, 'experience' => 250,
                'motivation' => 250, 'aggressiveness' => 0, 'stamina' => 250,
            ]), $this->track(['overtaking' => 'Very Hard', 'distance' => 200.0]), false, 0.0),
        ];

        foreach ($extremes as $r) {
            foreach (['overtake', 'defend'] as $key) {
                $this->assertGreaterThanOrEqual(5, $r[$key]);
                $this->assertLessThanOrEqual(70, $r[$key]);
                $this->assertSame(0, $r[$key] % 5);
            }
        }
    }

    public function testUnknownOvertakingRatingFallsBackToNormal(): void
    {
        $unknown = $this->service()->suggest($this->driver(), $this->track(['overtaking' => null]), false, 10.0);
        $normal  = $this->service()->suggest($this->driver(), $this->track(), false, 10.0);

        $this->assertSame($normal['overtake'], $unknown['overtake']);
        $this->assertSame($normal['defend'], $unknown['defend']);
    }

    public function testBoostLapsTargetTheInLaps(): void
    {
        $r = $this->service()->suggestBoostLaps(60, 2, false, 10.0);

        // Stints of 20 laps → stops after laps 20 and 40: boost the in-laps
        // (18-20, 38-40) and keep the last set for the final laps (58-60).
        $this->assertSame([18, 38, 58], $r['laps']);
        $this->assertStringContainsString('in-laps', $r['note']);
        $this->assertStringContainsString('clear air', $r['note']);
    }

    public function testBoostLapsFollowTheActualPitLaps(): void
    {
        // A late first stop at lap 24 (not the even-split lap 20): the in-lap
        // set has to move with it, or it burns boost on a lap with no stop.
        $r = $this->service()->suggestBoostLaps(60, 2, false, 10.0, 0, [24, 42], 'late');

        $this->assertSame([22, 40, 58], $r['laps']);
        $this->assertStringContainsString('overcut', $r['note']);
    }

    public function testEarlyFirstStopBoostsTheLightInLapsBeforeIt(): void
    {
        // GPRO refuels, so the laps before an early stop run the lightest car
        // of the race; the out-lap after it runs the heaviest. The set ends at
        // the stop (10-12), never after it.
        $r = $this->service()->suggestBoostLaps(60, 2, false, 10.0, 0, [12, 36], 'early');

        $this->assertSame([10, 34, 58], $r['laps']);
        $this->assertStringContainsString('light', $r['note']);
    }

    public function testNoBoostSetStartsAfterAStopItIsMeantFor(): void
    {
        foreach (['even', 'early', 'late'] as $mode) {
            $r = $this->service()->suggestBoostLaps(60, 2, false, 10.0, 3, [12, 36], $mode);

            $this->assertContains(10, $r['laps'], $mode);
            $this->assertContains(34, $r['laps'], $mode);
        }
    }

    public function testEvenFirstStopKeepsTheInLapPlan(): void
    {
        $implicit = $this->service()->suggestBoostLaps(60, 2, false, 10.0);
        $explicit = $this->service()->suggestBoostLaps(60, 2, false, 10.0, 0, [20, 40], 'even');

        $this->assertSame($implicit, $explicit);
    }

    public function testFirstStopSetOutranksTheFinalLapsWhenOnlyOneIsPlanned(): void
    {
        $r = $this->service()->suggestBoostLaps(60, 2, false, 10.0, 1, [12, 36], 'early');

        $this->assertSame([10], $r['planned']);
    }

    public function testBoostLapsSpreadWhenNoStops(): void
    {
        $r = $this->service()->suggestBoostLaps(60, 0, false, 10.0);

        $this->assertSame([15, 30, 58], $r['laps']);
    }

    public function testBoostLapsStayOutOfTheOpeningPack(): void
    {
        // Boost stuck in the lap-1 crowd buys nothing: no plan starts a set
        // before the field has had laps to spread out.
        foreach ([[60, 0], [60, 1], [60, 2], [44, 1], [70, 3], [26, 2]] as [$laps, $stops]) {
            $r = $this->service()->suggestBoostLaps($laps, $stops, false, 10.0);

            $this->assertGreaterThanOrEqual(5, min($r['laps']), "$laps laps, $stops stops");
        }
    }

    public function testBoostLapsNeverOverlapAndStayInRange(): void
    {
        foreach ([[44, 1], [70, 3], [26, 2]] as [$laps, $stops]) {
            $r = $this->service()->suggestBoostLaps($laps, $stops, false, 10.0);

            $this->assertCount(3, $r['laps']);
            $sorted = $r['laps'];
            sort($sorted);
            $this->assertSame($sorted, $r['laps']);
            foreach ($r['laps'] as $i => $lap) {
                $this->assertGreaterThanOrEqual(1, $lap);
                $this->assertLessThanOrEqual($laps - 2, $lap);
                if ($i > 0) {
                    $this->assertGreaterThanOrEqual(3, $lap - $r['laps'][$i - 1]);
                }
            }
        }
    }

    public function testBoostLapsWarnWhenRainIsLikely(): void
    {
        $dry = $this->service()->suggestBoostLaps(60, 2, false, 10.0);
        $threat = $this->service()->suggestBoostLaps(60, 2, false, 45.0);

        $this->assertStringNotContainsString('dry-plan', $dry['note']);
        $this->assertStringContainsString('dry-plan', $threat['note']);
    }

    public function testBoostLapsBailOnVeryShortRaces(): void
    {
        $r = $this->service()->suggestBoostLaps(10, 1, false, 10.0);

        $this->assertSame([], $r['laps']);
        $this->assertSame([], $r['planned']);
    }

    public function testPlannedSetsKeepThePriorityPicksNotTheEarliestLaps(): void
    {
        // No stops: the final laps outrank the mid-race and quarter-distance
        // fillers, so a single planned set is lap 58 — not lap 15, which merely
        // sorts first.
        $one = $this->service()->suggestBoostLaps(60, 0, false, 10.0, 1);
        $two = $this->service()->suggestBoostLaps(60, 0, false, 10.0, 2);

        $this->assertSame([15, 30, 58], $one['laps']);
        $this->assertSame([58], $one['planned']);
        $this->assertSame([30, 58], $two['planned']);
    }

    public function testEveryPlannedSetIsASuggestedSet(): void
    {
        $r = $this->service()->suggestBoostLaps(60, 2, false, 10.0, 3);

        $this->assertSame($r['laps'], $r['planned']);
    }

    public function testNoBoostPlannedKeepsTheFuelReminder(): void
    {
        $off = $this->service()->suggestBoostLaps(60, 2, false, 10.0, 0);
        $on = $this->service()->suggestBoostLaps(60, 2, false, 10.0, 2);

        // With no sets in the fuel plan the suggestions are advice only, so the
        // note says how to fuel them; once sets are planned the fuel covers them.
        $this->assertSame([], $off['planned']);
        $this->assertStringContainsString('Boost stints', $off['note']);
        $this->assertStringNotContainsString('Boost stints', $on['note']);
    }
}
