<?php

declare(strict_types=1);

namespace App\Engineer;

/**
 * What overtake and defend risks do in a race, learned from the corpus, and the
 * pair of risks that earns the most seconds once the mistakes they cause are paid for.
 *
 * Three ridge-Poisson models, each a count per lap:
 *  - passes made, driven by the overtake risk;
 *  - passes suffered, driven by the defend risk;
 *  - driver mistakes (needs the race replay), driven by both risks, the clear
 *    track risk, rain, traffic and where the car runs.
 * Each risk enters with its square, so a model can say "this helps up to a point".
 * Driver concentration, experience and aggressiveness enter all three, and talent
 * in the wet. A place is worth the seconds the caller says (PlaceGap); a mistake is
 * worth the seconds the replay says it cost. The pair of risks with the best net is
 * searched only inside the range the corpus has actually seen for that weather, so
 * the model never extrapolates.
 *
 * @phpstan-type Situation array{
 *     level: string, wet: bool, laps: int, ctr: int, con: int, tal: int, agg: int, exp: int,
 *     track: int, grid: int|null
 * }
 * @phpstan-type Driver array{
 *     level: string, ctr: int, con: int, tal: int, agg: int, exp: int, track: int
 * }
 * @phpstan-type Replay array{laps: int, mistakes: int, mistake_ms: int, traffic_share: float, avg_pos: float}
 * @phpstan-type Row array{
 *     level: string, wet: float, laps: int, ot_risk: int, def_risk: int, ctr: int, con: int, tal: int,
 *     agg: int, exp: int, track: int, grid: int|null, overtakes: int, passed: int, replay: Replay|null
 * }
 * @phpstan-type Cached array{
 *     races: int, replay_races: int, typical_wet: float,
 *     overtakes: array{mean: list<float>, scale: list<float>, beta: list<float>}|null,
 *     passed: array{mean: list<float>, scale: list<float>, beta: list<float>}|null,
 *     mistakes: array{mean: list<float>, scale: list<float>, beta: list<float>}|null,
 *     range: array<string, array{int, int, int, int}>,
 *     seconds_per_mistake: array<string, float>,
 *     traffic: array<string, array{float, float}>
 * }
 */
