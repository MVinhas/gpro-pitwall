<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\WearAdvisorService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WearAdvisorService::class)]
final class WearAdvisorServiceTest extends TestCase
{
    private WearAdvisorService $svc;

    protected function setUp(): void
    {
        $this->svc = new WearAdvisorService();
    }

    public function testClassifiesPartsAcrossAllBuckets(): void
    {
        $result = $this->svc->classify([
            'Engine'    => ['level' => 5, 'start' => 30, 'est' => 75.0, 'end' => 105.0],
            'Gearbox'   => ['level' => 4, 'start' => 50, 'est' => 42.0, 'end' => 92.0],
            'Brakes'    => ['level' => 3, 'start' => 40, 'est' => 38.0, 'end' => 78.0],
            'Chassis'   => ['level' => 6, 'start' => 10, 'est' => 12.0, 'end' => 22.0],
        ]);

        $this->assertCount(1, $result['swap']);
        $this->assertSame('Engine', $result['swap'][0]['part']);
        $this->assertCount(1, $result['risky']);
        $this->assertSame('Gearbox', $result['risky'][0]['part']);
        $this->assertCount(1, $result['watch']);
        $this->assertSame('Brakes', $result['watch'][0]['part']);
    }

    public function testBoundariesAreInclusive(): void
    {
        $result = $this->svc->classify([
            'A' => ['level' => 1, 'start' => 0, 'est' => 100.0, 'end' => 100.0],
            'B' => ['level' => 1, 'start' => 0, 'est' => 90.0,  'end' => 90.0],
            'C' => ['level' => 1, 'start' => 0, 'est' => 75.0,  'end' => 75.0],
            'D' => ['level' => 1, 'start' => 0, 'est' => 74.9,  'end' => 74.9],
        ]);

        $this->assertSame('A', $result['swap'][0]['part']);
        $this->assertSame('B', $result['risky'][0]['part']);
        $this->assertSame('C', $result['watch'][0]['part']);
        $this->assertSame([], array_filter(
            [$result['swap'], $result['risky'], $result['watch']],
            fn(array $b): bool => in_array('D', array_column($b, 'part'), true)
        ));
    }

    public function testHeadlineForSwap(): void
    {
        $one = $this->svc->classify([
            'Engine' => ['level' => 1, 'start' => 0, 'est' => 100.0, 'end' => 100.0],
        ]);
        $this->assertStringContainsString('1 part', $one['headline']);

        $two = $this->svc->classify([
            'Engine'  => ['level' => 1, 'start' => 0, 'est' => 100.0, 'end' => 100.0],
            'Gearbox' => ['level' => 1, 'start' => 0, 'est' => 100.0, 'end' => 100.0],
        ]);
        $this->assertStringContainsString('2 parts', $two['headline']);
    }

    public function testHeadlineForRiskyOnly(): void
    {
        $r = $this->svc->classify([
            'Gearbox' => ['level' => 1, 'start' => 0, 'est' => 92.0, 'end' => 92.0],
        ]);
        $this->assertStringContainsString('red', $r['headline']);
    }

    public function testHeadlineForWatchOnly(): void
    {
        $r = $this->svc->classify([
            'Brakes' => ['level' => 1, 'start' => 0, 'est' => 80.0, 'end' => 80.0],
        ]);
        $this->assertStringContainsString('watching', $r['headline']);
    }

    public function testHeadlineForAllGreen(): void
    {
        $r = $this->svc->classify([
            'Brakes' => ['level' => 1, 'start' => 0, 'est' => 40.0, 'end' => 40.0],
        ]);
        $this->assertStringContainsString('comfortably', $r['headline']);
    }
}
