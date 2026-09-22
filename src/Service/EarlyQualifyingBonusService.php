<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\SyncState;
use DateTimeImmutable;
use DateTimeZone;

/**
 * GPRO's early-qualifying bonus (from season 113): each qualifying session
 * done up to 24 h before the race pays a flat per-class amount with the race
 * income; inside the last 24 h it falls in a straight line to nothing when
 * qualifying closes. "12 hours before still pays around half" in GPRO's
 * announcement matches that line ending at qualifying close, not race start.
 */
final class EarlyQualifyingBonusService
{
    public const int FIRST_SEASON = 113;

    /** @var array<string, int> */
    private const array PER_SESSION = [
        'Rookie'  => 200_000,
        'Amateur' => 400_000,
        'Pro'     => 600_000,
        'Master'  => 800_000,
        'Elite'   => 1_000_000,
    ];

    private const int FULL_BONUS_LEAD    = 86_400;
    private const int DEFAULT_CLOSE_LEAD = 5_400;
    private const int RACE_START_HOUR    = 20;
    private const int RACE_LENGTH        = 7_200;
    private const string RACE_TZ         = 'Europe/Paris';
    private const string OUT_FORMAT      = 'Y-m-d H:i:s';

    /** @param list<int> $raceDays ISO-8601 weekday numbers (see RaceWindow) */
    public function __construct(private readonly array $raceDays)
    {
    }

    /**
     * Null when there is nothing worth saying: class unknown, no race to
     * qualify for, or qualifying already closed.
     *
     * @param array<string, mixed> $office
     * @return array{
     *     state: 'preview'|'full'|'shrinking'|'banked',
     *     division: string,
     *     per_session: int,
     *     first_season: int,
     *     share: float,
     *     now_per_session: int,
     *     full_until: ?string,
     *     closes_at: ?string,
     *     sessions: ?array{q1: bool, q2: bool},
     * }|null
     */
    public function assess(array $office, ?string $division, DateTimeImmutable $now, ?string $lastSyncedAt): ?array
    {
        $perSession = self::PER_SESSION[$division ?? ''] ?? null;
        $season = (int) ($office['seasonNb'] ?? 0);
        if ($perSession === null || $season <= 0 || $division === null) {
            return null;
        }

        $base = [
            'division'     => $division,
            'per_session'  => $perSession,
            'first_season' => self::FIRST_SEASON,
        ];

        if ($season < self::FIRST_SEASON) {
            return $base + [
                'state'           => 'preview',
                'share'           => 1.0,
                'now_per_session' => $perSession,
                'full_until'      => null,
                'closes_at'       => null,
                'sessions'        => null,
            ];
        }

        $raceStart = $this->nextRaceStart($now);
        if ($raceStart === null || (int) ($office['endOfSeason'] ?? 0) === 1) {
            return null;
        }

        $closesAt  = $raceStart->modify('-' . $this->closeLead($office) . ' seconds');
        $fullUntil = $raceStart->modify('-' . self::FULL_BONUS_LEAD . ' seconds');
        if ($now >= $closesAt) {
            return null;
        }

        $share = $now <= $fullUntil
            ? 1.0
            : ($closesAt->getTimestamp() - $now->getTimestamp())
                / ($closesAt->getTimestamp() - $fullUntil->getTimestamp());

        // After a race has run, the cached Office still carries that race's
        // done flags; they say nothing about the one now open.
        $sessions = SyncState::isStale($lastSyncedAt, $now, $this->raceDays)
            ? null
            : [
                'q1' => (int) ($office['doneQ1'] ?? 0) > 0,
                'q2' => (int) ($office['doneQ2'] ?? 0) > 0,
            ];

        $state = match (true) {
            $sessions !== null && $sessions['q1'] && $sessions['q2'] => 'banked',
            $share >= 1.0                                            => 'full',
            default                                                  => 'shrinking',
        };

        $utc = new DateTimeZone('UTC');

        return $base + [
            'state'           => $state,
            'share'           => $share,
            'now_per_session' => (int) round($perSession * $share, -4),
            'full_until'      => $fullUntil->setTimezone($utc)->format(self::OUT_FORMAT),
            'closes_at'       => $closesAt->setTimezone($utc)->format(self::OUT_FORMAT),
            'sessions'        => $sessions,
        ];
    }

    /**
     * A race still running counts as the next one: until it ends, Office and
     * its done flags describe it, not the race after.
     */
    private function nextRaceStart(DateTimeImmutable $now): ?DateTimeImmutable
    {
        $local = $now->setTimezone(new DateTimeZone(self::RACE_TZ));
        $running = $local->modify('-' . self::RACE_LENGTH . ' seconds');
        for ($days = 0; $days <= 7; $days++) {
            $start = $local->modify("+{$days} days")->setTime(self::RACE_START_HOUR, 0);
            if ($start > $running && in_array((int) $start->format('N'), $this->raceDays, true)) {
                return $start;
            }
        }
        return null;
    }

    /**
     * Qualifying closes a fixed time before the race. Office's two countdowns
     * were taken at the same instant, so their gap is that lead however old
     * the cached payload is.
     *
     * @param array<string, mixed> $office
     */
    private function closeLead(array $office): int
    {
        $gap = (int) ($office['secondsLeft'] ?? 0) - (int) ($office['secondsLeftQual'] ?? 0);
        return $gap > 0 && $gap < self::FULL_BONUS_LEAD ? $gap : self::DEFAULT_CLOSE_LEAD;
    }
}
