<?php

declare(strict_types=1);

namespace App\Service;

use App\Calibration\FuelCalibration;
use App\Calibration\LedgerCategories;
use App\Calibration\WearCalibration;
use App\Debrief\WeekendDiff;
use App\Repository\EconomyLedgerRepository;
use App\Repository\RaceHistoryRepository;
use App\Repository\StandingsRepository;
use App\Repository\TrackRepository;
use App\Repository\WeekendStateRepository;
use JsonException;

/**
 * Everything the stored archive knows about one race beyond the race itself:
 * the weekend the manager entered it with, the money that moved, where the
 * championship stood afterwards, and how far the wear and fuel models were off.
 * Each block is null when its source is missing, never an empty shell.
 *
 * @phpstan-import-type Change from WeekendDiff
 */
final readonly class RaceWeekendService
{
    public function __construct(
        private RaceHistoryRepository $history,
        private WeekendStateRepository $weekends,
        private StandingsRepository $standings,
        private EconomyLedgerRepository $ledger,
        private TrackRepository $tracks,
        private WearCalibration $wear,
        private FuelCalibration $fuel,
    ) {
    }

    /**
     * @return array{
     *     weekend: array<string, mixed>|null,
     *     economy: array<string, mixed>|null,
     *     standings: array<string, mixed>|null,
     *     calibration: array{wear: array<string, mixed>|null, fuel: array<string, mixed>|null}|null
     * }
     */
    public function forRace(int $userId, int $season, int $race): array
    {
        return [
            'weekend' => $this->weekend($userId, $season, $race),
            'economy' => $this->economy($userId, $season, $race),
            'standings' => $this->standingsAfter($userId, $season, $race),
            'calibration' => $this->calibration($userId, $season, $race),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function weekend(int $userId, int $season, int $race): ?array
    {
        $row = $this->weekends->find($userId, $season, $race);
        if ($row === null) {
            return null;
        }

        $previous = $this->weekends->previous($userId, $season, $race);
        $changes = $this->withNames(WeekendDiff::changes($previous, $row), $previous, $row);
        $changed = [];
        foreach ($changes as $change) {
            $changed[$change['group'] . '.' . $change['key']] = true;
        }

        return [
            'driver' => $this->decode($row['driver_json'] ?? null),
            'td' => $this->decode($row['td_json'] ?? null),
            'staff' => $this->decode($row['staff_json'] ?? null),
            'facilities' => $this->decode($row['facilities_json'] ?? null),
            'car' => $this->decode($row['car_json'] ?? null),
            'cash' => is_numeric($row['cash'] ?? null) ? (int) $row['cash'] : null,
            'changes' => $changes,
            'changed' => $changed,
        ];
    }

    /**
     * A hire is reported by id; a manager reads names, so the id rows carry the
     * previous and current person's name, falling back to the id.
     *
     * @param list<Change> $changes
     * @param array<string, mixed>|null $previous
     * @param array<string, mixed> $current
     *
     * @return list<Change>
     */
    private function withNames(array $changes, ?array $previous, array $current): array
    {
        foreach ($changes as $i => $change) {
            if ($change['key'] !== 'id' || !in_array($change['group'], ['driver', 'td'], true)) {
                continue;
            }
            $column = $change['group'] . '_json';
            $changes[$i]['from'] = $this->nameIn($previous[$column] ?? null) ?? $change['from'];
            $changes[$i]['to'] = $this->nameIn($current[$column] ?? null) ?? $change['to'];
        }

        return $changes;
    }

    private function nameIn(mixed $json): ?string
    {
        $name = $this->decode($json)['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function economy(int $userId, int $season, int $race): ?array
    {
        $lines = [];
        $income = 0;
        $costs = 0;
        foreach ($this->ledger->forRace($userId, $season, $race) as $row) {
            $description = (string) ($row['description'] ?? '');
            $amount = (int) ($row['amount'] ?? 0);
            $lines[] = [
                'description' => $description,
                'category' => LedgerCategories::categorise($description),
                'amount' => $amount,
                'balance_after' => is_numeric($row['balance_after'] ?? null) ? (int) $row['balance_after'] : null,
            ];
            if ($amount >= 0) {
                $income += $amount;
            } else {
                $costs -= $amount;
            }
        }

        return $lines === [] ? null : [
            'lines' => $lines,
            'income' => $income,
            'costs' => $costs,
            'net' => $income - $costs,
        ];
    }

    /**
     * Standings rows mean "entering race N", so the table after race R is the
     * row stored for R + 1. A season's last race has none and shows nothing.
     *
     * @return array<string, mixed>|null
     */
    private function standingsAfter(int $userId, int $season, int $race): ?array
    {
        $row = $this->standings->find($userId, $season, $race + 1);
        if ($row === null) {
            return null;
        }
        $decoded = $this->decode($row['managers_json'] ?? null);
        if ($decoded === null) {
            return null;
        }

        $ownPos = is_numeric($row['own_pos'] ?? null) ? (int) $row['own_pos'] : null;
        $managers = [];
        foreach ($decoded as $manager) {
            if (!is_array($manager) || !is_numeric($manager['pos'] ?? null)) {
                continue;
            }
            $pos = (int) $manager['pos'];
            $managers[] = [
                'pos' => $pos,
                'name' => is_string($manager['name'] ?? null) ? $manager['name'] : '—',
                'pts' => is_numeric($manager['pts'] ?? null) ? (int) $manager['pts'] : null,
                'own' => $ownPos !== null && $pos === $ownPos,
            ];
        }
        usort($managers, static fn (array $a, array $b): int => $a['pos'] <=> $b['pos']);

        return [
            'own_pos' => $ownPos,
            'own_pts' => is_numeric($row['own_pts'] ?? null) ? (int) $row['own_pts'] : null,
            'group_label' => is_string($row['group_label'] ?? null) ? $row['group_label'] : null,
            'managers' => $managers,
        ];
    }

    /**
     * @return array{wear: array<string, mixed>|null, fuel: array<string, mixed>|null}|null
     */
    private function calibration(int $userId, int $season, int $race): ?array
    {
        $row = $this->history->findRaw($userId, $season, $race);
        if ($row === null) {
            return null;
        }
        $track = $this->tracks->findByName((string) ($row['track_name'] ?? ''));
        $wear = $this->wear->forRace($row, $track);
        $fuel = $this->fuel->forRace($row, $track);

        return $wear === null && $fuel === null ? null : ['wear' => $wear, 'fuel' => $fuel];
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function decode(mixed $json): ?array
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            error_log('[Debrief] unreadable stored block: ' . $e::class);

            return null;
        }

        return is_array($data) ? $data : null;
    }
}
