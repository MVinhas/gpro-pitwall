<?php

declare(strict_types=1);

namespace App\Engineer;

final readonly class EnergyModel
{
    public const int ENERGY_MARGIN = 10;
    public const int MIN_ROWS = 30;
    public const int HARD_MAX = 79;
    public const int QUALI_DRAIN_FALLBACK = 4;

    // Provisional estimates, not GPRO rules: Micael's observed ceilings and a rough recovery line.
    public const float RECOVERY_BASE = 70.0;
    public const float RECOVERY_SLOPE = 0.3;
    public const array FALLBACK_CAP = [
        'Rookie' => 25,
        'Amateur' => 35,
        'Pro' => 47,
        'Master' => 65,
        'Elite' => 65,
    ];

    /**
     * @param array{a: float, b: float, s: float}|null $drain per-lap energy drain, null when unfitted
     */
    private function __construct(
        private ?array $drain,
        private float $r0,
        private float $r1,
        private int $qualiDrain,
    ) {
    }

    /**
     * @param list<array{ctr: int, from: int, to: int, laps: int, stamina: int}> $raceRows one division
     * @param list<array{q1_end: int, q2_end: int, race_from: int}> $qualiRows all divisions
     */
    public static function fit(array $raceRows, array $qualiRows): self
    {
        [$r0, $r1] = self::fitRecovery($qualiRows);

        return new self(self::fitDrain($raceRows), $r0, $r1, self::fitQualiDrain($qualiRows));
    }

    public static function fallback(): self
    {
        return self::fit([], []);
    }

    /**
     * @return array{drain: array{a: float, b: float, s: float}|null, r0: float, r1: float, quali_drain: int}
     */
    public function toArray(): array
    {
        return ['drain' => $this->drain, 'r0' => $this->r0, 'r1' => $this->r1, 'quali_drain' => $this->qualiDrain];
    }

    /**
     * @param array{drain: array{a: float, b: float, s: float}|null, r0: float, r1: float, quali_drain: int} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['drain'], $data['r0'], $data['r1'], $data['quali_drain']);
    }

    public function raceStart(int $driEnergy, bool $q2Done): int
    {
        $q2End = $q2Done ? $driEnergy : max(0, $driEnergy - $this->qualiDrain);

        // The inner round absorbs float noise from the fit so an exact .5 rounds the way it reads.
        $recovered = (int) round(round($this->r0 + $this->r1 * $q2End, 6));

        return min(100, max($q2End, $recovered));
    }

    /**
     * @return array{cap: int, estimate: bool, start: ?int}
     */
    public function cap(?int $driEnergy, bool $q2Done, int $laps, int $stamina, ?string $division): array
    {
        $start = $driEnergy === null ? null : $this->raceStart($driEnergy, $q2Done);

        if ($this->drain === null || $start === null || $laps <= 0) {
            $fallback = $division !== null
                ? (self::FALLBACK_CAP[$division] ?? max(self::FALLBACK_CAP))
                : max(self::FALLBACK_CAP);

            return ['cap' => min(self::HARD_MAX, $fallback), 'estimate' => true, 'start' => $start];
        }

        ['a' => $a, 'b' => $b, 's' => $s] = $this->drain;
        for ($ctr = self::HARD_MAX; $ctr > 0; $ctr--) {
            // The epsilon keeps a cap that lands exactly on the margin from flipping on float noise.
            if ($start - $laps * ($a + $b * $ctr + $s * $stamina) >= self::ENERGY_MARGIN - 1e-9) {
                return ['cap' => $ctr, 'estimate' => false, 'start' => $start];
            }
        }

        return ['cap' => 0, 'estimate' => false, 'start' => $start];
    }

    /**
     * @param list<array{ctr: int, from: int, to: int, laps: int, stamina: int}> $rows
     * @return array{a: float, b: float, s: float}|null
     */
    private static function fitDrain(array $rows): ?array
    {
        $n = 0;
        $m = [[0.0, 0.0, 0.0, 0.0], [0.0, 0.0, 0.0, 0.0], [0.0, 0.0, 0.0, 0.0]];
        foreach ($rows as $row) {
            if ($row['laps'] <= 0 || $row['to'] <= 0 || $row['from'] < $row['to']) {
                continue;
            }
            $n++;
            $x = [1.0, (float) $row['ctr'], (float) $row['stamina']];
            $y = ($row['from'] - $row['to']) / $row['laps'];
            for ($i = 0; $i < 3; $i++) {
                for ($j = 0; $j < 3; $j++) {
                    $m[$i][$j] += $x[$i] * $x[$j];
                }
                $m[$i][3] += $x[$i] * $y;
            }
        }
        if ($n < self::MIN_ROWS) {
            return null;
        }

        for ($col = 0; $col < 3; $col++) {
            $pivot = $col;
            for ($r = $col + 1; $r < 3; $r++) {
                if (abs($m[$r][$col]) > abs($m[$pivot][$col])) {
                    $pivot = $r;
                }
            }
            if (abs($m[$pivot][$col]) < 1e-9) {
                return null;
            }
            [$m[$col], $m[$pivot]] = [$m[$pivot], $m[$col]];
            for ($r = $col + 1; $r < 3; $r++) {
                $factor = $m[$r][$col] / $m[$col][$col];
                for ($c = $col; $c < 4; $c++) {
                    $m[$r][$c] -= $factor * $m[$col][$c];
                }
            }
        }

        $solution = [0.0, 0.0, 0.0];
        for ($i = 2; $i >= 0; $i--) {
            $sum = $m[$i][3];
            for ($j = $i + 1; $j < 3; $j++) {
                $sum -= $m[$i][$j] * $solution[$j];
            }
            $solution[$i] = $sum / $m[$i][$i];
        }

        if ($solution[1] <= 0.0) {
            return null;
        }

        return ['a' => $solution[0], 'b' => $solution[1], 's' => $solution[2]];
    }

    /**
     * @param list<array{q1_end: int, q2_end: int, race_from: int}> $rows
     * @return array{float, float}
     */
    private static function fitRecovery(array $rows): array
    {
        $defaults = [self::RECOVERY_BASE, self::RECOVERY_SLOPE];
        $points = array_values(array_filter($rows, static fn (array $row): bool => $row['race_from'] < 100));
        $n = count($points);
        if ($n < self::MIN_ROWS) {
            return $defaults;
        }

        $sx = $sy = $sxx = $sxy = 0.0;
        foreach ($points as $row) {
            $sx += $row['q2_end'];
            $sy += $row['race_from'];
            $sxx += $row['q2_end'] ** 2;
            $sxy += $row['q2_end'] * $row['race_from'];
        }
        $denominator = $n * $sxx - $sx ** 2;
        if (abs($denominator) < 1e-9) {
            return $defaults;
        }

        $r1 = ($n * $sxy - $sx * $sy) / $denominator;
        if ($r1 < 0.0 || $r1 > 1.0) {
            return $defaults;
        }

        return [($sy - $r1 * $sx) / $n, $r1];
    }

    /**
     * @param list<array{q1_end: int, q2_end: int, race_from: int}> $rows
     */
    private static function fitQualiDrain(array $rows): int
    {
        if (count($rows) < self::MIN_ROWS) {
            return self::QUALI_DRAIN_FALLBACK;
        }

        $drops = array_map(static fn (array $row): int => max(0, $row['q1_end'] - $row['q2_end']), $rows);
        sort($drops);
        $half = intdiv(count($drops), 2);
        $median = count($drops) % 2 === 1 ? $drops[$half] : ($drops[$half - 1] + $drops[$half]) / 2;

        return (int) round(2 * $median);
    }
}
