<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\PhaMatchService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhaMatchService::class)]
final class PhaMatchServiceTest extends TestCase
{
    private PhaMatchService $svc;

    protected function setUp(): void
    {
        $this->svc = new PhaMatchService();
    }

    /** Track demands P>A>H; car strongest P>A>H — orderings coincide. */
    private function alignedTrack(): array
    {
        return ['power' => 18, 'handling' => 8, 'acceleration' => 16];
    }

    private function alignedCar(): array
    {
        return ['power' => 15, 'handling' => 9, 'acceleration' => 12];
    }

    public function testAllInWhenSimilarAndFavourite(): void
    {
        $r = $this->svc->evaluate($this->alignedTrack(), $this->alignedCar(), true);
        $this->assertSame(PhaMatchService::VERDICT_ALL_IN, $r['verdict']);
        $this->assertTrue($r['pha_similar']);
        $this->assertTrue($r['favourite']);
    }

    public function testPushWhenSimilarButNotFavourite(): void
    {
        $r = $this->svc->evaluate($this->alignedTrack(), $this->alignedCar(), false);
        $this->assertSame(PhaMatchService::VERDICT_PUSH, $r['verdict']);
        $this->assertTrue($r['pha_similar']);
    }

    public function testPushWhenFavouriteButNotSimilar(): void
    {
        // Car ordering H>A>P — opposite of track demand → not similar, but favourite.
        $car = ['power' => 8, 'handling' => 18, 'acceleration' => 12];
        $r = $this->svc->evaluate($this->alignedTrack(), $car, true);
        $this->assertSame(PhaMatchService::VERDICT_PUSH, $r['verdict']);
        $this->assertFalse($r['pha_similar']);
    }

    public function testNeutralWhenNeither(): void
    {
        $car = ['power' => 8, 'handling' => 18, 'acceleration' => 12];
        $r = $this->svc->evaluate($this->alignedTrack(), $car, false);
        $this->assertSame(PhaMatchService::VERDICT_NEUTRAL, $r['verdict']);
        $this->assertFalse($r['pha_similar']);
    }

    public function testTopMatchButSecondMismatchIsNotSimilar(): void
    {
        // Both have Power #1, but track #2 is Accel while car #2 is Handling.
        $track = ['power' => 18, 'handling' => 8, 'acceleration' => 16];
        $car   = ['power' => 18, 'handling' => 16, 'acceleration' => 8];
        $r = $this->svc->evaluate($track, $car, false);
        $this->assertFalse($r['pha_similar'], 'top-1 match alone must not count as similar');
        $this->assertSame(PhaMatchService::VERDICT_NEUTRAL, $r['verdict']);
    }

    public function testBalancedCarIsNeverSimilar(): void
    {
        // A fresh 13/13/13 car has no exploitable ordering.
        $car = ['power' => 13, 'handling' => 13, 'acceleration' => 13];
        $r = $this->svc->evaluate($this->alignedTrack(), $car, false);
        $this->assertFalse($r['pha_similar']);
        $this->assertSame(PhaMatchService::VERDICT_NEUTRAL, $r['verdict']);

        // ...but a favourite track still earns a PUSH.
        $r2 = $this->svc->evaluate($this->alignedTrack(), $car, true);
        $this->assertSame(PhaMatchService::VERDICT_PUSH, $r2['verdict']);
    }

    public function testTiedTrackDemandIsNotSimilar(): void
    {
        // Track has handling == acceleration → ambiguous second demand.
        $track = ['power' => 18, 'handling' => 12, 'acceleration' => 12];
        $car   = ['power' => 15, 'handling' => 9, 'acceleration' => 12];
        $r = $this->svc->evaluate($track, $car, false);
        $this->assertFalse($r['pha_similar']);
    }

    public function testStringInputsFromApiAreHandled(): void
    {
        // The GPRO API returns these as strings.
        $track = ['power' => '18', 'handling' => '8', 'acceleration' => '16'];
        $car   = ['power' => '15', 'handling' => '9', 'acceleration' => '12'];
        $r = $this->svc->evaluate($track, $car, false);
        $this->assertTrue($r['pha_similar']);
        $this->assertSame(PhaMatchService::VERDICT_PUSH, $r['verdict']);
    }

    public function testAttributeAlignmentFlagsForUi(): void
    {
        $r = $this->svc->evaluate($this->alignedTrack(), $this->alignedCar(), false);
        // Every attribute occupies the same rank on both sides when similar.
        $this->assertTrue($r['attributes']['power']['aligned']);
        $this->assertTrue($r['attributes']['handling']['aligned']);
        $this->assertTrue($r['attributes']['acceleration']['aligned']);
        $this->assertSame(1, $r['attributes']['power']['track_rank']);
        $this->assertSame(1, $r['attributes']['power']['car_rank']);
    }

    public function testMatchLevelPerfectWhenAllRanksAlign(): void
    {
        // Track P > A > H, car P > A > H — every rank coincides.
        $this->assertSame(
            PhaMatchService::MATCH_PERFECT,
            $this->svc->matchLevel($this->alignedTrack(), $this->alignedCar()),
        );
    }

    public function testMatchLevelPerfectWithTiedRanks(): void
    {
        // Track P top, H == A tied second; car mirrors the same tied shape.
        $track = ['power' => 18, 'handling' => 12, 'acceleration' => 12];
        $car   = ['power' => 15, 'handling' => 9, 'acceleration' => 9];
        $this->assertSame(PhaMatchService::MATCH_PERFECT, $this->svc->matchLevel($track, $car));
    }

