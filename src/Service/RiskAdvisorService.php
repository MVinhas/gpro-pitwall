<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Suggests overtake/defend race risks (0-100 dials) for the next race.
 *
 * Heuristic advisor, not a game formula. Driver skills arrive on GPRO's
 * 0-250 scale and are normalised before weighting. Inputs, per the in-game
 * tooltip semantics: overtake risk pays on hard-overtaking tracks while the
 * track itself protects your position; defending matters where passing is
 * easy; talent carries wet races; aggressiveness without experience invites
 * mistakes; stamina fades on long races; low grip and heavy tyre wear both
 * raise the cost of pushing.
 *
 * Driver energy is deliberately not an input — it only affects clear-track
 * risk, which this advisor doesn't cover. Long races still get a static
 * reminder that clear-track risk drives energy drain.
 *
 * Track downforce and suspension are deliberately not inputs: downforce is
 * collinear with the overtaking rating (slow twisty tracks score high on
 * both), and suspension rigidity has no plausible mistake mechanism.
 */
class RiskAdvisorService
{
    private const array OVERTAKE_BASE = [
        'Very Easy' => 20,
        'Easy'      => 30,
        'Normal'    => 40,
        'Hard'      => 55,
        'Very Hard' => 65,
    ];

    private const array DEFEND_BASE = [
        'Very Easy' => 50,
        'Easy'      => 45,
        'Normal'    => 40,
        'Hard'      => 30,
        'Very Hard' => 25,
    ];

    /** Sliding cars punish ambition — low-grip tracks trim both dials. */
    private const array GRIP_FACTOR = [
        'Very Low' => 0.82,
        'Low'      => 0.91,
    ];

    /** Pushing chews rubber faster where wear is already heavy. */
    private const array TYRE_WEAR_FACTOR = [
        'Very High' => 0.93,
        'High'      => 0.97,
    ];

    /** GPRO driver skills run 0-250; internal math uses 0-100. */
    private const float ATTRIBUTE_SCALE = 2.5;

    /** Rain probability (%) from which a dry forecast still warrants caution. */
    private const float RAIN_WATCH_THRESHOLD = 30.0;

    /** Race distance (km) from which stamina starts to matter. */
    private const float LONG_RACE_KM = 300.0;

    private const int MIN_RISK = 5;
    private const int MAX_RISK = 70;

    /** Lap-time loss with a solvable car problem (s/lap) — official tutorial says 3-6. */
    private const float PROBLEM_LAP_LOSS = 4.5;

    /** Repair time assumed on top of pit-lane transit for a problem stop (s). */
    private const float PROBLEM_REPAIR_TIME = 15.0;

