<?php

declare(strict_types=1);

namespace App\Tests\Unit\Archive;

use App\Archive\BlockSource;
use App\Archive\RaceSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RaceSnapshot::class)]
final class RaceSnapshotTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function analysis(int $driverId): array
    {
        return ['driver' => ['id' => $driverId, 'OA' => '140']];
    }

    /**
     * @param array<string, mixed> $office
     */
    private static function snapshot(
        BlockSource $td,
        BlockSource $staff,
        BlockSource $driver,
        array $office = ['driId' => '1234', 'tdId' => '55'],
    ): RaceSnapshot {
        return new RaceSnapshot(
            $office,
            $driver === BlockSource::Present ? ['age' => 27] : [],
            $td === BlockSource::Present ? ['tdName' => 'Ada Byron', 'overall' => 50] : [],
            $staff === BlockSource::Present ? ['lvlLab' => 5] : [],
            [],
            [],
            [],
            true,
            $driver !== BlockSource::None,
            $td,
            $staff,
            $driver,
        );
    }

    public function testNoDriverUnderContractNeverMatchesTheRacingDriver(): void
    {
        $analysis = self::analysis(1234);

        $this->assertTrue(RaceSnapshot::raceWasRunByCurrentDriver($analysis, ['driId' => ' 1234 ']));
        $this->assertFalse(RaceSnapshot::raceWasRunByCurrentDriver($analysis, ['driId' => '9999']));
        $this->assertFalse(RaceSnapshot::raceWasRunByCurrentDriver($analysis, ['driId' => '']));
        $this->assertFalse(RaceSnapshot::raceWasRunByCurrentDriver($analysis, ['driId' => '0']));
        $this->assertFalse(RaceSnapshot::raceWasRunByCurrentDriver($analysis, []));
        $this->assertFalse(RaceSnapshot::raceWasRunByCurrentDriver([], ['driId' => '']));
        $this->assertFalse(RaceSnapshot::raceWasRunByCurrentDriver(['driver' => ['id' => 0]], ['driId' => '0']));
        $this->assertFalse(RaceSnapshot::raceWasRunByCurrentDriver($analysis, ['driId' => ['not', 'scalar']]));
    }

    public function testEachBlockIsTheProfileWhenPresentEmptyWhenNoneAndNullWhenAbsent(): void
    {
        $present = self::snapshot(BlockSource::Present, BlockSource::Present, BlockSource::Present);
        $this->assertSame('Ada Byron', $present->tdThatRaced()['tdName'] ?? null);
        $this->assertSame(['lvlLab' => 5], $present->staffThatRaced());
        $this->assertSame(['age' => 27], $present->driverThatRaced(self::analysis(1234)));

        $none = self::snapshot(BlockSource::None, BlockSource::Absent, BlockSource::None, ['driId' => '', 'tdId' => '']);
        $this->assertSame([], $none->tdThatRaced());
        $this->assertSame([], $none->driverThatRaced(self::analysis(1234)));

        $absent = self::snapshot(BlockSource::Absent, BlockSource::Absent, BlockSource::Absent);
        $this->assertNull($absent->tdThatRaced());
        $this->assertNull($absent->staffThatRaced());
        $this->assertNull($absent->driverThatRaced(self::analysis(1234)));
    }

    public function testAProfileOfAnotherDriverIsUnknownNotNone(): void
    {
        $snapshot = self::snapshot(BlockSource::Present, BlockSource::Present, BlockSource::Present);

        $this->assertNull($snapshot->driverThatRaced(self::analysis(9999)));
    }
}
