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

    private const array PRIORITY = [
        Endpoint::DRIVER, Endpoint::CAR, Endpoint::TRACK, Endpoint::SETUP, Endpoint::TESTING,
        Endpoint::STAFF, Endpoint::TD, Endpoint::SPONSORS, Endpoint::MONEY, Endpoint::ANALYSIS,
    ];

    private const array SEASON_PHASE1 = [Endpoint::CALENDAR, Endpoint::TYRES, Endpoint::MENU];
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

    public function __construct(private bool $analysisInPhase2 = false)
    {
    }

    public function plan(SyncContext $ctx): SyncPlan
    {
        $phase1 = $ctx->mode === self::MODE_HARVEST ? [] : $this->phase1($ctx);
        $floor = $ctx->mode === self::MODE_MANUAL ? self::MANUAL_FLOOR : self::LOGIN_FLOOR;
        $allowed = $this->budget($ctx->remaining, $floor);
        $trimmed = count($phase1) > $allowed;
        $phase1 = array_slice($phase1, 0, $allowed);

        $phase2 = $this->phase2($ctx, $ctx->remaining - count($phase1));

        return new SyncPlan(array_map(self::entry(...), $phase1), $phase2, $trimmed);
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
            fn (string $e): bool => in_array($e, $planned, true)
                && in_array($e, $eligible, true)
                && ($e !== Endpoint::ANALYSIS || !$this->analysisInPhase2),
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
        if ($this->analysisInPhase2 && ($newRace || !in_array(Endpoint::ANALYSIS, $ctx->archivedThisRace, true))) {
            $planned[] = Endpoint::ANALYSIS;
        }
        foreach ([Endpoint::STANDINGS, Endpoint::ECONOMY] as $endpoint) {
            if ($newRace || !in_array($endpoint, $ctx->archivedThisRace, true)) {
                $planned[] = $endpoint;
            }
        }
        $planned = [...$planned, ...$this->missing(self::SEASON_PHASE2, $ctx->archivedThisSeason)];

        $entries = array_slice(
            array_map(self::entry(...), $planned),
            0,
            $this->budget($left, self::PHASE2_FLOOR),
        );

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
