<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Server-wide outbound throttle for GPRO API calls.
 *
 * Every Pitwall instance hits the GPRO API from a single host IP, so the
 * concern is not per-user budget (GPRO already meters that per token) but the
 * *aggregate* rate one IP presents. This is a token bucket shared across all
 * PHP worker processes via a single flock-synchronised state file: it caps the
 * steady outbound rate while still allowing a short burst, so a thundering herd
 * of users opening their cockpit at the same minute can't make the server look
 * like it's hammering GPRO.
 *
 * It lives at the one chokepoint every outbound call passes through
 * (GproApiFetcher). Cache hits never reach it — only real fetches do — so the
 * common case pays nothing. Under contention it spaces calls by sleeping a
 * bounded amount; it never throws and never blocks longer than maxBlock, so a
 * busy moment degrades to "slightly slower" rather than a failed page.
 */
final class GproApiThrottle
{
    /** @param positive-int|0 $maxBlockMs upper bound on a single acquire()'s sleep */
    public function __construct(
        private readonly string $stateFile,
        private readonly float $ratePerSecond,
        private readonly float $burst,
        private readonly int $maxBlockMs,
    ) {
    }

    /**
     * Block until this process may issue one outbound call. Sleeps at most
     * maxBlockMs; a non-positive rate disables throttling entirely.
     */
    public function acquire(): void
    {
        if ($this->ratePerSecond <= 0.0) {
            return;
        }

        $waitSeconds = $this->reserve(microtime(true));
        if ($waitSeconds > 0.0) {
            usleep((int) round($waitSeconds * 1_000_000));
        }
    }

    /**
     * Reserve one token from the shared bucket and return how long the caller
     * must wait (seconds, already capped at maxBlock) before the call is due.
     * Separated from the sleep so the bucket logic is testable without delay.
     *
     * The bucket is allowed to go negative: each concurrent waiter reserves the
     * next slot and derives its own wait from the running deficit, which spaces
     * a queue of callers without holding the lock across the sleep.
     */
    public function reserve(float $now): float
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $handle = @fopen($this->stateFile, 'c+');
        if ($handle === false) {
            // Can't open the state file (e.g. var/ not writable). Fail open —
            // a throttle problem must never stop the app from talking to GPRO.
            return 0.0;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return 0.0;
            }

            [$tokens, $ts] = $this->readState($handle);

            // Refill for elapsed time, clamped to the burst ceiling.
            $elapsed = max(0.0, $now - $ts);
            $tokens = min($this->burst, $tokens + $elapsed * $this->ratePerSecond);

            // Reserve this call's slot.
            $tokens -= 1.0;

            $this->writeState($handle, $tokens, $now);
            flock($handle, LOCK_UN);

            if ($tokens >= 0.0) {
                return 0.0;
            }

            $wait = (-$tokens) / $this->ratePerSecond;
            return min($wait, $this->maxBlockMs / 1000.0);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     * @return array{0: float, 1: float} [tokens, lastRefillTs]
     */
    private function readState($handle): array
    {
        rewind($handle);
        $raw = stream_get_contents($handle);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['tokens'], $decoded['ts'])) {
                return [(float) $decoded['tokens'], (float) $decoded['ts']];
            }
        }

        // First ever call: start with a full bucket.
        return [$this->burst, 0.0];
    }

    /** @param resource $handle */
    private function writeState($handle, float $tokens, float $ts): void
    {
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, (string) json_encode(['tokens' => $tokens, 'ts' => $ts]));
        fflush($handle);
    }
}
