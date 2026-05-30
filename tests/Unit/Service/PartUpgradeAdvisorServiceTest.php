<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\PartUpgradeAdvisorService;
use App\Service\PhaMatchService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PartUpgradeAdvisorService::class)]
final class PartUpgradeAdvisorServiceTest extends TestCase
{
    /** @var array<string, array{power: int, handling: int, acceleration: int}> */
    private const array CONTRIBUTION = [
        'Engine'  => ['power' => 6, 'handling' => 0, 'acceleration' => 2],
        'Brakes'  => ['power' => 0, 'handling' => 2, 'acceleration' => 0],
        'Gearbox' => ['power' => 3, 'handling' => 1, 'acceleration' => 5],
    ];

    private PartUpgradeAdvisorService $svc;

    protected function setUp(): void
    {
        $this->svc = new PartUpgradeAdvisorService(new PhaMatchService(), self::CONTRIBUTION);
    }

    public function testSuggestsUpgradeWhenItFlipsTheMatch(): void
    {
        // Track wants Power first, Acceleration second, Handling last.
        $track = ['power' => 11, 'handling' => 9, 'acceleration' => 10];
        // Car currently: A > P > H — top attribute is Acceleration, doesn't match Power.
        $car   = ['power' => 84, 'handling' => 82, 'acceleration' => 86];

        // Upgrading Engine by +1 adds (6P, 0H, 2A) → P=90, A=88, H=82.
        // New ranking: P > A > H, matching track's P > A > H.
        $out = $this->svc->suggest($track, $car, [['part' => 'Engine', 'level' => 5]]);

        $this->assertCount(1, $out);
        $this->assertSame('Engine', $out[0]['part']);
        $this->assertSame(6, $out[0]['suggested_level']);
        $this->assertSame(1, $out[0]['delta']);
    }

    public function testSkipsPartWhenNoSwapHelps(): void
    {
        // Track and car already aligned — no suggestion needed.
        $track = ['power' => 11, 'handling' => 9, 'acceleration' => 10];
        $car   = ['power' => 90, 'handling' => 82, 'acceleration' => 88];

        $out = $this->svc->suggest($track, $car, [['part' => 'Brakes', 'level' => 4]]);
        $this->assertSame([], $out);
    }

    public function testSkipsUnknownPart(): void
    {
        $track = ['power' => 11, 'handling' => 9, 'acceleration' => 10];
        $car   = ['power' => 84, 'handling' => 82, 'acceleration' => 86];

        $out = $this->svc->suggest($track, $car, [['part' => 'Unobtanium', 'level' => 5]]);
        $this->assertSame([], $out);
    }

    public function testRespectsLevelBoundaries(): void
    {
        $track = ['power' => 11, 'handling' => 9, 'acceleration' => 10];
        $car   = ['power' => 84, 'handling' => 82, 'acceleration' => 86];

        // Lvl 9 can't go to 10 — only -1 is available, which doesn't help here.
        $atMax = $this->svc->suggest($track, $car, [['part' => 'Engine', 'level' => 9]]);
        $this->assertSame([], $atMax);
    }
}
