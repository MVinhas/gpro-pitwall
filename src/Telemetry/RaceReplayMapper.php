<?php

declare(strict_types=1);

namespace App\Telemetry;

/**
 * @phpstan-type ReplayFacts array{
 *     replay_laps: int,
 *     race_mistakes: int,
 *     race_mistake_ms: int,
 *     traffic_ahead_laps: int|null,
 *     traffic_behind_laps: int|null,
 *     avg_race_pos: float
 * }
 *
 * Turns a raw GPRO RaceReplay payload into the race-time facts RaceAnalysis
 * lacks: how often the driver made a mistake, what it cost, and how much of
 * the race was spent close to another car.
 *
 * The replay lists every manager in the group and carries no "this is you"
 * marker, so our car is the one whose per-lap position sequence matches the
 * RaceAnalysis laps. Output is anonymous: no manager id leaves this class.
 */
final class RaceReplayMapper
{
    use PayloadReader;

    private const int RACE_TIME_MS = 1;
    private const int IN_PITS = 2;
    private const int MISTAKE = 8;
    private const int OUT_OF_RACE = 9;
    private const int POSITION = 10;
    private const int MISTAKE_MS = 11;

    /** A car this close (or closer) counts as traffic. */
    private const int TRAFFIC_GAP_MS = 1000;

    /** Share of comparable laps whose position must agree to call a manager ours. */
    private const float MATCH_SHARE = 0.9;

    /**
     * @param array<string, mixed> $replay RaceReplay payload
     * @param list<array<string, mixed>> $analysisLaps RaceAnalysis laps[]
     * @return ReplayFacts|null null when the payload is unusable or our car cannot be told apart
     */
    public function map(array $replay, array $analysisLaps): ?array
    {
        $byLap = $this->lapsFrom($replay);
        $expected = $this->expectedPositions($analysisLaps);
        if ($byLap === [] || $expected === []) {
            return null;
        }

        $ours = $this->ourManager($byLap, $expected);
        if ($ours === null) {
            return null;
        }

        $series = [];
        foreach ($byLap as $lap => $rows) {
            if (isset($rows[$ours]) && $this->int($rows[$ours][self::OUT_OF_RACE] ?? null) !== 1) {
                $series[$lap] = $rows[$ours];
            }
        }
        if ($series === []) {
            return null;
        }

        $mistakes = 0;
        $mistakeMs = 0;
        $positions = [];
        foreach ($series as $row) {
            if ($this->int($row[self::MISTAKE] ?? null) === 1) {
                $mistakes++;
                $mistakeMs += max(0, $this->int($row[self::MISTAKE_MS] ?? null) ?? 0);
            }
            $positions[] = $this->int($row[self::POSITION] ?? null) ?? 0;
        }

        $traffic = $this->isCumulative($series) ? $this->traffic($byLap, $series) : null;

        return [
            'race_mistakes'       => $mistakes,
            'race_mistake_ms'     => $mistakeMs,
            'traffic_ahead_laps'  => $traffic['ahead'] ?? null,
            'traffic_behind_laps' => $traffic['behind'] ?? null,
            'avg_race_pos'        => round(array_sum($positions) / count($positions), 2),
            'replay_laps'         => $traffic['laps'] ?? count($series),
        ];
    }

    /**
     * Lap number => manager id => row. Lap 0 (the grid) is dropped.
     *
     * @param array<string, mixed> $replay
     * @return array<int, array<string, array<int|string, mixed>>>
     */
    private function lapsFrom(array $replay): array
    {
        $out = [];
        foreach ($this->listOf($replay['replayData'] ?? null) as $entry) {
            $lap = $this->int($entry['lapNb'] ?? null);
            if ($lap === null || $lap < 1) {
                continue;
            }
            $rows = [];
            foreach ($this->listOf($entry['managers'] ?? null) as $wrapper) {
                foreach ($wrapper as $id => $row) {
                    if (is_array($row)) {
                        $rows[(string) $id] = $row;
                    }
                }
            }
            if ($rows !== []) {
                $out[$lap] = $rows;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $laps
     * @return array<int, int> lap number => position
     */
    private function expectedPositions(array $laps): array
    {
        $out = [];
        foreach ($laps as $lap) {
            $idx = $this->int($lap['idx'] ?? null);
            $pos = $this->int($lap['pos'] ?? null);
            if ($idx !== null && $idx >= 1 && $pos !== null) {
                $out[$idx] = $pos;
            }
        }

        return $out;
    }

    /**
     * @param array<int, array<string, array<int|string, mixed>>> $byLap
     * @param array<int, int> $expected
     */
    private function ourManager(array $byLap, array $expected): ?string
    {
        $hits = [];
        $compared = 0;
        foreach ($expected as $lap => $pos) {
            if (!isset($byLap[$lap])) {
                continue;
            }
            $compared++;
            foreach ($byLap[$lap] as $id => $row) {
                if ($this->int($row[self::POSITION] ?? null) === $pos) {
                    $hits[$id] = ($hits[$id] ?? 0) + 1;
                }
            }
        }
        if ($compared === 0 || $hits === []) {
            return null;
        }

        arsort($hits);
        $ids = array_keys($hits);
        $best = $hits[$ids[0]];
        if ($best / $compared < self::MATCH_SHARE) {
            return null;
        }
        if (isset($ids[1]) && $hits[$ids[1]] === $best) {
            return null;
        }

        return (string) $ids[0];
    }

    /**
     * RACE_TIME_MS is read as cumulative race time. If our own series ever
     * goes backwards it is a per-lap time instead, and gaps cannot be derived.
     *
     * @param array<int, array<int|string, mixed>> $series
     */
    private function isCumulative(array $series): bool
    {
        $previous = null;
        foreach ($series as $row) {
            $time = $this->int($row[self::RACE_TIME_MS] ?? null);
            if ($time === null || ($previous !== null && $time <= $previous)) {
                return false;
            }
            $previous = $time;
        }

        return true;
    }

    /**
     * @param array<int, array<string, array<int|string, mixed>>> $byLap
     * @param array<int, array<int|string, mixed>> $series
     * @return array{ahead: int, behind: int, laps: int}
     */
    private function traffic(array $byLap, array $series): array
    {
        $ahead = 0;
        $behind = 0;
        $laps = 0;
        foreach ($series as $lap => $row) {
            if ($this->int($row[self::IN_PITS] ?? null) === 1) {
                continue;
            }
            $laps++;
            $pos = $this->int($row[self::POSITION] ?? null);
            $time = $this->int($row[self::RACE_TIME_MS] ?? null);
            if ($pos === null || $time === null) {
                continue;
            }
            $times = $this->timesByPosition($byLap[$lap]);
            if (isset($times[$pos - 1]) && $time - $times[$pos - 1] <= self::TRAFFIC_GAP_MS) {
                $ahead++;
            }
            if (isset($times[$pos + 1]) && $times[$pos + 1] - $time <= self::TRAFFIC_GAP_MS) {
                $behind++;
            }
        }

        return ['ahead' => $ahead, 'behind' => $behind, 'laps' => $laps];
    }

    /**
     * Race time per position for the cars still running.
     *
     * @param array<string, array<int|string, mixed>> $rows
     * @return array<int, int>
     */
    private function timesByPosition(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if ($this->int($row[self::OUT_OF_RACE] ?? null) === 1) {
                continue;
            }
            $pos = $this->int($row[self::POSITION] ?? null);
            $time = $this->int($row[self::RACE_TIME_MS] ?? null);
            if ($pos !== null && $time !== null) {
                $out[$pos] = $time;
            }
        }

        return $out;
    }
}
