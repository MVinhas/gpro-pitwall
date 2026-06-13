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
            'name'       => 'Imola',
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
            $this->track(['overtaking' => 'Very Hard', 'name' => 'Monaco']),
            false,
            10.0,
        );

        $this->assertGreaterThan($r['defend'], $r['overtake']);
    }

    public function testEasyTrackPushesDefendAboveOvertake(): void
    {
        $r = $this->service()->suggest(
            $this->driver(),
            $this->track(['overtaking' => 'Very Easy', 'name' => 'Monza']),
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
        $steady = $this->service()->suggest(
            $this->driver(['aggressiveness' => 100, 'experience' => 175]),
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

        $this->assertLessThan($steady['overtake'], $hothead['overtake']);
        $this->assertStringContainsString('aggressiveness 240', $hothead['phrase']);
    }

    public function testExperiencedAggressionBuysOvertakeButNotDefend(): void
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
        $this->assertSame($timid['defend'], $attacker['defend']);
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
        $this->assertStringContainsString('Grip here is very low', $slippery['phrase']);
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
        $this->assertStringContainsString('Tyre wear runs very high', $chewer['phrase']);
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
        $this->assertStringContainsString('stamina', $long['phrase']);
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
            $this->track(['overtaking' => 'Hard', 'name' => 'Barcelona']),
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

    public function testHardTrackTipRecommendsFewerStops(): void
    {
        $r = $this->service()->suggest($this->driver(), $this->track(['overtaking' => 'Hard']), false, 10.0);

        $this->assertStringContainsString('fewer pit stops', $r['tip']);
    }

    public function testEasyTrackTipAllowsExtraStop(): void
    {
        $r = $this->service()->suggest($this->driver(), $this->track(['overtaking' => 'Easy']), false, 10.0);

        $this->assertStringContainsString('extra pit stop', $r['tip']);
    }

    public function testTipIsSuppressedWhenRainIsLikely(): void
    {
        $wet = $this->service()->suggest($this->driver(), $this->track(['overtaking' => 'Hard']), true, 80.0);
        $threat = $this->service()->suggest($this->driver(), $this->track(['overtaking' => 'Hard']), false, 45.0);

        $this->assertNull($wet['tip']);
        $this->assertNull($threat['tip']);
    }

    public function testPhraseNamesTheTrackAndBothNumbers(): void
    {
        $r = $this->service()->suggest(
            $this->driver(),
            $this->track(['overtaking' => 'Hard', 'name' => 'Interlagos']),
            false,
            10.0,
        );

        $this->assertStringContainsString('Interlagos', $r['phrase']);
        $this->assertStringContainsString((string)$r['overtake'], $r['phrase']);
        $this->assertStringContainsString((string)$r['defend'], $r['phrase']);
        $this->assertStringNotContainsString('let the track defend for you', $r['phrase']);
    }

    public function testPhraseNeverInventsGameConcepts(): void
    {
        // "Composure" is an internal score, not a GPRO attribute — it must
        // never leak into user-facing prose.
        $weak = $this->service()->suggest(
            $this->driver(['concentration' => 30, 'experience' => 30, 'talent' => 30, 'motivation' => 30]),
            $this->track(),
            false,
            10.0,
        );
        $strong = $this->service()->suggest(
            $this->driver(['concentration' => 240, 'experience' => 240]),
            $this->track(),
            false,
            10.0,
        );

        $this->assertStringNotContainsString('composure', strtolower($weak['phrase']));
        $this->assertStringNotContainsString('composure', strtolower($strong['phrase']));
    }

    public function testVeryEasyTrackPointsAtClearTrackRisk(): void
    {
        $r = $this->service()->suggest($this->driver(), $this->track(['overtaking' => 'Very Easy']), false, 10.0);

        $this->assertStringContainsString('clear-track risk', $r['phrase']);
    }

    public function testDistanceTipPresentOnlyForShortAndLongRaces(): void
    {
        foreach ([238.6, 321.8] as $km) {
            $r = $this->service()->suggest($this->driver(), $this->track(['distance' => $km]), false, 10.0);

            $this->assertNotEmpty($r['distance_tip']);
            $this->assertStringContainsString('clear-track risk', $r['distance_tip']);
            $this->assertStringContainsString('boost lap', $r['distance_tip']);
        }
    }

    public function testDistanceTipStaysNarrativeWithoutNumbers(): void
    {
        // Managers don't need the kilometres or the field average — the note
        // must read as plain advice, never quoting a distance figure.
        foreach ([238.6, 321.8] as $km) {
            $r = $this->service()->suggest($this->driver(), $this->track(['distance' => $km]), false, 10.0);

            $this->assertDoesNotMatchRegularExpression('/\d/', $r['distance_tip']);
        }
    }

    public function testShortRaceTipFlagsLowerEnergyDrain(): void
    {
        $r = $this->service()->suggest($this->driver(), $this->track(['distance' => 250.0]), false, 10.0);

        $this->assertStringContainsString('short race', $r['distance_tip']);
        $this->assertStringContainsString('less driver energy', $r['distance_tip']);
        $this->assertStringContainsString('higher clear-track risk', $r['distance_tip']);
    }

    public function testNormalRaceShowsNoDistanceTip(): void
    {
        $r = $this->service()->suggest($this->driver(), $this->track(['distance' => 306.0]), false, 10.0);

        $this->assertSame('', $r['distance_tip']);
    }

    public function testLongRaceTipWarnsOnEnergyAndStamina(): void
    {
        $weak = $this->service()->suggest(
            $this->driver(['stamina' => 100]),
            $this->track(['distance' => 320.0]),
            false,
            10.0,
        );
        $strong = $this->service()->suggest(
            $this->driver(['stamina' => 220]),
            $this->track(['distance' => 320.0]),
            false,
            10.0,
        );

        $this->assertStringContainsString('long race', $weak['distance_tip']);
        $this->assertStringContainsString('drains more driver energy', $weak['distance_tip']);
        $this->assertStringContainsString('stamina', $weak['distance_tip']);
        $this->assertStringNotContainsString('stamina, he\'ll fade', $strong['distance_tip']);
    }

    public function testDistanceTipEmptyWhenDistanceUnknown(): void
    {
        $r = $this->service()->suggest($this->driver(), $this->track(['distance' => 0.0]), false, 10.0);

        $this->assertSame('', $r['distance_tip']);
    }

    public function testStartApproachScalesWithDriverAndTrack(): void
    {
        $elite = $this->service()->suggest(
            $this->driver([
                'concentration' => 216, 'experience' => 160,
                'talent' => 150, 'motivation' => 180, 'aggressiveness' => 120,
            ]),
            $this->track(['overtaking' => 'Hard']),
            false,
            10.0,
        );
        $rookieWet = $this->service()->suggest(
            $this->driver(['concentration' => 30, 'experience' => 30, 'talent' => 30, 'motivation' => 30]),
            $this->track(),
            true,
            80.0,
        );

        $this->assertSame('Force his way to the front', $elite['settings']['start_approach']);
        $this->assertSame('Avoid trouble', $rookieWet['settings']['start_approach']);
    }

    public function testProblemPitThresholdTracksPitLaneLength(): void
    {
        $shortLane = $this->service()->suggest(
            $this->driver(),
            $this->track(['pit_lane_loss' => 10.0]),
            false,
            10.0,
        );
        $longLane = $this->service()->suggest(
            $this->driver(),
            $this->track(['pit_lane_loss' => 45.0]),
            false,
            10.0,
        );
        $unknownLane = $this->service()->suggest($this->driver(), $this->track(), false, 10.0);

        $this->assertSame(6, $shortLane['settings']['problem_pit_laps']);
        $this->assertSame(12, $longLane['settings']['problem_pit_laps']);
        $this->assertSame(8, $unknownLane['settings']['problem_pit_laps']);
    }

    public function testBoostLapsTargetInLapsOnHardTracks(): void
    {
        $r = $this->service()->suggestBoostLaps(60, 2, 'Hard', false, 10.0);

        // Stints of 20 laps → stops after laps 20 and 40: boost the in-laps
        // (18-20, 38-40) and keep the last set for the final laps (58-60).
        $this->assertSame([18, 38, 58], $r['laps']);
        $this->assertStringContainsString('in-laps', $r['note']);
    }

    public function testBoostLapsFrontLoadOnEasyTracks(): void
    {
        $r = $this->service()->suggestBoostLaps(60, 2, 'Easy', false, 10.0);

        $this->assertSame([2, 18, 38], $r['laps']);
        $this->assertStringContainsString('early', $r['note']);
    }

    public function testBoostLapsSpreadWhenNoStops(): void
    {
        $r = $this->service()->suggestBoostLaps(60, 0, 'Normal', false, 10.0);

        $this->assertSame([2, 30, 58], $r['laps']);
    }

    public function testBoostLapsNeverOverlapAndStayInRange(): void
    {
        foreach ([[44, 1, 'Very Hard'], [70, 3, 'Very Easy'], [26, 2, 'Normal']] as [$laps, $stops, $rating]) {
            $r = $this->service()->suggestBoostLaps($laps, $stops, $rating, false, 10.0);

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
        $dry = $this->service()->suggestBoostLaps(60, 2, 'Hard', false, 10.0);
        $threat = $this->service()->suggestBoostLaps(60, 2, 'Hard', false, 45.0);

        $this->assertStringNotContainsString('dry-plan', $dry['note']);
        $this->assertStringContainsString('dry-plan', $threat['note']);
    }

    public function testBoostLapsBailOnVeryShortRaces(): void
    {
        $r = $this->service()->suggestBoostLaps(10, 1, 'Normal', false, 10.0);

        $this->assertSame([], $r['laps']);
    }
}
