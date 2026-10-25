<?php

declare(strict_types=1);

namespace App\Calibration;

use App\Service\CarWearService;

/**
 * @phpstan-type PartGap array{
 *     key: string, label: string, level: int,
 *     predicted: float, actual: int, gap: float, flagged: bool
 * }
 */
final readonly class WearCalibration
{
    public const float FLAG_POINTS = 3.0;
    public const int MIN_SAMPLE = 3;

    private const array PART_COLUMNS = [
        'chassis' => 'wear_chassis',
        'engine' => 'wear_engine',
        'FWing' => 'wear_fwing',
        'RWing' => 'wear_rwing',
        'underbody' => 'wear_underbody',
        'sidepods' => 'wear_sidepod',
        'cooling' => 'wear_cooling',
        'gear' => 'wear_gearbox',
        'brakes' => 'wear_brakes',
        'susp' => 'wear_suspension',
        'electronics' => 'wear_electronics',
    ];

    public function __construct(private CarWearService $wear)
    {
    }

    /**
     * @param array<string, mixed> $historyRow
     * @param array<string, mixed>|null $trackRow
     * @return array{wet: bool, parts: list<PartGap>, within: int, total: int}|null
     */
    public function forRace(array $historyRow, ?array $trackRow): ?array
    {
        $parts = StoredRace::decode($historyRow['parts_json'] ?? null);
        $driver = StoredRace::decode($historyRow['driver_json'] ?? null);
        $risk = $historyRow['clear_dry_risk'] ?? null;
        if ($trackRow === null || $parts === null || $driver === null || $risk === null) {
            return null;
        }

        $factor = $this->driverFactor($driver);
        if ($factor === null) {
            return null;
        }
        $risk = (int) $risk;

        $rows = [];
        $within = 0;
        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }
            $key = $part['key'] ?? null;
            $column = is_string($key) ? (self::PART_COLUMNS[$key] ?? null) : null;
            if (
                $column === null
                || ($part['level'] ?? null) === null
                || ($part['wear_gain'] ?? null) === null
                || ($trackRow[$column] ?? null) === null
            ) {
                continue;
            }

            $level = (int) $part['level'];
            $actual = (int) $part['wear_gain'];
            $base = (float) $trackRow[$column];
            $predicted = round($this->wear->raceWear($base, $level, $factor, $risk), 1);
            $gap = round($actual - $predicted, 1);
            $flagged = abs($gap) >= self::FLAG_POINTS;
            if (!$flagged) {
                $within++;
            }

            $rows[] = [
                'key' => $key,
                'label' => is_string($part['label'] ?? null) ? $part['label'] : $key,
                'level' => $level,
                'predicted' => $predicted,
                'actual' => $actual,
                'gap' => $gap,
                'flagged' => $flagged,
            ];
        }

        return [
            'wet' => (int) ($historyRow['was_wet'] ?? 0) === 1,
            'parts' => $rows,
            'within' => $within,
            'total' => count($rows),
        ];
    }

    /**
     * @param list<array<string, mixed>> $historyRows
     * @param array<string, array<string, mixed>> $trackRowsByName
     * @return array{parts: array<string, array{label: string, mean_gap: ?float, n: int}>, skipped: int, enough: bool}
     */
    public function bias(array $historyRows, array $trackRowsByName): array
    {
        $gaps = [];
        $labels = [];
        $skipped = 0;

        foreach ($historyRows as $row) {
            if ((int) ($row['was_wet'] ?? 0) === 1) {
                continue;
            }
            $race = $this->forRace($row, $trackRowsByName[(string) ($row['track_name'] ?? '')] ?? null);
            if ($race === null) {
                $skipped++;
                continue;
            }
            foreach ($race['parts'] as $part) {
                $gaps[$part['key']][] = $part['gap'];
                $labels[$part['key']] = $part['label'];
            }
        }

        $parts = [];
        $enough = false;
        foreach ($gaps as $key => $values) {
            $n = count($values);
            $enough = $enough || $n >= self::MIN_SAMPLE;
            $parts[$key] = [
                'label' => $labels[$key],
                'mean_gap' => $n >= self::MIN_SAMPLE ? round(array_sum($values) / $n, 2) : null,
                'n' => $n,
            ];
        }

        return ['parts' => $parts, 'skipped' => $skipped, 'enough' => $enough];
    }

    /**
     * @param iterable<array<string, mixed>> $telemetryRows
     * @param array<string, array<string, mixed>> $trackRowsByName
     * @return array{mean_gap: ?float, n: int}
     */
    public function corpusCheck(iterable $telemetryRows, array $trackRowsByName): array
    {
        $sum = 0.0;
        $n = 0;

        foreach ($telemetryRows as $row) {
            if ((int) ($row['was_wet'] ?? 0) === 1) {
                continue;
            }
            $trackRow = $trackRowsByName[(string) ($row['track_name'] ?? '')] ?? null;
            $driver = [
                'concentration' => $row['driver_con'] ?? null,
                'talent' => $row['driver_tal'] ?? null,
                'experience' => $row['driver_exp'] ?? null,
            ];
            if (
                $trackRow === null
                || in_array(null, $driver, true)
                || ($row['clear_dry_risk'] ?? null) === null
                || ($row['avg_part_level'] ?? null) === null
                || ($row['total_wear_gain'] ?? null) === null
            ) {
                continue;
            }

            $level = (int) round((float) $row['avg_part_level']);
            $risk = (int) $row['clear_dry_risk'];
            $factor = $this->wear->driverFactor($driver);
            $predicted = 0.0;
            foreach (self::PART_COLUMNS as $column) {
                if (($trackRow[$column] ?? null) === null) {
                    continue 2;
                }
                $predicted += $this->wear->raceWear((float) $trackRow[$column], $level, $factor, $risk);
            }

            $sum += (float) $row['total_wear_gain'] - $predicted;
            $n++;
        }

        return ['mean_gap' => $n > 0 ? round($sum / $n, 2) : null, 'n' => $n];
    }

    /**
     * @param array<string, mixed> $driver
     */
    private function driverFactor(array $driver): ?float
    {
        $values = [];
        foreach (['con' => 'concentration', 'tal' => 'talent', 'exp' => 'experience'] as $key => $name) {
            $value = StoredRace::attribute($driver, $key);
            if ($value === null) {
                return null;
            }
            $values[$name] = $value;
        }

        return $this->wear->driverFactor($values);
    }
}
