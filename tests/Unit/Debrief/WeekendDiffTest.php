<?php

declare(strict_types=1);

namespace App\Tests\Unit\Debrief;

use App\Debrief\WeekendDiff;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WeekendDiff::class)]
final class WeekendDiffTest extends TestCase
{
    private const array FACILITIES = [
        'windtunnel' => 3, 'pitstop' => 2, 'rdWorkshop' => 1, 'rdDesign' => 1,
        'engineering' => 4, 'lab' => 2, 'commercial' => 5,
    ];

    private const array PROFILE = [
        'concentration' => 100, 'talent' => 150, 'aggressiveness' => 50, 'experience' => 80,
        'techInsight' => 70, 'stamina' => 90, 'charisma' => 40, 'motivation' => 60,
    ];

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'source' => 'snapshot',
            'driver_json' => json_encode(['id' => 7, 'name' => 'A', 'profile' => self::PROFILE]),
            'td_json' => null,
            'staff_json' => null,
            'facilities_json' => json_encode(self::FACILITIES),
            'car_json' => null,
            'cash' => 0,
            'pts' => 0,
            'pos' => 1,
        ], $overrides);
    }

    public function testFacilityUpgradeIsReported(): void
    {
        $previous = $this->row();
        $current = $this->row(['facilities_json' => json_encode(['windtunnel' => 4] + self::FACILITIES)]);

        $this->assertSame(
            [['group' => 'facilities', 'key' => 'windtunnel', 'label' => 'Wind tunnel', 'from' => 3, 'to' => 4]],
            WeekendDiff::changes($previous, $current),
        );
    }

    public function testNewDriverIsReportedByIdNotEveryAttribute(): void
    {
        $profile = ['concentration' => 110, 'talent' => 150, 'stamina' => null] + self::PROFILE;
        $current = $this->row(['driver_json' => json_encode(['id' => 9, 'name' => 'B', 'profile' => $profile])]);

        $changes = WeekendDiff::changes($this->row(), $current);

        $this->assertSame(
            [['group' => 'driver', 'key' => 'id', 'label' => 'Driver', 'from' => 7, 'to' => 9]],
            $changes,
        );
    }

    public function testNewTechnicalDirectorIsReportedByIdNotEveryAttribute(): void
    {
        $td = static fn (int $id, int $pit): string => (string) json_encode([
            'id' => $id, 'profile' => ['pitCoord' => $pit, 'leadership' => 5],
        ]);

        $changes = WeekendDiff::changes(
            $this->row(['td_json' => $td(1, 10)]),
            $this->row(['td_json' => $td(2, 12)]),
        );

        $this->assertSame(
            [['group' => 'td', 'key' => 'id', 'label' => 'Technical director', 'from' => 1, 'to' => 2]],
            $changes,
        );
    }

    public function testFirstTechnicalDirectorHireIsReportedWhenBothRowsAreSnapshots(): void
    {
        $td = (string) json_encode(['id' => 4, 'name' => 'T. Director', 'profile' => ['pitCoord' => 10]]);
        $previous = $this->row();
        $current = $this->row(['td_json' => $td]);

        $changes = WeekendDiff::changes($previous, $current);

        $this->assertSame(
            [['group' => 'td', 'key' => 'id', 'label' => 'Technical director', 'from' => null, 'to' => 4]],
            $changes,
        );
        $this->assertSame(
            [['group' => 'td', 'key' => 'id', 'label' => 'Technical director', 'from' => null, 'to' => 'T. Director']],
            WeekendDiff::withNames($changes, $previous, $current),
        );
    }

    public function testTechnicalDirectorReleaseIsReportedWhenBothRowsAreSnapshots(): void
    {
        $td = (string) json_encode(['id' => 4, 'name' => 'T. Director', 'profile' => ['pitCoord' => 10]]);
        $previous = $this->row(['td_json' => $td]);
        $current = $this->row();

        $changes = WeekendDiff::changes($previous, $current);

        $this->assertSame(
            [['group' => 'td', 'key' => 'id', 'label' => 'Technical director', 'from' => 4, 'to' => null]],
            $changes,
        );
        $this->assertSame(
            [['group' => 'td', 'key' => 'id', 'label' => 'Technical director', 'from' => 'T. Director', 'to' => null]],
            WeekendDiff::withNames($changes, $previous, $current),
        );
    }

    public function testNoTechnicalDirectorOnEitherSideReportsNothing(): void
    {
        $this->assertSame([], WeekendDiff::changes($this->row(), $this->row()));
    }

    public function testHistorySourcedRowNeverReportsATechnicalDirectorChange(): void
    {
        $td = (string) json_encode(['id' => 4, 'name' => 'T. Director', 'profile' => ['pitCoord' => 10]]);
        $history = $this->row(['source' => 'history']);

        $this->assertSame([], WeekendDiff::changes($history, $this->row(['td_json' => $td])));
        $this->assertSame([], WeekendDiff::changes($this->row(['td_json' => $td]), $history));
    }

    public function testSameDriverStillReportsAttributeChanges(): void
    {
        $current = $this->row(['driver_json' => json_encode([
            'id' => 7, 'name' => 'A', 'profile' => ['concentration' => 110] + self::PROFILE,
        ])]);

        $this->assertSame(
            [['group' => 'driver', 'key' => 'concentration', 'label' => 'Concentration', 'from' => 100, 'to' => 110]],
            WeekendDiff::changes($this->row(), $current),
        );
    }

    public function testHistorySourcedRowWithoutDriverReportsNoDriverChange(): void
    {
        $history = $this->row(['source' => 'history', 'driver_json' => null]);

        $this->assertSame([], WeekendDiff::changes($this->row(), $history));
        $this->assertSame([], WeekendDiff::changes($history, $this->row()));
    }

    public function testFirstStoredRaceHasNoChanges(): void
    {
        $this->assertSame([], WeekendDiff::changes(null, $this->row()));
    }

    public function testUndecodableJsonSkipsThatGroup(): void
    {
        $previous = $this->row(['facilities_json' => '{not json']);
        $current = $this->row(['facilities_json' => json_encode(['windtunnel' => 9] + self::FACILITIES)]);

        $this->assertSame([], WeekendDiff::changes($previous, $current));
    }

    public function testCarReportsPointsAndPartLevelsButNeverWear(): void
    {
        $car = static fn (int $power, int $engineLevel, int $engineWear): string => (string) json_encode([
            'power' => $power, 'handling' => 20, 'accel' => 30,
            'parts' => ['engine' => ['level' => $engineLevel, 'wear' => $engineWear], 'fWing' => ['level' => 4, 'wear' => 10]],
        ]);

        $changes = WeekendDiff::changes(
            $this->row(['car_json' => $car(10, 5, 10)]),
            $this->row(['car_json' => $car(12, 6, 55)]),
        );

        $this->assertSame(
            [
                ['group' => 'car', 'key' => 'power', 'label' => 'Power', 'from' => 10, 'to' => 12],
                ['group' => 'car', 'key' => 'engine', 'label' => 'Engine level', 'from' => 5, 'to' => 6],
            ],
            $changes,
        );
    }

    public function testCarWithoutPartsFromHistoryStillComparesPoints(): void
    {
        $changes = WeekendDiff::changes(
            $this->row(['car_json' => json_encode(['power' => 10, 'handling' => 20, 'accel' => 30])]),
            $this->row(['car_json' => json_encode([
                'power' => 10, 'handling' => 21, 'accel' => 30,
                'parts' => ['engine' => ['level' => 6, 'wear' => 0]],
            ])]),
        );

        $this->assertSame(
            [['group' => 'car', 'key' => 'handling', 'label' => 'Handling', 'from' => 20, 'to' => 21]],
            $changes,
        );
    }

    public function testTechnicalDirectorAndStaffAreCompared(): void
    {
        $td = static fn (int $id, int $pit): string => (string) json_encode([
            'id' => $id, 'profile' => ['pitCoord' => $pit, 'leadership' => 5],
        ]);
        $staff = static fn (int $stress): string => (string) json_encode(['stressHandling' => $stress, 'efficiency' => 3]);

        $changes = WeekendDiff::changes(
            $this->row(['td_json' => $td(1, 10), 'staff_json' => $staff(8)]),
            $this->row(['td_json' => $td(1, 12), 'staff_json' => $staff(9)]),
        );

        $this->assertSame(
            [
                ['group' => 'td', 'key' => 'pitCoord', 'label' => 'Pit coordination', 'from' => 10, 'to' => 12],
                ['group' => 'staff', 'key' => 'stressHandling', 'label' => 'Stress handling', 'from' => 8, 'to' => 9],
            ],
            $changes,
        );
    }

    public function testUnchangedWeekendReportsNothing(): void
    {
        $this->assertSame([], WeekendDiff::changes($this->row(), $this->row()));
    }

    public function testWithNamesSwapsHireIdsForNamesAndKeepsTheIdWhenNoneIsStored(): void
    {
        $previous = $this->row();
        $current = $this->row(['driver_json' => json_encode(['id' => 9, 'name' => 'B', 'profile' => self::PROFILE])]);
        $changes = WeekendDiff::withNames(WeekendDiff::changes($previous, $current), $previous, $current);

        $this->assertSame('A', $changes[0]['from']);
        $this->assertSame('B', $changes[0]['to']);

        $nameless = $this->row(['driver_json' => json_encode(['id' => 9, 'profile' => self::PROFILE])]);
        $changes = WeekendDiff::withNames(WeekendDiff::changes($previous, $nameless), $previous, $nameless);

        $this->assertSame('A', $changes[0]['from']);
        $this->assertSame(9, $changes[0]['to']);
    }
}
