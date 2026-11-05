<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\SmokeRisk;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SmokeRisk::class)]
final class SmokeRiskTest extends TestCase
{
    public function test_part_reaching_100_late_smokes_without_likely_break(): void
    {
        $risk = SmokeRisk::for(80, 25, 60);

        $this->assertNotNull($risk);
        $this->assertSame(48, $risk->lap);
        $this->assertSame(60, $risk->laps);
        $this->assertSame(13, $risk->smokeLaps);
        $this->assertEqualsWithDelta(0.5 * 8 / 15, $risk->breakChance, 1e-9);
        $this->assertFalse($risk->likelyBreak());
        $this->assertSame('Reaches 100 % on lap 48 of 60: smokes about 13 laps', $risk->describe());
        $this->assertSame('Brakes reaches 100 % on lap 48 of 60: smokes about 13 laps', $risk->describe('Brakes'));
    }

    /** @return array<string, array{int, int, float}> */
    public static function breakpoints(): array
    {
        return [
            'five laps is the floor' => [44, 5, 0.0],
            'twenty laps is a coin flip' => [59, 20, 0.5],
            'between median and max' => [64, 25, 0.75],
            'thirty laps is certain' => [69, 30, 1.0],
            'past the max stays certain' => [79, 40, 1.0],
            'just above the floor' => [46, 7, 0.5 * 2 / 15],
        ];
    }

    #[DataProvider('breakpoints')]
    public function test_break_chance_breakpoints(int $start, int $smokeLaps, float $chance): void
    {
        $risk = SmokeRisk::for($start, 60, 60);

        $this->assertNotNull($risk);
        $this->assertSame($smokeLaps, $risk->smokeLaps);
        $this->assertEqualsWithDelta($chance, $risk->breakChance, 1e-9);
    }

    public function test_twenty_smoke_laps_is_likely_to_break(): void
    {
        $risk = SmokeRisk::for(59, 60, 60);

        $this->assertNotNull($risk);
        $this->assertTrue($risk->likelyBreak());
        $this->assertSame('likely to break and retire', $risk->phrase());
    }

    public function test_one_lap_is_singular(): void
    {
        $risk = SmokeRisk::for(70, 29.1, 50);

        $this->assertNotNull($risk);
        $this->assertSame(50, $risk->lap);
        $this->assertSame(1, $risk->smokeLaps);
        $this->assertSame('smokes about 1 lap', $risk->phrase());
    }

    public function test_wear_that_rounds_to_99_never_reaches_100(): void
    {
        $this->assertNull(SmokeRisk::for(70, 28.9, 50));
    }

    public function test_already_at_100_smokes_from_lap_one(): void
    {
        $risk = SmokeRisk::for(100, 10, 50);

        $this->assertNotNull($risk);
        $this->assertSame(1, $risk->lap);
        $this->assertSame(50, $risk->smokeLaps);
        $this->assertTrue($risk->likelyBreak());
    }

    public function test_degenerate_inputs_give_nothing(): void
    {
        $this->assertNull(SmokeRisk::for(50, 0, 50));
        $this->assertNull(SmokeRisk::for(100, 0, 50));
        $this->assertNull(SmokeRisk::for(80, 25, 0));
    }

    public function test_for_row_adds_training_to_the_start(): void
    {
        $row = ['start' => 70, 'training' => 10, 'est' => 25.0, 'end' => 105.0];

        $this->assertEquals(SmokeRisk::for(80, 25, 60), SmokeRisk::forRow($row, 60));
    }
}