final readonly class RiskModel
{
    public const int MIN_RACES = 60;
    public const int MIN_REPLAY = 40;

    private const int BLEND_HALF = 60;
    private const float LAMBDA = 3.0;
    private const int STEP = 5;
    private const int MIN_RISK = 5;
    private const int MAX_RISK = 70;
    private const int MIN_BUCKET_ROWS = 15;
    private const int MIN_MISTAKES_FOR_COST = 10;
    private const float DEFAULT_SECONDS_PER_MISTAKE = 5.0;
    private const float DEFAULT_TRAFFIC = 0.35;
    private const float DEFAULT_WET = 0.3;
    private const int DEFAULT_GRID = 10;
    private const array LEVELS = ['Rookie' => 0, 'Amateur' => 1, 'Pro' => 2, 'Master' => 3, 'Elite' => 4];

    /**
     * @param array<string, array{int, int, int, int}> $range overtake low/high, defend low/high, by 'wet' | 'dry'
     * @param array<string, float> $secondsPerMistake by 'wet' | 'dry' | 'any'
     * @param array<string, array{float, float}> $traffic traffic share and average position, keyed
     *   'level|bucket' and '*|bucket'
     */
    private function __construct(
        private int $races,
        private int $replayRaces,
        private float $typicalWet,
        private ?PoissonRidge $overtakes,
        private ?PoissonRidge $passed,
        private ?PoissonRidge $mistakes,
        private array $range,
        private array $secondsPerMistake,
        private array $traffic,
    ) {
    }

    /**
     * @param list<Row> $rows
     */
    public static function fit(array $rows): self
    {
        $usable = array_values(array_filter($rows, static fn (array $r): bool => $r['laps'] > 0));
        $races = count($usable);
        $replayRows = array_values(array_filter(
            $usable,
            static fn (array $r): bool => $r['replay'] !== null && $r['replay']['laps'] > 0,
        ));

        $overtakes = $passed = null;
        if ($races >= self::MIN_RACES) {
            $overtakes = self::fitCounts(
                $usable,
                static fn (array $r): array => self::xOvertake($r, $r['wet'], $r['ot_risk'], $r['grid']),
                static fn (array $r): int => $r['overtakes'],
            );
            $passed = self::fitCounts(
                $usable,
                static fn (array $r): array => self::xPassed($r, $r['wet'], $r['def_risk'], $r['grid']),
                static fn (array $r): int => $r['passed'],
            );
        }

        $mistakes = null;
        if (count($replayRows) >= self::MIN_REPLAY) {
            $mistakes = self::fitCounts(
                $replayRows,
                static fn (array $r): array => self::xMistakes(
                    $r,
                    $r['wet'],
                    $r['ot_risk'],
                    $r['def_risk'],
                    $r['replay']['traffic_share'] ?? self::DEFAULT_TRAFFIC,
                    $r['replay']['avg_pos'] ?? (float) self::DEFAULT_GRID,
                ),
                static fn (array $r): int => $r['replay']['mistakes'] ?? 0,
                static fn (array $r): int => $r['replay']['laps'] ?? $r['laps'],
            );
        }

        return new self(
            $races,
            count($replayRows),
            self::typicalWetOf($usable),
            $overtakes,
            $passed,
            $mistakes,
            self::ranges($usable),
            self::secondsPerMistakeOf($replayRows),
            self::trafficOf($replayRows),
        );
    }

    public static function fallback(): self
    {
        return new self(0, 0, self::DEFAULT_WET, null, null, null, [], [], []);
    }

    /** Enough races, with replays, for the three models to exist. */
    public function ready(): bool
    {
        return $this->overtakes !== null && $this->passed !== null && $this->mistakes !== null;
    }

    public function races(): int
    {
        return $this->races;
    }

    public function replayRaces(): int
    {
        return $this->replayRaces;
    }

    /** The share of laps a race counted as wet usually had in the corpus: what a wet forecast is read as. */
    public function typicalWet(): float
    {
        return $this->typicalWet;
    }

    /**
     * How far to trust the learned pair over the heuristic: half at 60 replay
     * races, rising with every race, never all the way.
     */
    public function weight(): float
    {
        return $this->ready() ? $this->replayRaces / ($this->replayRaces + self::BLEND_HALF) : 0.0;
    }

    /**
     * What a pair of risks is expected to do over the race.
     *
     * @param Situation $situation
     *
     * @return array{places: float, passed: float, mistakes: float}|null null until the model is ready
     */
    public function forecast(array $situation, int $overtakeRisk, int $defendRisk): ?array
    {
        if ($this->overtakes === null || $this->passed === null || $this->mistakes === null) {
            return null;
        }

        $offset = log(max(1, $situation['laps']));
        $wet = $situation['wet'] ? $this->typicalWet : 0.0;
        [$traffic, $avgPos] = $this->expectedTraffic($situation);

        return [
            'places' => $this->overtakes->predict(
                self::xOvertake($situation, $wet, $overtakeRisk, $situation['grid']),
                $offset,
            ),
            'passed' => $this->passed->predict(
                self::xPassed($situation, $wet, $defendRisk, $situation['grid']),
                $offset,
            ),
            'mistakes' => $this->mistakes->predict(
                self::xMistakes($situation, $wet, $overtakeRisk, $defendRisk, $traffic, $avgPos),
                $offset,
            ),
        ];
    }

    /**
     * The overtake and defend risks with the best net seconds: places won
     * minus places lost, at the caller's price per place, minus the seconds
     * the mistakes cost. Ties go to the lower risk.
     *
     * @param Situation $situation
     *
     * @return array{overtake: int, defend: int, net: float}|null null until the model is ready
     */
    public function recommend(array $situation, float $secondsPerPlace): ?array
    {
        if (!$this->ready()) {
            return null;
        }

        $bucket = $situation['wet'] ? 'wet' : 'dry';
        $full = [self::MIN_RISK, self::MAX_RISK, self::MIN_RISK, self::MAX_RISK];
        [$oLo, $oHi, $dLo, $dHi] = $this->range[$bucket] ?? $full;
        $perMistake = $this->secondsPerMistake[$bucket]
            ?? $this->secondsPerMistake['any']
            ?? self::DEFAULT_SECONDS_PER_MISTAKE;

        $best = null;
        for ($ot = $oLo; $ot <= $oHi; $ot += self::STEP) {
            for ($def = $dLo; $def <= $dHi; $def += self::STEP) {
                $f = $this->forecast($situation, $ot, $def);
                if ($f === null) {
                    return null;
                }
                $net = $secondsPerPlace * ($f['places'] - $f['passed']) - $perMistake * $f['mistakes'];
                if ($best === null || $net > $best['net']) {
                    $best = ['overtake' => $ot, 'defend' => $def, 'net' => $net];
                }
            }
        }

        return $best;
    }

    /**
     * @return Cached
     */
    public function toArray(): array
    {
        return [
            'races' => $this->races,
            'replay_races' => $this->replayRaces,
            'typical_wet' => $this->typicalWet,
            'overtakes' => $this->overtakes?->toArray(),
            'passed' => $this->passed?->toArray(),
            'mistakes' => $this->mistakes?->toArray(),
            'range' => $this->range,
            'seconds_per_mistake' => $this->secondsPerMistake,
            'traffic' => $this->traffic,
        ];
    }

    /**
     * @param Cached $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['races'],
            $data['replay_races'],
            $data['typical_wet'],
            $data['overtakes'] === null ? null : PoissonRidge::fromArray($data['overtakes']),
            $data['passed'] === null ? null : PoissonRidge::fromArray($data['passed']),
            $data['mistakes'] === null ? null : PoissonRidge::fromArray($data['mistakes']),
            $data['range'],
            $data['seconds_per_mistake'],
            $data['traffic'],
        );
    }

    /**
     * @param list<Row> $rows
     * @param callable(Row): list<float> $features
     * @param callable(Row): int $count
     * @param (callable(Row): int)|null $exposure laps by default
     */
    private static function fitCounts(
        array $rows,
        callable $features,
        callable $count,
        ?callable $exposure = null,
    ): ?PoissonRidge {
        $x = [];
        $y = [];
        $offset = [];
        foreach ($rows as $row) {
            $x[] = $features($row);
            $y[] = max(0, $count($row));
            $offset[] = log(max(1, $exposure === null ? $row['laps'] : $exposure($row)));
        }

        return PoissonRidge::fit($x, $y, $offset, self::LAMBDA);
    }

    /**
     * @param Driver $s
     *
     * @return list<float>
     */
    private static function xOvertake(array $s, float $wet, int $risk, ?int $grid): array
    {
        return [...self::risk($risk, $wet), ...self::driver($s), ...self::context($s, $wet, $grid)];
    }

    /**
     * @param Driver $s
     *
     * @return list<float>
     */
    private static function xPassed(array $s, float $wet, int $risk, ?int $grid): array
    {
        return [...self::risk($risk, $wet), ...self::driver($s), ...self::context($s, $wet, $grid)];
    }

    /**
     * @param Driver $s
     *
     * @return list<float>
     */
    private static function xMistakes(
        array $s,
        float $wet,
        int $overtake,
        int $defend,
        float $traffic,
        float $avgPos,
    ): array {
        return [
            $overtake / 100, ($overtake / 100) ** 2, $defend / 100, ($defend / 100) ** 2,
            $overtake / 100 * $wet, $defend / 100 * $wet,
            $s['ctr'] / 100,
            ...self::driver($s),
            $wet, $wet * $s['tal'] / 250,
            $traffic, $avgPos / 10,
        ];
    }

    /**
     * @return list<float>
     */
    private static function risk(int $risk, float $wet): array
    {
        return [$risk / 100, ($risk / 100) ** 2, $risk / 100 * $wet];
    }

    /**
     * @param Driver $s
     *
     * @return list<float>
     */
    private static function driver(array $s): array
    {
        return [$s['con'] / 250, $s['exp'] / 250, $s['agg'] / 250];
    }

    /**
     * @param Driver $s
     *
     * @return list<float>
     */
    private static function context(array $s, float $wet, ?int $grid): array
    {
        return [
            $wet, $wet * $s['tal'] / 250, $s['track'] / 4,
            (self::LEVELS[$s['level']] ?? 1) / 4, ($grid ?? self::DEFAULT_GRID) / 10,
        ];
    }

    /**
     * @param Situation $situation
     *
     * @return array{float, float}
     */
    private function expectedTraffic(array $situation): array
    {
        $bucket = self::gridBucket($situation['grid']);

        return $this->traffic[$situation['level'] . '|' . $bucket]
            ?? $this->traffic['*|' . $bucket]
            ?? [self::DEFAULT_TRAFFIC, (float) ($situation['grid'] ?? self::DEFAULT_GRID)];
    }

    private static function gridBucket(?int $grid): int
    {
        return match (true) {
            $grid === null => 1,
            $grid <= 3 => 0,
            $grid <= 10 => 1,
            default => 2,
        };
    }

    /**
     * The risks the corpus has seen, 5th to 95th percentile, per weather.
     *
     * @param list<Row> $rows
     *
     * @return array<string, array{int, int, int, int}>
     */
    private static function ranges(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $out = [];
        foreach (['wet' => true, 'dry' => false] as $name => $isWet) {
            $bucket = array_values(array_filter($rows, static fn (array $r): bool => ($r['wet'] > 0.0) === $isWet));
            $use = count($bucket) >= self::MIN_BUCKET_ROWS ? $bucket : $rows;
            $overtake = array_map(static fn (array $r): int => $r['ot_risk'], $use);
            $defend = array_map(static fn (array $r): int => $r['def_risk'], $use);
            $out[$name] = [
                ...self::span($overtake),
                ...self::span($defend),
            ];
        }

        return $out;
    }

    /**
     * @param list<int> $values
     *
     * @return array{int, int}
     */
    private static function span(array $values): array
    {
        sort($values);
        $last = count($values) - 1;
        $lo = (int) (ceil($values[(int) floor(0.05 * $last)] / self::STEP) * self::STEP);
        $hi = (int) (floor($values[(int) ceil(0.95 * $last)] / self::STEP) * self::STEP);
        $lo = max(self::MIN_RISK, min(self::MAX_RISK, $lo));
        $hi = max($lo, min(self::MAX_RISK, $hi));

        return [$lo, $hi];
    }

    /**
     * Seconds one mistake costs, from the replays.
     *
     * @param list<Row> $replayRows
     *
     * @return array<string, float>
     */
    private static function secondsPerMistakeOf(array $replayRows): array
    {
        $sums = ['wet' => [0, 0], 'dry' => [0, 0], 'any' => [0, 0]];
        foreach ($replayRows as $row) {
            $replay = $row['replay'];
            if ($replay === null) {
                continue;
            }
            foreach ([$row['wet'] > 0.0 ? 'wet' : 'dry', 'any'] as $key) {
                $sums[$key][0] += $replay['mistakes'];
                $sums[$key][1] += $replay['mistake_ms'];
            }
        }

        $out = [];
        foreach ($sums as $key => [$mistakes, $ms]) {
            if ($mistakes >= self::MIN_MISTAKES_FOR_COST) {
                $out[$key] = $ms / 1000 / $mistakes;
            }
        }

        return $out;
    }

    /**
     * Median traffic share and average position by division and grid slot.
     *
     * @param list<Row> $replayRows
     *
     * @return array<string, array{float, float}>
     */
    private static function trafficOf(array $replayRows): array
    {
        $groups = [];
        foreach ($replayRows as $row) {
            $replay = $row['replay'];
            if ($replay === null) {
                continue;
            }
            $bucket = self::gridBucket($row['grid']);
            foreach ([$row['level'] . '|' . $bucket, '*|' . $bucket] as $key) {
                $groups[$key][0][] = $replay['traffic_share'];
                $groups[$key][1][] = $replay['avg_pos'];
            }
        }

        $out = [];
        foreach ($groups as $key => [$traffic, $pos]) {
            $out[$key] = [self::median($traffic), self::median($pos)];
        }

        return $out;
    }

    /**
     * @param list<Row> $rows
     */
    private static function typicalWetOf(array $rows): float
    {
        $shares = array_values(array_filter(
            array_map(static fn (array $r): float => $r['wet'], $rows),
            static fn (float $share): bool => $share > 0.0,
        ));

        return $shares === [] ? self::DEFAULT_WET : self::median($shares);
    }

    /**
     * @param non-empty-list<float> $values
     */
    private static function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