    public function testMatchLevelTopWhenOnlyTopAttributeCoincides(): void
    {
        // Both have Power #1, but #2/#3 are flipped.
        $track = ['power' => 18, 'handling' => 8, 'acceleration' => 16];
        $car   = ['power' => 18, 'handling' => 16, 'acceleration' => 8];
        $this->assertSame(PhaMatchService::MATCH_TOP, $this->svc->matchLevel($track, $car));
    }

    public function testMatchLevelNoneWhenTopsDiffer(): void
    {
        $track = ['power' => 18, 'handling' => 8, 'acceleration' => 16];
        $car   = ['power' => 8, 'handling' => 18, 'acceleration' => 12];
        $this->assertSame(PhaMatchService::MATCH_NONE, $this->svc->matchLevel($track, $car));
    }

    public function testMatchLevelNoneWhenTrackTopIsTied(): void
    {
        // Track P == H tied top — no single #1, so a single-top car can't be a
        // top match (only a full perfect mirror would count).
        $track = ['power' => 18, 'handling' => 18, 'acceleration' => 8];
        $car   = ['power' => 18, 'handling' => 10, 'acceleration' => 8];
        $this->assertSame(PhaMatchService::MATCH_NONE, $this->svc->matchLevel($track, $car));
    }

    public function testMatchLevelNoneForBalancedCar(): void
    {
        $car = ['power' => 13, 'handling' => 13, 'acceleration' => 13];
        $this->assertSame(PhaMatchService::MATCH_NONE, $this->svc->matchLevel($this->alignedTrack(), $car));
    }

    public function testEvaluateExposesMatchLevel(): void
    {
        $r = $this->svc->evaluate($this->alignedTrack(), $this->alignedCar(), false);
        $this->assertSame(PhaMatchService::MATCH_PERFECT, $r['match']);
    }

    public function testTierPerfectWhenRanksMatchExactly(): void
    {
        // Track P > H > A, car P > H > A.
        $track = ['power' => 15, 'handling' => 10, 'acceleration' => 5];
        $car   = ['power' => 90, 'handling' => 80, 'acceleration' => 70];
        $this->assertSame(PhaMatchService::TIER_PERFECT, $this->svc->tierFor($track, $car));
    }

    public function testTierTopMatchWhenTopAlignedButLowerOrderDiffers(): void
    {
        // Track P > H > A, car P > A > H — top matches, middle/bottom flipped.
        $track = ['power' => 15, 'handling' => 10, 'acceleration' => 5];
        $car   = ['power' => 90, 'handling' => 70, 'acceleration' => 80];
        $this->assertSame(PhaMatchService::TIER_TOP_MATCH, $this->svc->tierFor($track, $car));
    }

    public function testTierTopSwapWhenCarTopMatchesTrackSecond(): void
    {
        // Track P > H > A, car H > P > A.
        $track = ['power' => 15, 'handling' => 10, 'acceleration' => 5];
        $car   = ['power' => 80, 'handling' => 90, 'acceleration' => 70];
        $this->assertSame(PhaMatchService::TIER_TOP_SWAP, $this->svc->tierFor($track, $car));
    }

    public function testTierTrashWhenCarTopMatchesTrackBottom(): void
    {
        // Track P > H > A, car A > P > H.
        $track = ['power' => 15, 'handling' => 10, 'acceleration' => 5];
        $car   = ['power' => 80, 'handling' => 70, 'acceleration' => 90];
        $this->assertSame(PhaMatchService::TIER_TRASH, $this->svc->tierFor($track, $car));
    }

    public function testTierPerfectWhenTrackTopIsTiedAndCarMirrors(): void
    {
        // Track 10P 10H 9A — P and H tied for top.
        // Car must also tie P and H above A to be Perfect.
        $track = ['power' => 10, 'handling' => 10, 'acceleration' => 9];
        $car   = ['power' => 90, 'handling' => 90, 'acceleration' => 70];
        $this->assertSame(PhaMatchService::TIER_PERFECT, $this->svc->tierFor($track, $car));
    }

    public function testTierTopMatchWhenTrackTopIsTiedAndCarTopOnly(): void
    {
        // Track 10P 10H 9A. Car P > H > A — P is in the top set, H isn't.
        $track = ['power' => 10, 'handling' => 10, 'acceleration' => 9];
        $car   = ['power' => 90, 'handling' => 80, 'acceleration' => 70];
        $this->assertSame(PhaMatchService::TIER_TOP_MATCH, $this->svc->tierFor($track, $car));
    }

    public function testTierTrashWhenTrackTopIsTiedAndCarPicksBottom(): void
    {
        // Track 10P 10H 9A; car A > P > H — top is A, which is the track's
        // bottom. No second tier to swap into (P/H tied at the top), so Trash.
        $track = ['power' => 10, 'handling' => 10, 'acceleration' => 9];
        $car   = ['power' => 80, 'handling' => 70, 'acceleration' => 90];
        $this->assertSame(PhaMatchService::TIER_TRASH, $this->svc->tierFor($track, $car));
    }

    public function testTierPerfectOnFullyTiedTrackWhenCarFullyTied(): void
    {
        $track = ['power' => 10, 'handling' => 10, 'acceleration' => 10];
        $car   = ['power' => 90, 'handling' => 90, 'acceleration' => 90];
        $this->assertSame(PhaMatchService::TIER_PERFECT, $this->svc->tierFor($track, $car));
    }

    public function testTierTopMatchOnFullyTiedTrackWhenCarHasAnyOrder(): void
    {
        // Track has no preference, so any car ranking that doesn't perfectly
        // mirror (i.e. isn't fully tied) is at worst Top match.
        $track = ['power' => 10, 'handling' => 10, 'acceleration' => 10];
        $car   = ['power' => 90, 'handling' => 80, 'acceleration' => 70];
        $this->assertSame(PhaMatchService::TIER_TOP_MATCH, $this->svc->tierFor($track, $car));
    }
}
