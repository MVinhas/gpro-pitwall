<?php

declare(strict_types=1);

namespace App\Engineer;

/**
 * The sums a ridge regression needs (X'X, X'y, y'y and the row count), kept so that
 * two sets of rows can be added or taken apart without reading the rows again. That
 * is what makes held-out testing cheap: the fit on "everything but this fold" is the
 * full sums minus the fold's sums.
 *
 * There is no intercept: callers centre their rows first. Columns are scaled by their
 * root mean square before the penalty is applied, so one penalty means the same for
 * every column; coefficients come back on the raw scale.
 */
final class RidgeGram
{
    private const float SINGULAR = 1e-12;
    private const float FLAT_COLUMN = 1e-9;

    /** @var array<int, array<int, float>> */
    private array $xx;

    /** @var array<int, float> */
    private array $xy;

    private float $yy = 0.0;

    private int $rows = 0;

    public function __construct(private readonly int $width)
    {
        $this->xy = array_fill(0, $width, 0.0);
        $this->xx = array_fill(0, $width, $this->xy);
    }

    /**
     * @param list<float> $x one value per column
     */
    public function add(array $x, float $y): void
    {
        for ($i = 0; $i < $this->width; $i++) {
            $xi = $x[$i];
            $this->xy[$i] += $xi * $y;
            for ($j = 0; $j < $this->width; $j++) {
                $this->xx[$i][$j] += $xi * $x[$j];
            }
        }
        $this->yy += $y * $y;
        $this->rows++;
    }

    public function plus(self $other): self
    {
        return $this->combined($other, 1);
    }

    public function minus(self $other): self
    {
        return $this->combined($other, -1);
    }

    public function rows(): int
    {
        return $this->rows;
    }

    /**
     * Ridge coefficients for the chosen columns, in the order given.
     *
     * @param list<int> $columns
     *
     * @return list<float>|null null when there are no rows or the system cannot be solved
     */
    public function solve(array $columns, float $lambda): ?array
    {
        if ($this->rows <= 0) {
            return null;
        }

        $scale = [];
        foreach ($columns as $c) {
            $rms = sqrt(max(0.0, $this->xx[$c][$c]) / $this->rows);
            $scale[] = $rms >= self::FLAT_COLUMN ? $rms : 1.0;
        }

        $a = [];
        $b = [];
        foreach ($columns as $i => $ci) {
            $row = [];
            foreach ($columns as $j => $cj) {
                $row[] = $this->xx[$ci][$cj] / ($scale[$i] * $scale[$j]);
            }
            $row[$i] += $lambda;
            $a[] = $row;
            $b[] = $this->xy[$ci] / $scale[$i];
        }

        $beta = self::gauss($a, $b);
        if ($beta === null) {
            return null;
        }

        $out = [];
        foreach ($beta as $i => $value) {
            $out[] = $value / $scale[$i];
        }

        return $out;
    }

    /**
     * The sum of squared errors these coefficients leave on the rows summed here.
     *
     * @param list<int> $columns
     * @param list<float> $beta one coefficient per column, raw scale
     */
    public function sse(array $columns, array $beta): float
    {
        $total = $this->yy;
        foreach ($columns as $i => $ci) {
            $total -= 2 * $beta[$i] * $this->xy[$ci];
            foreach ($columns as $j => $cj) {
                $total += $beta[$i] * $beta[$j] * $this->xx[$ci][$cj];
            }
        }

        return $total;
    }

    private function combined(self $other, int $sign): self
    {
        $out = new self($this->width);
        for ($i = 0; $i < $this->width; $i++) {
            $out->xy[$i] = $this->xy[$i] + $sign * $other->xy[$i];
            for ($j = 0; $j < $this->width; $j++) {
                $out->xx[$i][$j] = $this->xx[$i][$j] + $sign * $other->xx[$i][$j];
            }
        }
        $out->yy = $this->yy + $sign * $other->yy;
        $out->rows = $this->rows + $sign * $other->rows;

        return $out;
    }

    /**
     * Gaussian elimination with partial pivoting.
     *
     * @param array<int, array<int, float>> $a
     * @param array<int, float> $b
     *
     * @return array<int, float>|null
     */
    private static function gauss(array $a, array $b): ?array
    {
        $n = count($b);
        for ($i = 0; $i < $n; $i++) {
            $a[$i][] = $b[$i];
        }

        for ($c = 0; $c < $n; $c++) {
            $pivot = $c;
            for ($r = $c + 1; $r < $n; $r++) {
                if (abs($a[$r][$c]) > abs($a[$pivot][$c])) {
                    $pivot = $r;
                }
            }
            if (abs($a[$pivot][$c]) < self::SINGULAR) {
                return null;
            }
            [$a[$c], $a[$pivot]] = [$a[$pivot], $a[$c]];
            for ($r = $c + 1; $r < $n; $r++) {
                $factor = $a[$r][$c] / $a[$c][$c];
                for ($j = $c; $j <= $n; $j++) {
                    $a[$r][$j] -= $factor * $a[$c][$j];
                }
            }
        }

        $x = array_fill(0, $n, 0.0);
        for ($i = $n - 1; $i >= 0; $i--) {
            $sum = $a[$i][$n];
            for ($j = $i + 1; $j < $n; $j++) {
                $sum -= $a[$i][$j] * $x[$j];
            }
            $x[$i] = $sum / $a[$i][$i];
        }

        return $x;
    }
}
