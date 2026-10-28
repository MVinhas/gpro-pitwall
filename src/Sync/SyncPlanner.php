<?php

declare(strict_types=1);

namespace App\Sync;

/**
 * Decides which GPRO endpoints a sync should spend API calls on. The freshly
 * fetched office is the change detector against the previously archived one,
 * so an unchanged re-login costs nothing beyond the office call itself.
 */
final class SyncPlanner
{
    public const string MODE_LOGIN = 'login';
    public const string MODE_MANUAL = 'manual';
    public const string MODE_HARVEST = 'harvest';

    public const int LOGIN_FLOOR = 50;
    public const int MANUAL_FLOOR = 20;
    public const int BACKFILL_MIN = 60;
    public const int BACKFILL_MAX_PER_WINDOW = 5;

    private const int PHASE2_FLOOR = 50;

    /** Phase-1 snapshot endpoints, in spending order. RaceAnalysis is always phase 2. */
    private const array PRIORITY = [
        Endpoint::DRIVER, Endpoint::CAR, Endpoint::TRACK, Endpoint::SETUP, Endpoint::TESTING,
        Endpoint::STAFF, Endpoint::TD, Endpoint::SPONSORS, Endpoint::MONEY,
    ];

    private const array SEASON_PHASE1 = [Endpoint::CALENDAR, Endpoint::TYRES, Endpoint::MENU, Endpoint::TRACKS];
    /** Fetched for the last completed season, which never changes. */
    private const array SEASON_PHASE2 = [Endpoint::SF_HISTORY, Endpoint::CCP_HISTORY];

    /** @var array<string, list<string>> done-flag => endpoints it unlocks when it flips to true */
    private const array FLAG_TRIGGERS = [
        'doneDriTrain' => [Endpoint::DRIVER],
        'doneCarRepair' => [Endpoint::CAR],
        'doneTesting' => [Endpoint::CAR, Endpoint::TESTING],
        'doneRaceSetup' => [Endpoint::SETUP],
        'doneQ2' => [Endpoint::SETUP],
        'doneStaffTrain' => [Endpoint::STAFF],
        'doneFacilitiesUpdate' => [Endpoint::STAFF],
    ];

    public function plan(SyncContext $ctx): SyncPlan
    {
        $phase1 = $ctx->mode === self::MODE_HARVEST ? [] : $this->phase1($ctx);
        $allowed = $this->budget($ctx->remaining, self::floor($ctx->mode));
        $trimmed = count($phase1) > $allowed;
        $dropped = array_slice($phase1, $allowed);
        $phase1 = array_slice($phase1, 0, $allowed);

        $phase2 = $this->phase2($ctx, $ctx->remaining - count($phase1));

        return new SyncPlan(array_map(self::entry(...), $phase1), $phase2, $trimmed, $dropped);
    }

    /**
     * Calls the mode's phase-1 floor still allows once $spent have been made:
     * what a sync may spend on follow-up reads its plan cannot name up front.
     */
    public function spareCalls(SyncContext $ctx, int $spent): int
    {
        return $this->budget($ctx->remaining - $spent, self::floor($ctx->mode));
    }

    private static function floor(string $mode): int
    {
        return $mode === self::MODE_MANUAL ? self::MANUAL_FLOOR : self::LOGIN_FLOOR;
    }

    /**
     * @return list<string>
     */
    private function phase1(SyncContext $ctx): array
    {
        $planned = $this->snapshotTriggers($ctx);
        if ($ctx->mode === self::MODE_MANUAL) {
            $planned[] = Endpoint::CAR;
            $planned[] = Endpoint::SETUP;
        }

        $planned = [...$planned, ...$this->missing(self::PRIORITY, $ctx->archivedThisRace)];
        $eligible = $this->eligible($ctx->office);

        $ordered = array_values(array_filter(
            self::PRIORITY,
            static fn (string $e): bool => in_array($e, $planned, true) && in_array($e, $eligible, true),
        ));

        return [...$ordered, ...$this->missing(self::SEASON_PHASE1, $ctx->archivedThisSeason)];
    }

