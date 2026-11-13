<?php

declare(strict_types=1);

namespace App\Engineer;

/**
 * What overtake and defend risks are worth in places, learned from race results.
 *
 * One ridge regression on places gained (grid slot minus finish). Every driver is
 * compared with themself: within one driver's season the averages are taken out, so
 * a manager with a stronger car who also happens to like high risks does not pass
 * for proof that high risks work. Mistakes need no model of their own: a mistake
 * costs time, time costs places, and places are what is fitted.
 *
 * The curve is a hill in each dial (the risk and its square). What may bend it
 * (weather, a track that is hard to pass on, low grip, a midfield start, the
 * driver's aggressiveness, experience and concentration) is a candidate group: it
 * joins the model only when it predicts races it has not seen better than the plain
 * hill, in nearly every one of 20 different held-out splits. The same test decides
 * whether the hill itself is trusted. Groups are tested again on every refit, so a
 * real effect earns its place as races accumulate, with no code change.
 *
 * The advice is the knee, not the peak: the lowest total risk that keeps 90 % of
 * the gain between the lowest risks seen and the best pair. The search stays inside
 * the risks managers have actually run (5th to 95th percentile).
 *
 * `.local/tools/risk_curve_reference.py` is the reference implementation; both must
 * give the same answer on the same corpus.
 *
 * @phpstan-type Row array{
 *     driver: int, season: int, grid: int, gained: int, overtake: int, defend: int, wet: bool,
 *     track: int, grip: int, agg: int, exp: int, con: int
 * }
 * @phpstan-type Context array{wet: bool, track: int, grip: int, agg: int, exp: int, con: int, grid: ?int}
 * @phpstan-type Cached array{
 *     rows: int, drivers: int, ready: bool, core_wins: int, group_wins: array<string, int>,
 *     admitted: list<string>, columns: list<string>, beta: list<float>,
 *     range: array{int, int, int, int}, usual: array{int, int}, mid_share: float
 * }
 */
