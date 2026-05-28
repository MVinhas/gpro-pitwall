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
}