    /**
     * @return list<array{endpoint: string, params: string}>
     */
    private function phase2(SyncContext $ctx, int $left): array
    {
        $planned = [];
        $newRace = $this->isNewRace($ctx);
        foreach ([Endpoint::ANALYSIS, Endpoint::STANDINGS, Endpoint::ECONOMY, Endpoint::GROUP_STAFF] as $endpoint) {
            if ($newRace || !in_array($endpoint, $ctx->archivedThisRace, true)) {
                $planned[] = $endpoint;
            }
        }
        $entries = array_map(self::entry(...), $planned);

        $completed = is_numeric($ctx->office['seasonNb'] ?? null) ? (int) $ctx->office['seasonNb'] - 1 : 0;
        if ($completed >= 1) {
            foreach ($this->missing(self::SEASON_PHASE2, $ctx->archivedThisSeason) as $endpoint) {
                $entries[] = ['endpoint' => $endpoint, 'params' => 'season=' . $completed];
            }
        }

        $entries = array_slice($entries, 0, $this->budget($left, self::PHASE2_FLOOR));

        // Backfill only spends what is genuinely spare once everything above is paid for.
        $left -= count($entries);
        if ($left >= self::BACKFILL_MIN) {
            $allowance = max(0, self::BACKFILL_MAX_PER_WINDOW - $ctx->backfillDoneThisWindow);
            $backfill = array_map(
                static fn (string $sr): array => ['endpoint' => Endpoint::ANALYSIS, 'params' => 'SR=' . $sr],
                array_slice($ctx->backfillPending, 0, $allowance),
            );
            $entries = [...$entries, ...array_slice($backfill, 0, max(0, $left - self::PHASE2_FLOOR))];
        }

        return $entries;
    }

    /**
     * @return list<string>
     */
    private function snapshotTriggers(SyncContext $ctx): array
    {
        if ($this->isNewRace($ctx)) {
            return self::PRIORITY;
        }

        $previous = $ctx->previousOffice ?? [];
        $planned = [];
        foreach (self::FLAG_TRIGGERS as $flag => $endpoints) {
            if (!self::truthy($previous[$flag] ?? null) && self::truthy($ctx->office[$flag] ?? null)) {
                $planned = [...$planned, ...$endpoints];
            }
        }
        if (self::text($previous, 'driId') !== self::text($ctx->office, 'driId')) {
            $planned[] = Endpoint::DRIVER;
        }
        if (self::text($previous, 'tdId') !== self::text($ctx->office, 'tdId')) {
            $planned[] = Endpoint::TD;
        }
        if (self::text($previous, 'sponsorsAlert') !== self::text($ctx->office, 'sponsorsAlert')) {
            $planned[] = Endpoint::SPONSORS;
        }

        return $planned;
    }

    /**
     * @param array<string, mixed> $office
     *
     * @return list<string>
     */
    private function eligible(array $office): array
    {
        return array_values(array_filter(
            self::PRIORITY,
            static fn (string $e): bool => match ($e) {
                Endpoint::DRIVER => self::hasId($office, 'driId'),
                Endpoint::TD => self::hasId($office, 'tdId'),
                Endpoint::TRACK => !self::truthy($office['endOfSeason'] ?? null),
                default => true,
            },
        ));
    }

    /**
     * @param list<string> $candidates
     * @param list<string> $archived
     *
     * @return list<string>
     */
    private function missing(array $candidates, array $archived): array
    {
        return array_values(array_filter(
            $candidates,
            static fn (string $e): bool => !in_array($e, $archived, true),
        ));
    }

    private function isNewRace(SyncContext $ctx): bool
    {
        $previous = $ctx->previousOffice;

        return $previous === null
            || self::text($previous, 'seasonNb') !== self::text($ctx->office, 'seasonNb')
            || self::text($previous, 'raceNb') !== self::text($ctx->office, 'raceNb');
    }

    private function budget(int $remaining, int $floor): int
    {
        return max(0, $remaining - $floor);
    }

    /**
     * @return array{endpoint: string, params: string}
     */
    private static function entry(string $endpoint): array
    {
        return ['endpoint' => $endpoint, 'params' => ''];
    }

    /**
     * @param array<string, mixed> $office
     */
    private static function text(array $office, string $key): string
    {
        $value = $office[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $office
     */
    private static function hasId(array $office, string $key): bool
    {
        return !in_array(self::text($office, $key), ['', '0'], true);
    }

    private static function truthy(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
