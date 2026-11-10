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
 * easy; talent carries wet races; stamina fades on long races; low grip and
 * heavy tyre wear both raise the cost of pushing.
 *
 * Aggressiveness is the primary lever on both dials, not a trim. A risk
 * setting is an instruction to fight for a position, and a driver who will
 * not fight banks the mistakes without cashing the places — so it enters
 * twice: as an appetite factor scaling both numbers, and as a hard per-driver
 * ceiling no track and no other attribute can push through. Everything else
 * modulates inside that envelope. Experience is what buys the appetite back:
 * the backed share adds attacking pace, the unbacked share is the mistake
 * trap and eats into overall margin.
 *
 * Driver energy is deliberately not an input — it only affects clear-track
 * risk, which this advisor doesn't cover.
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

    /**
     * From this distance (km) a race is long and stamina starts to cost risk.
     * Derived from the 64 seeded tracks: the field mean is ~301 km with a
     * standard deviation of ~17 km, so mean + 0.5·SD marks the 310+ tail
     * (~10 tracks, up to Indianapolis Oval 321.8).
     */
    private const float LONG_RACE_KM = 310.0;

    private const int MIN_RISK = 5;
    private const int MAX_RISK = 70;

    /**
     * Appetite factor = APPETITE_FLOOR + APPETITE_SPAN * (aggressiveness/250).
     * Calibrated against the situation that used to advise ~50: aggressiveness
     * 0 lands near 10, 50 near 20, and only a maxed 250 reaches the sixties.
     */
    private const float APPETITE_FLOOR = 0.20;
    private const float APPETITE_SPAN = 1.05;

    /**
     * Per-driver ceiling = CEILING_FLOOR + CEILING_SPAN * normalised
     * aggressiveness, snapped down to a multiple of 5. Nothing — not Monaco,
     * not a maxed driver, not dry weather — lifts a dial above it, so
     * aggressiveness 0 tops out at 15 and 250 at 65.
     */
    private const float CEILING_FLOOR = 15.0;
    private const float CEILING_SPAN = 0.50;

    /**
     * @param array<string, mixed> $driver mapped driver attributes (0-250 scales)
     * @param array{overtaking?: ?string, grip?: ?string, tyre_wear?: ?string, distance?: float} $track
     * @return array{overtake: int, defend: int}
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
        $aggN = $n('aggressiveness');
        $aggGap = max(0.0, $aggN - $n('experience'));
        $aggCovered = min($aggN, $n('experience'));
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

        // Appetite scales both dials; the ceiling then caps whatever comes out.
        $appetite = self::APPETITE_FLOOR + self::APPETITE_SPAN * $aggN / 100;
        $ceiling = $this->driverCeiling($aggN);

        $overtake = $this->finalise(
            self::OVERTAKE_BASE[$rating] * $mult * $appetite * (1 + 0.10 * $aggCovered / 100),
            $ceiling,
        );
        $defend = $this->finalise(self::DEFEND_BASE[$rating] * $mult * $appetite, $ceiling);

        return ['overtake' => $overtake, 'defend' => $defend];
    }

    /**
     * Suggests the three boost-set start laps for the chosen strategy. Boost is
     * spent where the car has clear air, as F1 spends its push laps: pace
     * stuck behind another car is wasted. So the sets go where the pit cycle
     * and the race spread the field out, never in the opening pack. Sets run
     * 3 laps each and overlapping sets are wasted.
     *
     * The sets follow the plan's real pit laps and always run the in-laps,
     * ending at the stop. GPRO refuels, as F1 did in 1994-2009, so the laps
     * before a stop carry the lightest car of the stint and the out-lap after
     * it the heaviest — the refuelling-era overcut, not today's out-lap
     * undercut. Without pit laps the stops are assumed at the even split.
     *
     * `planned` is the subset the fuel plan actually carries (the manager's
     * boost-stints choice): the highest-priority picks, not the earliest laps.
     *
     * @param list<int> $pitLaps
     * @return array{laps: array<int>, planned: array<int>, note: string}
     */
    public function suggestBoostLaps(
        int $raceLaps,
        int $stops,
        bool $raceWet,
        float $rainAvg,
        int $plannedSets = 0,
        array $pitLaps = [],
        string $firstStop = StrategyService::FIRST_STOP_EVEN,
    ): array {
        if ($raceLaps < 12) {
            return ['laps' => [], 'planned' => [], 'note' => 'Too few laps to plan boost sets — place them by feel.'];
        }

        if (count($pitLaps) !== $stops) {
            $stintLen = intdiv($raceLaps, max(1, $stops + 1));
            $pitLaps = $stops > 0 ? range($stintLen, $stops * $stintLen, $stintLen) : [];
        }

        // Priority order: the pit windows, then the final laps (the field has
        // spread out by then), then mid-race and quarter-distance as fillers.
        $candidates = [];
        foreach ($pitLaps as $pitLap) {
            $candidates[] = $pitLap - 2;
        }
        $candidates[] = $raceLaps - 2;
        $candidates[] = intdiv($raceLaps, 2);
        $candidates[] = intdiv($raceLaps, 4);

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
        $planned = array_slice($laps, 0, max(0, min(3, $plannedSets)));
        sort($laps);
        sort($planned);

        $note = match (true) {
            $firstStop === StrategyService::FIRST_STOP_EARLY && $pitLaps !== [] => 'Boost in clear air, '
                . 'on the in-laps before each stop. Before your early first stop the car is at its '
                . 'lightest, so those laps are the fastest you can buy.',
            $firstStop === StrategyService::FIRST_STOP_LATE && $pitLaps !== [] => 'Boost in clear air. '
                . 'Your late first stop is an overcut: once the cars around you have pitted, boost the '
                . 'in-laps before your own stop.',
            $pitLaps !== [] => 'Boost in clear air: the in-laps before your stops, once the cars around '
                . 'you have pitted.',
            default => 'Boost in clear air. With no stops, wait for the field to spread out — never in '
                . 'the opening pack.',
        };
        $note .= ' A spare set goes on the final laps, when the field is strung out.';

        if ($raceWet || $rainAvg >= self::RAIN_WATCH_THRESHOLD) {
            $note .= ' Rain could move the pit laps — treat these as dry-plan numbers.';
        }

        if ($planned === []) {
            $note .= ' Boosts burn extra fuel: set Boost stints in the form so the fuel columns include it.';
        }

        return ['laps' => $laps, 'planned' => $planned, 'note' => $note];
    }

    /**
     * The highest dial this driver is ever advised, from aggressiveness alone.
     * Snapped *down* to a multiple of 5 so the cap is itself a legal answer
     * rather than something `finalise()` would round back above it.
     */
    private function driverCeiling(float $aggN): int
    {
        $ceiling = (int)(floor((self::CEILING_FLOOR + self::CEILING_SPAN * $aggN) / 5) * 5);

        return max(self::MIN_RISK, min(self::MAX_RISK, $ceiling));
    }

    /** Snap to a multiple of 5 inside the sane band — risks are coarse dials. */
    private function finalise(float $value, int $ceiling): int
    {
        $snapped = (int)(round($value / 5) * 5);
        return max(self::MIN_RISK, min($ceiling, $snapped));
    }
}
