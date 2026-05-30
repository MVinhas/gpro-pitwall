<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Classifies per-part wear projections into cockpit recommendations:
 * which parts must be swapped before the race, which are risky, which
 * are merely worth watching. Operates on the output of CarWearService.
 */
final class WearAdvisorService
{
    /** End-of-race wear (%) at or above which the part will not finish. */
    public const int THRESHOLD_SWAP = 100;

    /** End-of-race wear (%) at or above which the part is dangerously close. */
    public const int THRESHOLD_RISKY = 90;

    /** End-of-race wear (%) at or above which to keep an eye on the part. */
    public const int THRESHOLD_WATCH = 75;

    public const string STATUS_SWAP  = 'swap';
    public const string STATUS_RISKY = 'risky';
    public const string STATUS_WATCH = 'watch';
    public const string STATUS_OK    = 'ok';

    /**
     * @param array<string, array{start: int|float, est: int|float, end: int|float, level?: int}> $parts
     * @return array{
     *   swap: list<array{part: string, level: int, start: int, est: float, end: float}>,
     *   risky: list<array{part: string, level: int, start: int, est: float, end: float}>,
     *   watch: list<array{part: string, level: int, start: int, est: float, end: float}>,
     *   headline: string
     * }
     */
    public function classify(array $parts): array
    {
        $buckets = [
            self::STATUS_SWAP  => [],
            self::STATUS_RISKY => [],
            self::STATUS_WATCH => [],
        ];

        foreach ($parts as $label => $row) {
            $end = (float) $row['end'];
            $status = $this->statusFor($end);
            if ($status === self::STATUS_OK) {
                continue;
            }
            $buckets[$status][] = [
                'part'  => $label,
                'level' => (int) ($row['level'] ?? 0),
                'start' => (int) $row['start'],
                'est'   => (float) $row['est'],
                'end'   => $end,
            ];
        }

        return [
            'swap'     => $buckets[self::STATUS_SWAP],
            'risky'    => $buckets[self::STATUS_RISKY],
            'watch'    => $buckets[self::STATUS_WATCH],
            'headline' => $this->headline($buckets),
        ];
    }

    private function statusFor(float $end): string
    {
        return match (true) {
            $end >= self::THRESHOLD_SWAP  => self::STATUS_SWAP,
            $end >= self::THRESHOLD_RISKY => self::STATUS_RISKY,
            $end >= self::THRESHOLD_WATCH => self::STATUS_WATCH,
            default                       => self::STATUS_OK,
        };
    }

    /**
     * @param array<string, list<array<string, mixed>>> $buckets
     */
    private function headline(array $buckets): string
    {
        if ($buckets[self::STATUS_SWAP] !== []) {
            $n = count($buckets[self::STATUS_SWAP]);
            return $n === 1
                ? '1 part will not survive the race — swap it.'
                : $n . ' parts will not survive the race — swap them.';
        }
        if ($buckets[self::STATUS_RISKY] !== []) {
            return 'No mandatory swaps, but some parts will finish in the red.';
        }
        if ($buckets[self::STATUS_WATCH] !== []) {
            return 'All parts will finish, with a couple worth watching.';
        }
        return 'All parts will finish comfortably.';
    }
}