final readonly class RiskCurve
{
    public const int MIN_ROWS = 300;
    public const int SPLITS = 20;

    private const int CAP = 70;
    private const int MIN_RISK = 5;
    private const int STEP = 5;
    private const int MIN_GROUP = 3;
    private const float LAMBDA = 3.0;
    private const int FOLDS = 5;
    private const int CORE_WINS = 14;
    private const int GROUP_WINS = 18;
    private const float KNEE = 0.9;
    private const int HARD_TRACK = 3;
    private const int LOW_GRIP = 1;
    private const int MIDFIELD_FROM = 6;
    private const int MIDFIELD_TO = 25;
    private const int UNKNOWN_GRID = 10;

    private const array BASE = ['grid', 'track', 'grip', 'wet'];
    private const array DIAL = ['o', 'o2', 'd', 'd2'];
    private const array GROUPS = [
        'weather' => ['o_wet', 'd_wet'],
        'hard_track' => ['o_hard', 'd_hard'],
        'low_grip' => ['o_low', 'd_low'],
        'midfield' => ['o_mid', 'd_mid'],
        'aggressiveness' => ['o_agg', 'd_agg'],
        'experience' => ['o_exp', 'd_exp'],
        'concentration' => ['o_con', 'd_con'],
    ];

    /** The order features() writes its values in. */
    private const array COLUMNS = [
        'grid', 'track', 'grip', 'wet',
        'o', 'o2', 'd', 'd2',
        'o_wet', 'd_wet', 'o_hard', 'd_hard', 'o_low', 'd_low', 'o_mid', 'd_mid',
        'o_agg', 'd_agg', 'o_exp', 'd_exp', 'o_con', 'd_con',
    ];

    /**
     * @param array<string, int> $groupWins splits (of SPLITS) each candidate group beat the plain hill in
     * @param list<string> $admitted candidate groups in the model
     * @param list<string> $columns the model's columns, in the order of $beta
     * @param list<float> $beta
     * @param array{int, int, int, int} $range overtake low/high, defend low/high
     * @param array{int, int} $usual the overtake and defend risks managers run most (the medians)
     */
    private function __construct(
        private int $rows,
        private int $drivers,
        private bool $ready,
        private int $coreWins,
        private array $groupWins,
        private array $admitted,
        private array $columns,
        private array $beta,
        private array $range,
        private array $usual,
        private float $midShare,
    ) {
    }

    /**
     * @param list<Row> $rows
     */
    public static function fit(array $rows): self
    {
        $seasons = [];
        foreach ($rows as $row) {
            $seasons[$row['driver'] . ':' . $row['season']][] = $row;
        }

        $width = count(self::COLUMNS);
        /** @var array<int, RidgeGram> $byDriver */
        $byDriver = [];
        $used = [];
        foreach ($seasons as $group) {
            $n = count($group);
            if ($n < self::MIN_GROUP) {
                continue;
            }
            $xs = [];
            $sum = array_fill(0, $width, 0.0);
            $ySum = 0;
            foreach ($group as $row) {
                $x = self::features($row, $row['overtake'], $row['defend'], null);
                $xs[] = $x;
                foreach ($x as $i => $value) {
                    $sum[$i] += $value;
                }
                $ySum += $row['gained'];
            }
            $driver = $group[0]['driver'];
            $byDriver[$driver] ??= new RidgeGram($width);
            foreach ($group as $k => $row) {
                $centred = [];
                foreach ($xs[$k] as $i => $value) {
                    $centred[] = $value - $sum[$i] / $n;
                }
                $byDriver[$driver]->add($centred, $row['gained'] - $ySum / $n);
                $used[] = $row;
            }
        }

        $drivers = count($byDriver);
        if (count($used) < self::MIN_ROWS) {
            return self::notReady(count($used), $drivers, 0, []);
        }

        $full = new RidgeGram($width);
        foreach ($byDriver as $gram) {
            $full = $full->plus($gram);
        }

        $base = self::indexes(self::BASE);
        $core = self::indexes([...self::BASE, ...self::DIAL]);
        $withGroup = [];
        $groupWins = [];
        foreach (self::GROUPS as $name => $columns) {
            $withGroup[$name] = [...$core, ...self::indexes($columns)];
            $groupWins[$name] = 0;
        }

        $coreWins = 0;
        for ($split = 0; $split < self::SPLITS; $split++) {
            $folds = [];
            for ($k = 0; $k < self::FOLDS; $k++) {
                $folds[] = new RidgeGram($width);
            }
            foreach ($byDriver as $driver => $gram) {
                $k = crc32($driver . ':' . $split) % self::FOLDS;
                $folds[$k] = $folds[$k]->plus($gram);
            }

            $errorBase = self::heldOut($full, $folds, $base);
            $errorCore = self::heldOut($full, $folds, $core);
            if ($errorBase === null || $errorCore === null) {
                continue;
            }
            if ($errorCore < $errorBase) {
                $coreWins++;
            }
            foreach ($withGroup as $name => $columns) {
                $error = self::heldOut($full, $folds, $columns);
                if ($error !== null && $error < $errorCore) {
                    $groupWins[$name]++;
                }
            }
        }

        if ($coreWins < self::CORE_WINS) {
            return self::notReady(count($used), $drivers, $coreWins, $groupWins);
        }

        $admitted = [];
        $names = [...self::BASE, ...self::DIAL];
        foreach (self::GROUPS as $name => $columns) {
            if ($groupWins[$name] >= self::GROUP_WINS) {
                $admitted[] = $name;
                $names = [...$names, ...$columns];
            }
        }
        $beta = $full->solve(self::indexes($names), self::LAMBDA);
        if ($beta === null) {
            return self::notReady(count($used), $drivers, $coreWins, $groupWins);
        }

        $overtake = [];
        $defend = [];
        $midfield = 0;
        foreach ($used as $row) {
            $overtake[] = min(self::CAP, $row['overtake']);
            $defend[] = min(self::CAP, $row['defend']);
            $midfield += self::isMidfield($row['grid']) ? 1 : 0;
        }
        [$oLo, $oHi] = self::span($overtake);
        [$dLo, $dHi] = self::span($defend);

        return new self(
            count($used),
            $drivers,
            true,
            $coreWins,
            $groupWins,
            $admitted,
            $names,
            $beta,
            [$oLo, $oHi, $dLo, $dHi],
            [self::snap(self::median($overtake), $oLo, $oHi), self::snap(self::median($defend), $dLo, $dHi)],
            $midfield / count($used),
        );
    }

    public static function fallback(): self
    {
        return self::notReady(0, 0, 0, []);
    }

    /** Enough races, and the dials predicted unseen races better than ignoring them. */
    public function ready(): bool
    {
        return $this->ready;
    }

    /** Races the curve was fitted on: those of drivers with at least three races in a season. */
    public function rows(): int
    {
        return $this->rows;
    }

    public function drivers(): int
    {
        return $this->drivers;
    }

    public function coreWins(): int
    {
        return $this->coreWins;
    }

    /**
     * @return array<string, int>
     */
    public function groupWins(): array
    {
        return $this->groupWins;
    }

    /**
     * @return list<string>
     */
    public function admitted(): array
    {
        return $this->admitted;
    }

    /**
     * @return array{int, int, int, int}
     */
    public function range(): array
    {
        return $this->range;
    }

    /**
     * @return array{int, int}
     */
    public function usual(): array
    {
        return $this->usual;
    }

    public function midShare(): float
    {
        return $this->midShare;
    }

    /**
     * @return array<string, float>
     */
    public function coefficients(): array
    {
        return array_combine($this->columns, $this->beta);
    }

    /**
     * The lowest total risk that keeps 90 % of what the best pair gains over the
     * lowest risks seen, and what it is worth against the pair managers usually run.
     *
     * @param Context $context
     *
     * @return array{overtake: int, defend: int, places: float}|null null until the curve is ready
     */
    public function recommend(array $context): ?array
    {
        if (!$this->ready) {
            return null;
        }

        [$oLo, $oHi, $dLo, $dHi] = $this->range;
        $floor = $this->value($context, $oLo, $dLo);
        $grid = [];
        $best = $floor;
        for ($o = $oLo; $o <= $oHi; $o += self::STEP) {
            for ($d = $dLo; $d <= $dHi; $d += self::STEP) {
                $value = $this->value($context, $o, $d);
                $grid[] = [$value, $o, $d];
                $best = max($best, $value);
            }
        }

        $gain = $best - $floor;
        $pick = [$floor, $oLo, $dLo];
        if ($gain > 1e-9) {
            $pick = null;
            foreach ($grid as $candidate) {
                if ($candidate[0] - $floor < self::KNEE * $gain - 1e-12) {
                    continue;
                }
                if ($pick === null || self::lowerRisk($candidate, $pick)) {
                    $pick = $candidate;
                }
            }
            $pick ??= [$floor, $oLo, $dLo];
        }

        return [
            'overtake' => $pick[1],
            'defend' => $pick[2],
            'places' => $pick[0] - $this->value($context, $this->usual[0], $this->usual[1]),
        ];
    }

    /**
     * The admitted groups that change the answer for this race, by name.
     *
     * @param Context $context
     *
     * @return list<string>
     */
    public function factors(array $context): array
    {
        $out = [];
        foreach ($this->admitted as $name) {
            $inPlay = match ($name) {
                'weather' => $context['wet'],
                'hard_track' => $context['track'] >= self::HARD_TRACK,
                'low_grip' => $context['grip'] <= self::LOW_GRIP,
                'midfield' => $context['grid'] !== null && self::isMidfield($context['grid']),
                default => true,
            };
            if ($inPlay) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * @return Cached
     */
    public function toArray(): array
    {
        return [
            'rows' => $this->rows,
            'drivers' => $this->drivers,
            'ready' => $this->ready,
            'core_wins' => $this->coreWins,
            'group_wins' => $this->groupWins,
            'admitted' => $this->admitted,
            'columns' => $this->columns,
            'beta' => $this->beta,
            'range' => $this->range,
            'usual' => $this->usual,
            'mid_share' => $this->midShare,
        ];
    }

    /**
     * @param Cached $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['rows'],
            $data['drivers'],
            $data['ready'],
            $data['core_wins'],
            $data['group_wins'],
            $data['admitted'],
            $data['columns'],
            $data['beta'],
            $data['range'],
            $data['usual'],
            $data['mid_share'],
        );
    }

    /**
     * @param array<string, int> $groupWins
     */
    private static function notReady(int $rows, int $drivers, int $coreWins, array $groupWins): self
    {
        $low = self::MIN_RISK;

        $range = [$low, $low, $low, $low];

        return new self($rows, $drivers, false, $coreWins, $groupWins, [], [], [], $range, [$low, $low], 0.0);
    }

    /**
     * Places the pair is worth on an arbitrary zero: only the difference between two pairs means anything.
     * The grid, track, grip and weather columns are left out, because they do not depend on the dials.
     *
     * @param Context $context
     */
    private function value(array $context, int $overtake, int $defend): float
    {
        $mid = $context['grid'] === null ? $this->midShare : null;
        $row = ['grid' => $context['grid'] ?? self::UNKNOWN_GRID] + $context;
        $x = array_combine(self::COLUMNS, self::features($row, $overtake, $defend, $mid));

        $value = 0.0;
        foreach ($this->columns as $i => $column) {
            if (!in_array($column, self::BASE, true)) {
                $value += $this->beta[$i] * $x[$column];
            }
        }

        return $value;
    }

    /**
     * One value per COLUMNS entry, in that order.
     *
     * @param array{wet: bool, track: int, grip: int, agg: int, exp: int, con: int, grid: int, ...} $row
     * @param ?float $mid overrides the midfield flag, for a race whose grid slot is not known
     *
     * @return list<float>
     */
    private static function features(array $row, int $overtake, int $defend, ?float $mid): array
    {
        $o = min(self::CAP, $overtake) / 100;
        $d = min(self::CAP, $defend) / 100;
        $wet = $row['wet'] ? 1.0 : 0.0;
        $hard = $row['track'] >= self::HARD_TRACK ? 1.0 : 0.0;
        $low = $row['grip'] <= self::LOW_GRIP ? 1.0 : 0.0;
        $mid ??= self::isMidfield($row['grid']) ? 1.0 : 0.0;
        $agg = $row['agg'] / 250;
        $exp = $row['exp'] / 250;
        $con = $row['con'] / 250;

        return [
            $row['grid'] / 10, $row['track'] / 4, $row['grip'] / 4, $wet,
            $o, $o * $o, $d, $d * $d,
            $o * $wet, $d * $wet, $o * $hard, $d * $hard, $o * $low, $d * $low, $o * $mid, $d * $mid,
            $o * $agg, $d * $agg, $o * $exp, $d * $exp, $o * $con, $d * $con,
        ];
    }

    /**
     * The summed squared error on each fold of a model fitted without that fold.
     *
     * @param array<int, RidgeGram> $folds
     * @param list<int> $columns
     */
    private static function heldOut(RidgeGram $full, array $folds, array $columns): ?float
    {
        $total = 0.0;
        foreach ($folds as $fold) {
            if ($fold->rows() === 0) {
                continue;
            }
            $beta = $full->minus($fold)->solve($columns, self::LAMBDA);
            if ($beta === null) {
                return null;
            }
            $total += $fold->sse($columns, $beta);
        }

        return $total;
    }

    /**
     * @param list<string> $names
     *
     * @return list<int>
     */
    private static function indexes(array $names): array
    {
        $index = array_flip(self::COLUMNS);

        return array_map(static fn (string $name): int => $index[$name], $names);
    }

    /**
     * Lower total risk first, then the better value, then the lower overtake risk.
     *
     * @param array{float, int, int} $a
     * @param array{float, int, int} $b
     */
    private static function lowerRisk(array $a, array $b): bool
    {
        return [$a[1] + $a[2], -$a[0], $a[1]] < [$b[1] + $b[2], -$b[0], $b[1]];
    }

    private static function isMidfield(int $grid): bool
    {
        return $grid >= self::MIDFIELD_FROM && $grid <= self::MIDFIELD_TO;
    }

    /**
     * The risks the corpus has seen, 5th to 95th percentile, on the 5-point grid.
     *
     * @param non-empty-list<int> $values
     *
     * @return array{int, int}
     */
    private static function span(array $values): array
    {
        sort($values);
        $last = count($values) - 1;
        $lo = (int) (ceil($values[(int) floor(0.05 * $last)] / self::STEP) * self::STEP);
        $hi = (int) (floor($values[(int) ceil(0.95 * $last)] / self::STEP) * self::STEP);
        $lo = max(self::MIN_RISK, min(self::CAP, $lo));
        $hi = max($lo, min(self::CAP, $hi));

        return [$lo, $hi];
    }

    /**
     * @param non-empty-list<int> $values
     */
    private static function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    private static function snap(float $value, int $lo, int $hi): int
    {
        return max($lo, min($hi, (int) (floor($value / self::STEP + 0.5) * self::STEP)));
    }
}
