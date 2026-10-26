<?php

declare(strict_types=1);

namespace App\Calibration;

use App\Service\FuelModel;

final readonly class FuelCalibration
{
    public const float FLAG_PERCENT = 3.0;
    public const int MIN_SAMPLE = 3;
    private const int LAPS_PER_BOOST = 3;

    public function __construct(private FuelModel $fuel)
    {
    }

    /**
     * @param array<string, mixed> $historyRow
     * @param array<string, mixed>|null $trackRow
     * @return array{wet: bool, predicted: float, actual: float, gap_pct: float, flagged: bool}|null
     */
    public function forRace(array $historyRow, ?array $trackRow): ?array
    {
        $parts = StoredRace::decode($historyRow['parts_json'] ?? null);
        $driver = StoredRace::decode($historyRow['driver_json'] ?? null);
        $actual = $historyRow['fuel_per_km'] ?? null;
        $laps = (int) ($historyRow['laps_completed'] ?? 0);
        $dryLaps = (int) ($historyRow['dry_laps'] ?? 0) + (int) ($historyRow['mist_laps'] ?? 0);
        $rainLaps = (int) ($historyRow['rain_laps'] ?? 0);

        if (
            $trackRow === null || $parts === null || $driver === null
            || !is_numeric($actual) || (float) $actual <= 0.0
            || $laps <= 0 || $dryLaps + $rainLaps <= 0
        ) {
            return null;
        }

        $engine = StoredRace::partLevel($parts, 'engine');
        $electronics = StoredRace::partLevel($parts, 'electronics');
        $con = StoredRace::attribute($driver, 'con');
        $agr = StoredRace::attribute($driver, 'agr');
        $exp = StoredRace::attribute($driver, 'exp');
        $tei = StoredRace::attribute($driver, 'tei');
        if (in_array(null, [$engine, $electronics, $con, $agr, $exp, $tei], true)) {
            return null;
        }

        $rates = $this->fuel->perKm(
            $trackRow,
            ['concentration' => $con, 'aggressiveness' => $agr, 'experience' => $exp, 'technical_insight' => $tei],
            ['lvlEngine' => $engine, 'lvlElectronics' => $electronics],
        );

        $perKm = ($dryLaps * $rates['dry'] + $rainLaps * $rates['wet']) / ($dryLaps + $rainLaps);

        $boostStints = 0;
        foreach (['boost_lap_1', 'boost_lap_2', 'boost_lap_3'] as $column) {
            if (($historyRow[$column] ?? null) !== null) {
                $boostStints++;
            }
        }
        $wet = $rainLaps > $dryLaps;
        $distance = $laps * $this->fuel->lapLength($trackRow);
        $predicted = round(
            $perKm + $this->fuel->boostExtra($trackRow, $boostStints * self::LAPS_PER_BOOST, $wet) / $distance,
            3,
        );
        $actual = round((float) $actual, 3);
        $gapPct = round(round(($actual - $predicted) / $actual * 100, 6), 1);

        return [
            'wet' => $wet,
            'predicted' => $predicted,
            'actual' => $actual,
            'gap_pct' => $gapPct,
            'flagged' => abs($gapPct) > self::FLAG_PERCENT,
        ];
    }

    /**
     * @param list<array<string, mixed>> $historyRows
     * @param array<string, array<string, mixed>> $trackRowsByName
     * @return array{mean_gap_pct: ?float, n: int, skipped: int, enough: bool}
     */
    public function bias(array $historyRows, array $trackRowsByName): array
    {
        $gaps = [];
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
            $gaps[] = $race['gap_pct'];
        }

        $n = count($gaps);
        $enough = $n >= self::MIN_SAMPLE;

        return [
            'mean_gap_pct' => $enough ? round(array_sum($gaps) / $n, 1) : null,
            'n' => $n,
            'skipped' => $skipped,
            'enough' => $enough,
        ];
    }
}
