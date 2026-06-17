<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\GproApiThrottle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GproApiThrottle::class)]
final class GproApiThrottleTest extends TestCase
{
    private string $stateFile;

    protected function setUp(): void
    {
        $this->stateFile = sys_get_temp_dir() . '/throttle_' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->stateFile);
    }

    public function testBurstCallsIncurNoWait(): void
    {
        $t = new GproApiThrottle($this->stateFile, ratePerSecond: 4.0, burst: 8.0, maxBlockMs: 2000);

        // The full burst budget is available at the same instant: no waits.
        for ($i = 0; $i < 8; $i++) {
            $this->assertSame(0.0, $t->reserve(1000.0), "call {$i} should not wait");
        }
    }

    public function testCallsBeyondBurstAreSpacedByRefillRate(): void
    {
        $t = new GproApiThrottle($this->stateFile, ratePerSecond: 4.0, burst: 8.0, maxBlockMs: 2000);

        // Drain the bucket at one instant.
        for ($i = 0; $i < 8; $i++) {
            $t->reserve(1000.0);
        }

        // The 9th call (still same instant) is one token short → waits 1/rate.
        $this->assertEqualsWithDelta(0.25, $t->reserve(1000.0), 1e-9);
        // The 10th is two tokens short → 2/rate.
        $this->assertEqualsWithDelta(0.50, $t->reserve(1000.0), 1e-9);
    }

    public function testElapsedTimeRefillsTheBucket(): void
    {
        $t = new GproApiThrottle($this->stateFile, ratePerSecond: 4.0, burst: 8.0, maxBlockMs: 2000);

        for ($i = 0; $i < 8; $i++) {
            $t->reserve(1000.0);
        }

        // Two seconds later, 8 tokens would have refilled but the bucket is
        // capped at burst (8), so the next call is free again.
        $this->assertSame(0.0, $t->reserve(1002.0));
    }

    public function testWaitIsCappedAtMaxBlock(): void
    {
        $t = new GproApiThrottle($this->stateFile, ratePerSecond: 1.0, burst: 1.0, maxBlockMs: 500);

        $t->reserve(1000.0); // consume the only token

        // Deficit would imply a 1s wait, but maxBlock caps it at 0.5s.
        $this->assertSame(0.5, $t->reserve(1000.0));
    }

    public function testZeroRateDisablesThrottling(): void
    {
        $t = new GproApiThrottle($this->stateFile, ratePerSecond: 0.0, burst: 8.0, maxBlockMs: 2000);

        // acquire() short-circuits before touching the filesystem.
        $t->acquire();
        $this->assertFileDoesNotExist($this->stateFile);
    }

    public function testStatePersistsAcrossInstances(): void
    {
        // Separate instances sharing one state file model separate worker
        // processes: the second sees the first's consumption.
        $a = new GproApiThrottle($this->stateFile, ratePerSecond: 4.0, burst: 2.0, maxBlockMs: 2000);
        $a->reserve(1000.0);
        $a->reserve(1000.0); // bucket now empty

        $b = new GproApiThrottle($this->stateFile, ratePerSecond: 4.0, burst: 2.0, maxBlockMs: 2000);
        $this->assertEqualsWithDelta(0.25, $b->reserve(1000.0), 1e-9);
    }
}