    /**
     * @param array<string, mixed> $driver mapped driver attributes (0-250 scales)
     * @param array{name?: ?string, overtaking?: ?string, grip?: ?string,
     *               tyre_wear?: ?string, distance?: float, pit_lane_loss?: float} $track
     * @return array{overtake: int, defend: int, phrase: string, tip: ?string,
     *               energy_tip: ?string,
     *               settings: array{start_approach: string, problem_pit_laps: int}}
     */
    public function suggest(array $driver, array $track, bool $raceWet, float $rainAvg): array
    {
        $overtaking = $track['overtaking'] ?? null;
        $rating = isset(self::OVERTAKE_BASE[$overtaking ?? '']) ? (string)$overtaking : 'Normal';
        $grip = (string)($track['grip'] ?? '');
        $tyreWear = (string)($track['tyre_wear'] ?? '');
        $distanceKm = (float)($track['distance'] ?? 0);

        $n = fn(string $key): float =>
            max(0.0, min(100.0, (float)($driver[$key] ?? 0) / self::ATTRIBUTE_SCALE));

        // Wet races shift the weight to talent ("talent truly shines through"
        // in tricky/wet conditions); dry races lean on concentration + experience.
        $composure = $raceWet
            ? 0.30 * $n('concentration') + 0.40 * $n('talent') + 0.20 * $n('experience') + 0.10 * $n('motivation')
            : 0.40 * $n('concentration') + 0.20 * $n('talent') + 0.30 * $n('experience') + 0.10 * $n('motivation');

        // Aggression is "hit or miss, depends on experience": the share backed
        // by experience buys extra attacking pace (overtake only); the share
        // beyond it is the mistake trap and trims overall margin.
        $aggGap = max(0.0, $n('aggressiveness') - $n('experience'));
        $aggCovered = min($n('aggressiveness'), $n('experience'));
        $composure = max(0.0, min(100.0, $composure - 0.3 * $aggGap));

        $mult = 0.55 + 0.80 * $composure / 100;

        if ($raceWet) {
            $mult *= 0.85;
        } elseif ($rainAvg >= self::RAIN_WATCH_THRESHOLD) {
            $mult *= 0.92;
        }

        $mult *= self::GRIP_FACTOR[$grip] ?? 1.0;
        $mult *= self::TYRE_WEAR_FACTOR[$tyreWear] ?? 1.0;

        $longRace = $distanceKm >= self::LONG_RACE_KM;
        if ($longRace) {
            $mult *= 0.90 + 0.10 * $n('stamina') / 100;
        }

        $overtake = $this->finalise(self::OVERTAKE_BASE[$rating] * $mult * (1 + 0.10 * $aggCovered / 100));
        $defend   = $this->finalise(self::DEFEND_BASE[$rating] * $mult);

        return [
            'overtake' => $overtake,
            'defend'   => $defend,
            'phrase'   => $this->phrase(
                $rating,
                $overtake,
                $defend,
                (string)($track['name'] ?? ''),
                $grip,
                $tyreWear,
                $raceWet,
                $rainAvg,
                $composure,
                $aggGap,
                $longRace,
                $driver,
            ),
            'tip' => $this->strategyTip($rating, $raceWet, $rainAvg),
            'energy_tip' => $longRace
                ? sprintf(
                    'At %.0f km this is a long race — mind your clear-track risks: they drive energy '
                    . 'drain, and a driver who hits 0%% energy crawls home at a slow, no-risk pace.',
                    $distanceKm,
                )
                : null,
            'settings' => [
                'start_approach' => $this->startApproach($rating, $composure, $aggCovered, $raceWet, $n('talent')),
                'problem_pit_laps' => $this->problemPitLaps((float)($track['pit_lane_loss'] ?? 0)),
            ],
        ];
    }

    /**
     * Suggests the three boost-set start laps for the chosen strategy. Boost
     * pays where pace converts into something: passing chances in a pack
     * (the official tutorial's example), track position through the pit cycle
     * (boosted in-laps = the overcut), or gap defence in the final laps.
     * Sets run 3 laps each and overlapping sets are wasted.
     *
     * @return array{laps: array<int>, note: string}
     */
    public function suggestBoostLaps(
        int $raceLaps,
        int $stops,
        ?string $overtaking,
        bool $raceWet,
        float $rainAvg,
    ): array {
        if ($raceLaps < 12) {
            return ['laps' => [], 'note' => 'Too few laps to plan boost sets — place them by feel.'];
        }

        $rating = isset(self::OVERTAKE_BASE[$overtaking ?? '']) ? (string)$overtaking : 'Normal';
        $easyPassing = in_array($rating, ['Very Easy', 'Easy'], true);
        $stintLen = intdiv($raceLaps, max(1, $stops + 1));

        // Priority order: easy passing cashes pace early while the field is
        // packed; otherwise in-laps first (overcut), then the final laps,
        // then early/mid-race as fillers.
        $candidates = [];
        if ($easyPassing) {
            $candidates[] = 2;
        }
        for ($i = 1; $i <= $stops; $i++) {
            $candidates[] = $i * $stintLen - 2;
        }
        $candidates[] = $raceLaps - 2;
        if (!$easyPassing) {
            $candidates[] = 2;
        }
        $candidates[] = intdiv($raceLaps, 2);

        $laps = [];
        foreach ($candidates as $lap) {
            $lap = max(1, min($raceLaps - 2, $lap));
            foreach ($laps as $taken) {
                if (abs($taken - $lap) < 3) {
                    continue 2;
                }
            }
            $laps[] = $lap;
            if (count($laps) === 3) {
                break;
            }
        }
        sort($laps);

        $note = $easyPassing
            ? 'One set early while the field is still packed — passing is cheap here, so pace turns '
                . 'straight into positions — then boost into the pit windows.'
            : ($stops > 0
                ? 'Boost the in-laps before the stops to jump the cars around you through the pit '
                    . 'cycle; any spare set defends the final laps.'
                : 'No stops to play with, so spread the sets — one early, one mid-race, one to bring it home.');

        if ($raceWet || $rainAvg >= self::RAIN_WATCH_THRESHOLD) {
            $note .= ' Rain could move the pit laps — treat these as dry-plan numbers.';
        }

        $note .= ' Boosts burn extra fuel: set Boost stints in the form so the fuel columns include it.';

        return ['laps' => $laps, 'note' => $note];
    }

