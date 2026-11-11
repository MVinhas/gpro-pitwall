<?php

declare(strict_types=1);

namespace App\Tests\Unit\Telemetry;

use App\Telemetry\RaceReplayMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RaceReplayMapper::class)]
final class RaceReplayMapperTest extends TestCase
{
    /**
     * One replay lap entry for one manager, shaped after the spec's lap-1-onwards key.
     *
     * @return array<int, int|string>
     */
    private function row(int $timeMs, int $pos, bool $pit = false, bool $mistake = false, int $mistakeMs = 0, bool $out = false): array
    {
        return [
            0 => 'Manager', 1 => $timeMs, 2 => $pit ? 1 : 0, 3 => 0, 4 => 0, 5 => 0, 6 => 1, 7 => 0,
            8 => $mistake ? 1 : 0, 9 => $out ? 1 : 0, 10 => $pos, 11 => $mistakeMs,
        ];
    }

    /**
     * @param array<int, list<array{0: string, 1: array<int, int|string>}>> $laps lap number => [[managerId, row], ...]
     * @return array<string, mixed>
     */
    private function replay(array $laps): array
    {
        $data = [];
        foreach ($laps as $lap => $managers) {
            $entry = [];
            foreach ($managers as [$id, $row]) {
                $entry[] = [$id => $row];
            }
            $data[] = ['lapNb' => $lap, 'managers' => $entry];
        }

        return ['replayData' => $data, 'hasAccess' => 1];
    }

    /**
     * @param list<int> $positions
     * @return list<array{idx: int, pos: int}>
     */
    private function analysisLaps(array $positions): array
    {
        $laps = [['idx' => 0, 'pos' => 5]];
        foreach ($positions as $i => $pos) {
            $laps[] = ['idx' => $i + 1, 'pos' => $pos];
        }

        return $laps;
    }

    /**
     * Three managers. Ours ("111") runs 2nd, 2nd, 3rd, 3rd. "222" leads, "333" is last.
     *
     * @return array<string, mixed>
     */
    private function threeCars(): array
    {
        return $this->replay([
            1 => [
                ['222', $this->row(90000, 1)],
                ['111', $this->row(90500, 2, mistake: true, mistakeMs: 1200)],
                ['333', $this->row(93000, 3)],
            ],
            2 => [
                ['222', $this->row(180000, 1)],
                ['111', $this->row(180400, 2)],
                ['333', $this->row(181200, 3)],
            ],
            3 => [
                ['222', $this->row(270000, 1)],
                ['333', $this->row(271000, 2)],
                ['111', $this->row(272500, 3, mistake: true, mistakeMs: 800)],
            ],
            4 => [
                ['222', $this->row(360000, 1)],
                ['333', $this->row(361000, 2)],
                ['111', $this->row(362800, 3)],
            ],
        ]);
    }

    public function testFindsOurManagerByPositionSequenceAndSumsMistakes(): void
    {
        $out = (new RaceReplayMapper())->map($this->threeCars(), $this->analysisLaps([2, 2, 3, 3]));

        self::assertNotNull($out);
        self::assertSame(2, $out['race_mistakes']);
        self::assertSame(2000, $out['race_mistake_ms']);
        self::assertSame(2.5, $out['avg_race_pos']);
    }

    public function testCountsLapsWithinOneSecondOfAheadAndBehind(): void
    {
        $out = (new RaceReplayMapper())->map($this->threeCars(), $this->analysisLaps([2, 2, 3, 3]));

        self::assertNotNull($out);
        // Ahead: lap1 gap 500 (yes), lap2 gap 400 (yes), lap3 gap 1500 (no), lap4 gap 1800 (no).
        self::assertSame(2, $out['traffic_ahead_laps']);
        // Behind: lap1 gap 2500 (no), lap2 gap 800 (yes); laps 3 and 4 nobody behind.
        self::assertSame(1, $out['traffic_behind_laps']);
        self::assertSame(4, $out['replay_laps']);
    }

    public function testPitLapsAreLeftOutOfTraffic(): void
    {
        $replay = $this->threeCars();
        $replay['replayData'][1]['managers'][1] = ['111' => $this->row(180400, 2, pit: true)];

        $out = (new RaceReplayMapper())->map($replay, $this->analysisLaps([2, 2, 3, 3]));

        self::assertNotNull($out);
        self::assertSame(1, $out['traffic_ahead_laps']);
        self::assertSame(0, $out['traffic_behind_laps']);
        self::assertSame(3, $out['replay_laps']);
    }

    public function testNoManagerMatchesGivesNull(): void
    {
        self::assertNull((new RaceReplayMapper())->map($this->threeCars(), $this->analysisLaps([2, 1, 3, 1])));
    }

    public function testMissingOrEmptyReplayGivesNull(): void
    {
        $mapper = new RaceReplayMapper();

        self::assertNull($mapper->map([], $this->analysisLaps([2])));
        self::assertNull($mapper->map(['replayData' => [], 'hasAccess' => 0], $this->analysisLaps([2])));
        self::assertNull($mapper->map($this->threeCars(), []));
    }

    public function testRetiredCarsAreNotAheadOrBehind(): void
    {
        $replay = $this->threeCars();
        // "222" is out of the race from lap 2: it must not count as the car ahead.
        $replay['replayData'][1]['managers'][0] = ['222' => $this->row(180000, 1, out: true)];

        $out = (new RaceReplayMapper())->map($replay, $this->analysisLaps([2, 2, 3, 3]));

        self::assertNotNull($out);
        self::assertSame(1, $out['traffic_ahead_laps']);
    }

    public function testRaceTimeThatIsNotCumulativeDisablesTraffic(): void
    {
        $replay = $this->replay([
            1 => [['111', $this->row(90000, 1)], ['222', $this->row(90400, 2)]],
            2 => [['111', $this->row(89500, 1)], ['222', $this->row(89900, 2)]],
            3 => [['111', $this->row(90100, 1)], ['222', $this->row(90300, 2)]],
        ]);

        $out = (new RaceReplayMapper())->map($replay, $this->analysisLaps([1, 1, 1]));

        self::assertNotNull($out);
        self::assertNull($out['traffic_ahead_laps']);
        self::assertNull($out['traffic_behind_laps']);
        self::assertSame(0, $out['race_mistakes']);
    }
}
