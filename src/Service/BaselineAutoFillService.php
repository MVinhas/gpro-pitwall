<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PilotRepository;
use App\Repository\RaceTelemetryRepository;
use Throwable;

/**
 * Grows the Division Baseline from the anonymous corpus.
 *
 * A driver that qualified in the top three AND finished in the top three is, by
 * definition, at the sharp end of its division — exactly the sample the
 * baseline is meant to describe. Every such race is promoted into `pilots`
 * once, under the division it was run in.
 *
 * Two properties matter:
 *
 *  - **The division OA cap still applies.** Promoted stats go through the same
 *    PilotCalculatorService::adjustPilotStats() a hand-entered pilot does, so a
 *    Rookie driver whose real attributes exceed the Rookie cap is dwarfed the
 *    same way. The baseline stays internally consistent regardless of where a
 *    row came from.
 *  - **It is idempotent.** Promotion is keyed on the source telemetry row, so
 *    running it on every sync can never duplicate a pilot.
 *
 * Nothing user-identifying is involved: the corpus has no user column, so a
 * promoted pilot cannot be traced to the manager whose sync produced it.
 */
final readonly class BaselineAutoFillService
{
    /** Corpus column -> pilots column. */
    private const array ATTRIBUTE_MAP = [
        'driver_con' => 'concentration',
        'driver_tal' => 'talent',
        'driver_agg' => 'aggressiveness',
        'driver_exp' => 'experience',
        'driver_tei' => 'technical_insight',
        'driver_sta' => 'stamina',
        'driver_cha' => 'charisma',
        'driver_mot' => 'motivation',
        'driver_wei' => 'weight',
        'driver_age' => 'age',
    ];

    /** Divisions a promoted row may land in. */
    private const array DIVISIONS = ['Rookie', 'Amateur', 'Pro', 'Master', 'Elite'];

    public function __construct(
        private RaceTelemetryRepository $corpus,
        private PilotRepository $pilots,
        private PilotCalculatorService $calculator,
    ) {
    }

    /**
     * Promote every eligible race not already in the baseline.
     *
     * @param int $limit safety valve so one sync can never spend unbounded time
     * @return int number of pilots added
     */
    public function run(int $limit = 200): int
    {
        try {
            $candidates = $this->corpus->podiumDoublesAwaitingBaseline($limit);
        } catch (Throwable $e) {
            error_log('[BaselineAutoFill] read failed: ' . $e::class . ': ' . $e->getMessage());
            return 0;
        }

        $added = 0;
        foreach ($candidates as $row) {
            $division = $this->divisionFor($row);
            if ($division === null) {
                continue;
            }

            $stats = $this->statsFrom($row);
            if ($stats === null) {
                continue;
            }

            try {
                // The same cap treatment a hand-entered pilot gets.
                $adjusted = $this->calculator->adjustPilotStats($stats, $division);

                $this->pilots->addPilot(array_merge($adjusted, [
                    'division' => $division,
                    'source_telemetry_id' => (int) $row['id'],
                ]));
                $added++;
            } catch (Throwable $e) {
                // A single bad row must not stop the rest, nor break the sync
                // that called this.
                error_log('[BaselineAutoFill] promote failed: ' . $e::class . ': ' . $e->getMessage());
            }
        }

        return $added;
    }

    /** @param array<string, mixed> $row */
    private function divisionFor(array $row): ?string
    {
        $level = $row['level'] ?? null;

        return is_string($level) && in_array($level, self::DIVISIONS, true) ? $level : null;
    }

    /**
     * Every attribute must be present: pilots columns are NOT NULL, and a
     * partially-filled pilot would quietly skew the division's averages.
     *
     * @param array<string, mixed> $row
     * @return array<string, int>|null
     */
    private function statsFrom(array $row): ?array
    {
        $stats = [];
        foreach (self::ATTRIBUTE_MAP as $source => $target) {
            $value = $row[$source] ?? null;
            if ($value === null || !is_numeric($value)) {
                return null;
            }
            $stats[$target] = (int) $value;
        }

        return $stats;
    }
}