    /**
     * Maps driver control + track context to GPRO's four start options. The
     * official tutorial warns start risk stacks with race risks, so the
     * suggestion steps down when composure is thin or the start is wet.
     */
    private function startApproach(
        string $rating,
        float $composure,
        float $aggCovered,
        bool $raceWet,
        float $talentN,
    ): string {
        $score = $composure + 0.15 * $aggCovered;

        // Hard passing makes grid position durable — the start is where it's won.
        $score += match ($rating) {
            'Hard', 'Very Hard' => 10.0,
            'Normal' => 5.0,
            default => 0.0,
        };

        if ($raceWet && $talentN < 70) {
            $score -= 15.0;
        }

        return match (true) {
            $score >= 75 => 'Force his way to the front',
            $score >= 55 => 'Overtake where possible',
            $score >= 35 => 'Maintain his position',
            default => 'Avoid trouble',
        };
    }

    /**
     * Break-even threshold for "pit on a solvable problem": a repair stop
     * costs pit-lane transit + repair, a limping car loses 3-6 s every lap.
     */
    private function problemPitLaps(float $pitLaneLoss): int
    {
        $stopCost = ($pitLaneLoss > 0 ? $pitLaneLoss : 20.0) + self::PROBLEM_REPAIR_TIME;

        return (int) max(5, min(12, ceil($stopCost / self::PROBLEM_LAP_LOSS)));
    }

    /** Snap to a multiple of 5 inside the sane band — risks are coarse dials. */
    private function finalise(float $value): int
    {
        $snapped = (int)(round($value / 5) * 5);
        return max(self::MIN_RISK, min(self::MAX_RISK, $snapped));
    }

    /**
     * Pit-count tie-breaker advice driven by how hard passing is. Suppressed
     * when rain is likely — a wet race rewrites the stop plan anyway.
     */
    private function strategyTip(string $rating, bool $raceWet, float $rainAvg): ?string
    {
        if ($raceWet || $rainAvg >= self::RAIN_WATCH_THRESHOLD) {
            return null;
        }

        return match ($rating) {
            'Hard', 'Very Hard' => 'Unsure between two strategies? Take the one with fewer pit stops — '
                . 'every stop drops you into traffic you can\'t easily clear when passing is this hard.',
            'Very Easy', 'Easy' => 'If two strategies are close on paper, the extra pit stop is affordable here — '
                . 'fresh tyres and clean air beat track position when passing is easy.',
            default => 'If two strategies are within a few seconds, prefer the one with fewer stops — '
                . 'track position still breaks ties.',
        };
    }

