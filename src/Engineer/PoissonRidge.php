<?php

declare(strict_types=1);

namespace App\Engineer;

/**
 * A Poisson regression with a log link and a ridge penalty, fitted by
 * iteratively reweighted least squares. Counts per exposure (laps) go in via
 * an offset. Features are standardised before fitting, so the one penalty
 * means the same thing for every column; the intercept is never penalised.
 *
 * The ridge is the shrinkage: with little data the slopes stay near zero and
 * the prediction stays near the average rate, instead of swinging on noise.
 */
final readonly class PoissonRidge
{
    private const int MAX_ITERATIONS = 40;
    private const float TOLERANCE = 1e-7;
    private const float LINEAR_CLAMP = 30.0;

    /**
     * @param list<float> $mean per-feature mean
     * @param list<float> $scale per-feature standard deviation (1.0 for a constant column)
     * @param list<float> $beta intercept first, then one slope per feature, on the standardised scale
     */
    private function __construct(
        private array $mean,
        private array $scale,
        private array $beta,
    ) {
    }

    /**
     * @param list<list<float>> $x one feature vector per row
     * @param list<int> $y event count per row
     * @param list<float> $offset log of the exposure per row (0.0 when the rate is per row)
     *
     * @return self|null null when there are too few rows, no events, or the system cannot be solved
     */
    public static function fit(array $x, array $y, array $offset, float $lambda): ?self
    {
        $n = count($x);
        $p = $n > 0 ? count($x[0]) : 0;
        if ($n < $p + 2 || array_sum($y) <= 0) {
            return null;
        }

        [$mean, $scale] = self::moments($x, $p);
        $z = [];
        foreach ($x as $row) {
            $standard = [1.0];
            for ($j = 0; $j < $p; $j++) {
                $standard[] = ($row[$j] - $mean[$j]) / $scale[$j];
            }
            $z[] = $standard;
        }

        $beta = self::irls($z, $y, $offset, $lambda, $p + 1);

        return $beta === null ? null : new self($mean, $scale, array_values($beta));
    }

    /**
     * The expected count for a feature vector and exposure offset.
     *
     * @param list<float> $x
     */
    public function predict(array $x, float $offset = 0.0): float
    {
        $eta = $this->beta[0] + $offset;
        foreach ($this->mean as $j => $mean) {
            $eta += $this->beta[$j + 1] * (($x[$j] - $mean) / $this->scale[$j]);
        }

        return exp(max(-self::LINEAR_CLAMP, min(self::LINEAR_CLAMP, $eta)));
    }

    /**
     * @return array{mean: list<float>, scale: list<float>, beta: list<float>}
     */
    public function toArray(): array
    {
        return ['mean' => $this->mean, 'scale' => $this->scale, 'beta' => $this->beta];
    }

    /**
     * @param array{mean: list<float>, scale: list<float>, beta: list<float>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['mean'], $data['scale'], $data['beta']);
    }

    /**
     * @param list<list<float>> $x
     *
     * @return array{list<float>, list<float>}
     */
    private static function moments(array $x, int $p): array
    {
        $n = count($x);
        $mean = [];
        $scale = [];
        for ($j = 0; $j < $p; $j++) {
            $sum = 0.0;
            foreach ($x as $row) {
                $sum += $row[$j];
            }
            $m = $sum / $n;
            $var = 0.0;
            foreach ($x as $row) {
                $var += ($row[$j] - $m) ** 2;
            }
            $sd = sqrt($var / $n);
            $mean[] = $m;
            $scale[] = $sd < 1e-9 ? 1.0 : $sd;
        }

        return [$mean, $scale];
    }

    /**
     * @param list<list<float>> $z design rows, intercept column first
     * @param list<int> $y
     * @param list<float> $offset
     *
     * @return array<int, float>|null
     */
    private static function irls(array $z, array $y, array $offset, float $lambda, int $width): ?array
    {
        $n = count($z);
        $beta = array_fill(0, $width, 0.0);
        $exposure = 0.0;
        foreach ($offset as $o) {
            $exposure += exp($o);
        }
        $beta[0] = log(max(array_sum($y), 0.5) / max($exposure, 1e-9));

        for ($iteration = 0; $iteration < self::MAX_ITERATIONS; $iteration++) {
            $a = array_fill(0, $width, array_fill(0, $width, 0.0));
            $b = array_fill(0, $width, 0.0);
            for ($i = 0; $i < $n; $i++) {
                $eta = $offset[$i];
                for ($j = 0; $j < $width; $j++) {
                    $eta += $beta[$j] * $z[$i][$j];
                }
                $eta = max(-self::LINEAR_CLAMP, min(self::LINEAR_CLAMP, $eta));
                $mu = exp($eta);
                $working = $eta - $offset[$i] + ($y[$i] - $mu) / $mu;
                for ($j = 0; $j < $width; $j++) {
                    $b[$j] += $mu * $z[$i][$j] * $working;
                    for ($k = 0; $k < $width; $k++) {
                        $a[$j][$k] += $mu * $z[$i][$j] * $z[$i][$k];
                    }
                }
            }
            for ($j = 1; $j < $width; $j++) {
                $a[$j][$j] += $lambda;
            }

            $next = self::solve($a, $b);
            if ($next === null) {
                return null;
            }
            $change = 0.0;
            foreach ($next as $j => $value) {
                $change = max($change, abs($value - $beta[$j]));
            }
            $beta = $next;
            if ($change < self::TOLERANCE) {
                break;
            }
        }

        return $beta;
    }

    /**
     * Gaussian elimination with partial pivoting.
     *
     * @param array<int, array<int, float>> $a
     * @param array<int, float> $b
     *
     * @return array<int, float>|null
     */
    private static function solve(array $a, array $b): ?array
    {
        $size = count($b);
        for ($col = 0; $col < $size; $col++) {
            $pivot = $col;
            for ($r = $col + 1; $r < $size; $r++) {
                if (abs($a[$r][$col]) > abs($a[$pivot][$col])) {
                    $pivot = $r;
                }
            }
            if (abs($a[$pivot][$col]) < 1e-12) {
                return null;
            }
            [$a[$col], $a[$pivot]] = [$a[$pivot], $a[$col]];
            [$b[$col], $b[$pivot]] = [$b[$pivot], $b[$col]];
            for ($r = $col + 1; $r < $size; $r++) {
                $factor = $a[$r][$col] / $a[$col][$col];
                for ($c = $col; $c < $size; $c++) {
                    $a[$r][$c] -= $factor * $a[$col][$c];
                }
                $b[$r] -= $factor * $b[$col];
            }
        }

        $solution = array_fill(0, $size, 0.0);
        for ($i = $size - 1; $i >= 0; $i--) {
            $sum = $b[$i];
            for ($j = $i + 1; $j < $size; $j++) {
                $sum -= $a[$i][$j] * $solution[$j];
            }
            $solution[$i] = $sum / $a[$i][$i];
        }

        return $solution;
    }
}
