<?php

declare(strict_types=1);

namespace App\Archive;

use App\Repository\ApiArchiveRepository;
use App\Repository\EconomyLedgerRepository;
use App\Telemetry\PayloadReader;

/**
 * Projects the money movements one race's archive carries: the transactions of
 * that race's analysis, and the EconomyHistory rows fetched on entering it.
 * Every movement has a fingerprint, so a movement seen in several windows (the
 * economy table repeats recent history) is stored once.
 */
final class LedgerProjector
{
    use PayloadReader;

    public function __construct(
        private readonly ApiArchiveRepository $archive,
        private readonly EconomyLedgerRepository $repository,
        private readonly ?AnalysisReader $analyses = null,
    ) {
    }

    public function project(int $userId, int $season, int $race): void
    {
        $analysis = ($this->analyses ?? new AnalysisReader($this->archive))->forRace($userId, $season, $race);
        foreach ($this->listOf($analysis['transactions'] ?? null) as $index => $transaction) {
            $amount = $this->int($transaction['amount'] ?? null);
            if ($amount === null) {
                continue;
            }
            $description = $this->str($transaction['desc'] ?? null) ?? '';
            $this->repository->save([
                'user_id' => $userId,
                'season' => $season,
                'race' => $race,
                'source' => 'race',
                'occurred_at' => null,
                'description' => $description,
                'amount' => $amount,
                'balance_after' => null,
                'fingerprint' => sha1("race|{$season}|{$race}|{$index}|{$description}|{$amount}"),
            ]);
        }

        foreach ($this->archive->allStamped($userId, 'EconomyHistory', $season, $race) as $payload) {
            if (SupporterGate::isUnavailable('EconomyHistory', $payload)) {
                continue;
            }
            $header = null;
            foreach ($this->listOf($payload['data'] ?? null) as $entry) {
                if (($entry['type'] ?? null) === 'header') {
                    $header = $this->headerRace($entry);
                    continue;
                }
                $this->saveEconomy($userId, $header, [$season, $race], $entry);
            }
        }
    }

    /**
     * "Season 99, Race 10 - Indianapolis Oval (United States)" -> [99, 10].
     *
     * @param array<string, mixed> $header
     *
     * @return array{int, int}|null
     */
    private function headerRace(array $header): ?array
    {
        $desc = $this->str($header['desc'] ?? null) ?? '';

        return preg_match('/Season\s+(\d+)\s*,\s*Race\s+(\d+)/i', $desc, $m) === 1
            ? [(int) $m[1], (int) $m[2]]
            : null;
    }

    /**
     * A row belongs to the race of the header above it; the archive stamp is
     * only a fallback, and a stamped guess never overrides a header-filed row.
     *
     * @param array{int, int}|null $header
     * @param array{int, int} $stamp
     * @param array<string, mixed> $entry
     */
    private function saveEconomy(int $userId, ?array $header, array $stamp, array $entry): void
    {
        $amount = $this->int($entry['amount'] ?? null);
        if ($amount === null) {
            return;
        }
        $description = $this->str($entry['desc'] ?? null) ?? '';
        $at = $this->str($entry['datetime'] ?? null);
        [$season, $race] = $header ?? $stamp;

        $row = [
            'user_id' => $userId,
            'season' => $season,
            'race' => $race,
            'source' => 'economy',
            'occurred_at' => $at,
            'description' => $description,
            'amount' => $amount,
            'balance_after' => $this->int($entry['balanceAfter'] ?? null),
            'fingerprint' => sha1("economy|{$at}|{$description}|{$amount}"),
        ];
        $header === null ? $this->repository->saveIfNew($row) : $this->repository->save($row);
    }
}