    /**
     * @param array<string, mixed> $driver
     */
    private function phrase(
        string $rating,
        int $overtake,
        int $defend,
        string $trackName,
        string $grip,
        string $tyreWear,
        bool $raceWet,
        float $rainAvg,
        float $composure,
        float $aggGap,
        bool $longRace,
        array $driver,
    ): string {
        $track = $trackName !== '' ? $trackName : 'this track';

        $lead = match ($rating) {
            'Very Easy' => sprintf(
                'Passing at %s comes cheap, so I\'d keep overtake modest at %d and put the '
                . 'effort into defending at %d. And remember these dials only act in traffic — '
                . 'on a track this open, the lap time lives in your clear-track risk.',
                $track,
                $overtake,
                $defend,
            ),
            'Easy' => sprintf(
                'Passing at %s comes cheap, so I\'d keep overtake modest at %d and put the '
                . 'effort into defending at %d — your position is what\'s under threat here.',
                $track,
                $overtake,
                $defend,
            ),
            'Hard', 'Very Hard' => sprintf(
                'Overtaking at %s is %s, so I\'d push overtake up to %d to make moves stick — '
                . 'and since the track already makes you hard to pass, %d on defence is plenty.',
                $track,
                strtolower($rating),
                $overtake,
                $defend,
            ),
            default => sprintf(
                '%s is neutral for passing — a balanced %d overtake / %d defend split fits.',
                $track,
                $overtake,
                $defend,
            ),
        };

        $caveats = [];

        if ($raceWet) {
            $tal = (float)($driver['talent'] ?? 0);
            $talN = $tal / self::ATTRIBUTE_SCALE;
            $caveats[] = match (true) {
                $talN >= 70 => sprintf(
                    'It\'s a wet race and talent at %.0f thrives in the spray, so I\'ve only trimmed lightly.',
                    $tal,
                ),
                $talN < 40 => sprintf(
                    'It\'s a wet race and with talent at %.0f I\'d stay well clear of trouble — '
                    . 'both numbers are trimmed.',
                    $tal,
                ),
                default => sprintf(
                    'It\'s a wet race, so I\'ve trimmed both — talent at %.0f buys some margin, '
                    . 'but not enough to push.',
                    $tal,
                ),
            };
        } elseif ($rainAvg >= self::RAIN_WATCH_THRESHOLD) {
            $caveats[] = sprintf(
                'Rain sits at %.0f%% — if it turns wet, ease off further.',
                $rainAvg,
            );
        }

        if (isset(self::GRIP_FACTOR[$grip])) {
            $caveats[] = sprintf(
                'Grip here is %s — sliding cars punish ambition, so I\'ve shaved both numbers.',
                strtolower($grip),
            );
        }

        if ($aggGap > 15) {
            $caveats[] = sprintf(
                'Watch the temper: aggressiveness %.0f against experience %.0f invites mistakes, '
                . 'so I held back a notch.',
                (float)($driver['aggressiveness'] ?? 0),
                (float)($driver['experience'] ?? 0),
            );
        }

        if ($tyreWear === 'Very High') {
            $caveats[] = 'Tyre wear runs very high here — pushing chews the rubber you\'ll need '
                . 'at the end of each stint.';
        }

        if ($longRace && (float)($driver['stamina'] ?? 0) / self::ATTRIBUTE_SCALE < 60) {
            $caveats[] = sprintf(
                'It\'s a long race and stamina at %.0f will fade late — another reason to stay tidy.',
                (float)($driver['stamina'] ?? 0),
            );
        }

        if ($caveats === []) {
            $caveats[] = $composure >= 70
                ? sprintf(
                    'Concentration %.0f and experience %.0f give plenty of margin for these numbers.',
                    (float)($driver['concentration'] ?? 0),
                    (float)($driver['experience'] ?? 0),
                )
                : 'The driver is the ceiling here — train concentration and experience '
                    . 'before pushing these dials higher.';
        }

        return $lead . ' ' . implode(' ', array_slice($caveats, 0, 2));
    }
}
