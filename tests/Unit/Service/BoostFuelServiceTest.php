<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\BoostFuelService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BoostFuelService::class)]
final class BoostFuelServiceTest extends TestCase
{
    private BoostFuelService $svc;

    protected function setUp(): void
    {
        $this->svc = new BoostFuelService();
    }

    public function testExtraFuelRoundsUp(): void
    {
        // Serres: 3.186 km * 0.12 = 0.382 → ceil = 1 L for one boost lap.
        $this->assertSame(1, $this->svc->extraFuel(1, 3.186, 0.12));
        // 3 laps: 3 * 3.186 * 0.12 = 1.147 → ceil = 2 L.
        $this->assertSame(2, $this->svc->extraFuel(3, 3.186, 0.12));
    }

    public function testZeroOrNegativeInputsYieldZero(): void
    {
        $this->assertSame(0, $this->svc->extraFuel(0, 3.186, 0.12));
        $this->assertSame(0, $this->svc->extraFuel(2, 0.0, 0.12));
        $this->assertSame(0, $this->svc->extraFuel(2, 3.186, 0.0));
        $this->assertSame(0, $this->svc->extraFuel(-1, 3.186, 0.12));
    }
}
