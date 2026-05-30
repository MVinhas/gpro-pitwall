<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\CarWearService;
use App\Service\PartSwapAdvisorService;
use App\Service\PartUpgradeAdvisorService;
use App\Service\PhaMatchService;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PartSwapAdvisorService::class)]
final class PartSwapAdvisorServiceTest extends TestCase
{
    private PartSwapAdvisorService $svc;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $carWear = new CarWearService($pdo, [
            'driver_wear_factors' => ['concentration' => 1.0, 'talent' => 1.0, 'experience' => 1.0],
            'part_level_factors'  => [
                1 => 1.02, 2 => 1.018, 3 => 1.016, 4 => 1.014,
                5 => 1.012, 6 => 1.01, 7 => 1.008, 8 => 1.006, 9 => 1.004,
            ],
        ]);
        $pha = new PhaMatchService();
        $upgrade = new PartUpgradeAdvisorService($pha, [
            'Engine' => ['power' => 6, 'handling' => 0, 'acceleration' => 2],
        ]);
        $this->svc = new PartSwapAdvisorService($carWear, $pha, $upgrade);
    }

    /**
     * @param list<array{level: int, wear: int, cost: int, action: int}> $opts
     * @return array<string, mixed>
     */
    private function carData(array $opts): array
    {
        $options = [];
        foreach ($opts as $o) {
            $options[] = [
                'value'    => ['value' => $o['action'], 'cost' => $o['cost']],
                'newLvl'   => (string) $o['level'],
                'newWear'  => (string) $o['wear'],
                'disabled' => 'false',
                'text'     => '',
            ];
        }
        return ['engineOptions' => $options];
    }

    /**
     * @return list<array{part: string, level: int, start: int, est: float, end: float}>
     */
    private function flaggedEngine(int $level = 6, int $start = 95, float $end = 100.0): array
    {
        return [['part' => 'Engine', 'level' => $level, 'start' => $start, 'est' => $end - $start, 'end' => $end]];
    }

    /**
     * @return array<string, array{level: int, start: int, est: float, end: float, track_base: float}>
     */
    private function wearParts(float $trackBase = 5.0, int $level = 6, int $start = 95, float $end = 100.0): array
    {
        return ['Engine' => [
            'level' => $level, 'start' => $start, 'est' => $end - $start, 'end' => $end, 'track_base' => $trackBase,
        ]];
    }

    /**
     * @param list<array{slot: string, level: int}> $picks
     * @return array<string, int>
     */
    private function bySlot(array $picks): array
    {
        $out = [];
        foreach ($picks as $p) {
            $out[$p['slot']] = $p['level'];
        }
        return $out;
    }

    public function testProducesUpToFourNamedPicks(): void
    {
        // Track and car align (P=A=H all 10) so PHA score stays constant —
        // we're only testing the slot selection, not scoring.
        $track = ['power' => 10, 'handling' => 10, 'acceleration' => 10];
        $car   = ['power' => 90, 'handling' => 80, 'acceleration' => 85];

        $carData = $this->carData([
            // Free downgrades from L6 down.
            ['level' => 5, 'wear' => 20, 'cost' => 0,        'action' => -1],
            ['level' => 4, 'wear' => 30, 'cost' => 0,        'action' => -2],
            // Paid options.
            ['level' => 5, 'wear' => 0,  'cost' => 700_000,  'action' => 5],
            ['level' => 6, 'wear' => 0,  'cost' => 900_000,  'action' => 6],
            ['level' => 7, 'wear' => 0,  'cost' => 1_500_000, 'action' => 7],
        ]);

        $out = $this->svc->advise(
            $this->flaggedEngine(),
            $carData,
            $this->wearParts(),
            [],
            $track,
            $car,
            0,
            [],
            10_000_000,
        );

        $picks = $out['Engine']['picks'];
        $bySlot = $this->bySlot($picks);
        $this->assertSame(['free_downgrade', 'downgrade', 'sidegrade', 'upgrade'], array_keys($bySlot));
        // Free downgrade picks the HIGHEST-level surviving spare.
        $this->assertSame(5, $bySlot['free_downgrade']);
        // Paid downgrade picks the closest-below current (L5).
        $this->assertSame(5, $bySlot['downgrade']);
        // Sidegrade is current level.
        $this->assertSame(6, $bySlot['sidegrade']);
        // Upgrade is closest-above current (L7).
        $this->assertSame(7, $bySlot['upgrade']);
    }

    public function testFreeDowngradeOmittedWhenNoneSurvives(): void
    {
        $track = ['power' => 10, 'handling' => 10, 'acceleration' => 10];
        $car   = ['power' => 90, 'handling' => 80, 'acceleration' => 85];

        // Free downgrade L5 starts at 95% pre-worn, won't survive a big track.
        $carData = $this->carData([
            ['level' => 5, 'wear' => 95, 'cost' => 0,       'action' => -1],
            ['level' => 6, 'wear' => 0,  'cost' => 700_000, 'action' => 6],
        ]);

        $out = $this->svc->advise(
            $this->flaggedEngine(),
            $carData,
            $this->wearParts(trackBase: 20.0),
            [],
            $track,
            $car,
            0,
            [],
            10_000_000,
        );

        $bySlot = $this->bySlot($out['Engine']['picks']);
        $this->assertArrayNotHasKey('free_downgrade', $bySlot);
        $this->assertArrayHasKey('sidegrade', $bySlot);
    }

    public function testUpgradeOmittedWhenAllAboveCurrentAreOutOfBand(): void
    {
        $track = ['power' => 10, 'handling' => 10, 'acceleration' => 10];
        $car   = ['power' => 90, 'handling' => 80, 'acceleration' => 85];

        // Group is 5..6; band is [4, 7]. L8 fresh exists but is out of band.
        $carData = $this->carData([
            ['level' => 6, 'wear' => 0, 'cost' => 700_000, 'action' => 6],
            ['level' => 8, 'wear' => 0, 'cost' => 1_500_000, 'action' => 8],
        ]);

        $out = $this->svc->advise(
            $this->flaggedEngine(),
            $carData,
            $this->wearParts(),
            [],
            $track,
            $car,
            0,
            [5, 5, 6, 6],
            10_000_000,
        );

        $bySlot = $this->bySlot($out['Engine']['picks']);
        $this->assertArrayNotHasKey('upgrade', $bySlot);
        $this->assertArrayHasKey('sidegrade', $bySlot);
    }

    public function testCashFilterDropsUnaffordablePaidOptionsButKeepsFreeDowngrades(): void
    {
        $track = ['power' => 11, 'handling' => 9, 'acceleration' => 10];
        $car   = ['power' => 11, 'handling' => 9, 'acceleration' => 10];

        $carData = $this->carData([
            ['level' => 5, 'wear' => 30, 'cost' => 0,         'action' => -1],
            ['level' => 6, 'wear' => 0,  'cost' => 9_000_000, 'action' => 6],
        ]);

        $out = $this->svc->advise(
            $this->flaggedEngine(),
            $carData,
            $this->wearParts(),
            [],
            $track,
            $car,
            0,
            [],
            500_000,
        );

        $bySlot = $this->bySlot($out['Engine']['picks']);
        $this->assertArrayHasKey('free_downgrade', $bySlot);
        $this->assertArrayNotHasKey('sidegrade', $bySlot);
    }

    public function testWearFilterDropsOptionsThatWontSurvive(): void
    {
        $track = ['power' => 11, 'handling' => 9, 'acceleration' => 10];
        $car   = ['power' => 11, 'handling' => 9, 'acceleration' => 10];

        // L1 fresh starts at 70%; the level-1 wear factor compounds at risk 100
        // and pushes L1 past 100%. L5 stays comfortably under.
        $carData = $this->carData([
            ['level' => 1, 'wear' => 70, 'cost' => 100_000, 'action' => 1],
            ['level' => 5, 'wear' => 0,  'cost' => 700_000, 'action' => 5],
        ]);

        $out = $this->svc->advise(
            $this->flaggedEngine(),
            $carData,
            $this->wearParts(trackBase: 8.0),
            [],
            $track,
            $car,
            100,
            [],
            10_000_000,
        );

        $bySlot = $this->bySlot($out['Engine']['picks']);
        // L1 fresh was filtered by wear; L5 fresh survives and becomes the
        // paid downgrade. Sidegrade (L6 fresh) wasn't offered in this scenario.
        $this->assertSame(5, $bySlot['downgrade']);
        $this->assertArrayNotHasKey('sidegrade', $bySlot);
    }

    public function testOperatingBandSkippedWhenFewerThanThreePeers(): void
    {
        $track = ['power' => 11, 'handling' => 9, 'acceleration' => 10];
        $car   = ['power' => 11, 'handling' => 9, 'acceleration' => 10];

        $carData = $this->carData([
            ['level' => 1, 'wear' => 0, 'cost' => 100_000,   'action' => 1],
            ['level' => 9, 'wear' => 0, 'cost' => 1_500_000, 'action' => 9],
        ]);

        $out = $this->svc->advise(
            $this->flaggedEngine(),
            $carData,
            $this->wearParts(),
            [],
            $track,
            $car,
            0,
            [5, 6],
            10_000_000,
        );

        $bySlot = $this->bySlot($out['Engine']['picks']);
        // Both extremes available because the band filter was skipped.
        $this->assertSame(1, $bySlot['downgrade']);
        $this->assertSame(9, $bySlot['upgrade']);
    }

    public function testEmptyPicksWhenEverythingFiltersOut(): void
    {
        $track = ['power' => 11, 'handling' => 9, 'acceleration' => 10];
        $car   = ['power' => 11, 'handling' => 9, 'acceleration' => 10];

        $carData = $this->carData([
            ['level' => 6, 'wear' => 0, 'cost' => 9_000_000, 'action' => 6],
        ]);

        $out = $this->svc->advise(
            $this->flaggedEngine(),
            $carData,
            $this->wearParts(),
            [],
            $track,
            $car,
            0,
            [],
            100,
        );

        $this->assertSame([], $out['Engine']['picks']);
    }

    public function testActionKindLabelling(): void
    {
        $track = ['power' => 11, 'handling' => 9, 'acceleration' => 10];
        $car   = ['power' => 11, 'handling' => 9, 'acceleration' => 10];

        $carData = $this->carData([
            ['level' => 5, 'wear' => 20, 'cost' => 0,        'action' => -1],
            ['level' => 6, 'wear' => 0,  'cost' => 700_000,  'action' => 6],
            ['level' => 7, 'wear' => 0,  'cost' => 900_000,  'action' => 7],
        ]);

        $out = $this->svc->advise(
            $this->flaggedEngine(),
            $carData,
            $this->wearParts(),
            [],
            $track,
            $car,
            0,
            [],
            10_000_000,
        );

        $byKind = [];
        foreach ($out['Engine']['picks'] as $p) {
            $byKind[$p['slot']] = $p['action_kind'];
        }
        $this->assertSame('downgrade', $byKind['free_downgrade']);
        $this->assertSame('refresh',   $byKind['sidegrade']);
        $this->assertSame('upgrade',   $byKind['upgrade']);
    }
}
