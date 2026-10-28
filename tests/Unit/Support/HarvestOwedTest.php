<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Service\AuthService;
use App\Service\HarvestService;
use App\Support\HarvestOwed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HarvestOwed::class)]
final class HarvestOwedTest extends TestCase
{
    private const array USER = ['id' => 7, 'api_token' => 'tok'];

    /** @var array<string, mixed> */
    private array $priorSession = [];

    protected function setUp(): void
    {
        $this->priorSession = $_SESSION ?? [];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->priorSession;
    }

    private function harvest(bool $mayFetch, bool $due): HarvestService
    {
        $harvest = $this->createMock(HarvestService::class);
        $harvest->expects($this->once())->method('isDueSafely')->with(self::USER, $mayFetch)->willReturn($due);

        return $harvest;
    }

    public function testAnOwedHarvestWithNothingDueIsSettled(): void
    {
        $_SESSION = [AuthService::HARVEST_OWED => true];

        $this->assertFalse(HarvestOwed::due($this->harvest(true, false), self::USER));
        $this->assertArrayNotHasKey(AuthService::HARVEST_OWED, $_SESSION);
    }

    public function testAnOwedHarvestWithWorkDueStaysOwed(): void
    {
        $_SESSION = [AuthService::HARVEST_OWED => true];

        $this->assertTrue(HarvestOwed::due($this->harvest(true, true), self::USER));
        $this->assertTrue($_SESSION[AuthService::HARVEST_OWED]);
    }

    public function testWithoutAnOwedHarvestOnlyProjectionsCountAndNothingIsOwed(): void
    {
        $_SESSION = [];

        $this->assertTrue(HarvestOwed::due($this->harvest(false, true), self::USER));
        $this->assertArrayNotHasKey(AuthService::HARVEST_OWED, $_SESSION);
    }
}
