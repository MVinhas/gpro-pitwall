<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The Car Wear tab: how each part's wear builds up over the next few races,
 * and how hard every round of the season is on each part.
 *
 * Wear comes from CarWearService (the same track base × part level ^ CTR ×
 * driver model the cockpit uses), but each race is rounded to a whole percent
 * before it is carried forward. GPRO reports whole-percent wear after every
 * race, so carrying fractions would drift a point off the game within three
 * races. The failure line is WearAdvisorService's: a part at 100% has broken.
 */
final readonly class CarWearPlannerService
{
    /** The next race plus the four after it. */
    public const int PLAN_RACES = 5;

    public const int MIN_LEVEL = 1;
    public const int MAX_LEVEL = 9;
    public const int MAX_WEAR = 99;

    /** Breaks in the next race. */
    public const string STATUS_NOW = 'now';
    /** Finishes the next race, breaks later in the plan. */
    public const string STATUS_LATER = 'later';
    /** Lasts every race in the plan. */
    public const string STATUS_OK = 'ok';

    public const string RATING_LIGHT = 'Light';
    public const string RATING_AVERAGE = 'Average';
    public const string RATING_HEAVY = 'Heavy';

    /** Where clear-track risk costs least in parts: push harder. */
    public const string ADVICE_PUSH = 'Push';
    public const string ADVICE_NORMAL = 'Normal';
    /** Where risk eats the expensive parts: hold back. */
    public const string ADVICE_HOLD = 'Hold back';

    /**
     * How far from the season average a round may sit and still read
     * "Average" (wear) or "Normal" (risk advice).
     */
    private const float RATING_BAND = 0.15;

    /**
     * The clear-track risk the push advice prices. Only the ranking between
     * rounds is shown, and a mid-range value weighs low-level parts (which
     * risk wears fastest) the way a pushing manager actually meets them.
     */
    private const int PUSH_REFERENCE_CTR = 50;

    public const string CELL_FAIL = 'fail';
    public const string CELL_RISKY = 'risky';
    public const string CELL_OK = 'ok';

    public function __construct(private CarWearService $wear)
    {
    }

    /**
     * Form keys for each part — PARTS_MAP labels carry spaces, which make poor
     * query-string keys.
     *
     * @return array<string, string> part label → slug
     */
    public static function slugs(): array
    {
        $out = [];
        foreach (CarWearService::PARTS_MAP as $label => $map) {
            $out[$label] = substr($map['db'], strlen('wear_'));
        }
        return $out;
    }

    /**
     * The rounds the planner covers: the next race and up to four after it.
     * It stops at the end of the season rather than guess the next one.
     *
     * @param list<array{race: int, track_name: ?string}> $season SeasonCalendarService rows
     * @return list<array{race: int, track_name: ?string}>
     */
    public function upcoming(array $season, int $nextRace): array
    {
        if ($nextRace <= 0) {
            return [];
        }

        $rows = array_values(array_filter(
            $season,
            static fn (array $row): bool => $row['race'] >= $nextRace,
        ));
        usort($rows, static fn (array $a, array $b): int => $a['race'] <=> $b['race']);

        return array_slice($rows, 0, self::PLAN_RACES);
    }

    /**
     * Each part's level and wear from the synced car, with any edits from the
     * form applied on top. Edits are keyed by slug; a value that isn't a number
     * keeps the synced one, and a number out of range is pulled back into it.
     *
     * @param array<string, mixed> $carData  GPRO Car payload (lvl* / usa* fields)
     * @param array<mixed> $levelOverrides   slug → level
     * @param array<mixed> $wearOverrides    slug → wear %
     * @return array<string, array{level: int, wear: int}>
     */
    public function partsFrom(array $carData, array $levelOverrides, array $wearOverrides): array
    {
        $slugs = self::slugs();
        $out = [];
        foreach (CarWearService::PARTS_MAP as $label => $map) {
            $slug = $slugs[$label];
            $level = self::intOr($levelOverrides[$slug] ?? null, (int) ($carData[$map['lvl']] ?? self::MIN_LEVEL));
            $wear = self::intOr($wearOverrides[$slug] ?? null, (int) ($carData[$map['wear']] ?? 0));

            $out[$label] = [
                'level' => max(self::MIN_LEVEL, min(self::MAX_LEVEL, $level)),
                'wear'  => max(0, min(self::MAX_WEAR, $wear)),
            ];
        }
        return $out;
    }

    /**
     * Projects every part race by race.
     *
     * A race whose track has no wear data (`base` null) ends the projection:
     * adding nothing for it would understate every race after it, so those
     * cells are unknown instead.
     *
     * @param list<array{race: int, track_name: ?string, ctr: int, base: ?array<string, float>}> $races
     * @param array<string, array{level: int, wear: int}> $parts
     * @return array{
     *   races: list<array{race: int, track_name: ?string, ctr: int, has_data: bool}>,
     *   parts: array<string, array{
     *     level: int, wear: int, ends: list<?int>, cells: list<string>,
     *     fails_at: ?int, status: string, verdict: string,
     *   }>,
     *   status: string,
     *   headline: string,
     *   horizon: ?int,
     *   missing_tracks: list<string>,
     * }
     */
    public function plan(array $races, array $parts, float $driverFactor): array
    {
        $horizon = null;
        $missing = [];
        $raceRows = [];
        $known = true;
        foreach ($races as $race) {
            $hasData = $race['base'] !== null;
            if (!$hasData) {
                $missing[] = (string) ($race['track_name'] ?? 'Race ' . $race['race']);
                $known = false;
            }
            if ($known) {
                $horizon = $race['race'];
            }
            $raceRows[] = [
                'race'       => $race['race'],
                'track_name' => $race['track_name'],
                'ctr'        => $race['ctr'],
                'has_data'   => $hasData,
            ];
        }

        $nextRace = $races[0]['race'] ?? null;
        $out = [];
        foreach ($parts as $label => $part) {
            $wear = $part['wear'];
            $ends = [];
            $cells = [];
            $failsAt = null;
            $known = true;
            foreach ($races as $race) {
                $base = $race['base'][$label] ?? null;
                if (!$known || $race['base'] === null || $base === null) {
                    $known = false;
                    $ends[] = null;
                    $cells[] = self::CELL_OK;
                    continue;
                }
                $wear += (int) round($this->wear->raceWear($base, $part['level'], $driverFactor, $race['ctr']));
                $ends[] = $wear;
                $cells[] = self::cellFor($wear);
                if ($failsAt === null && $wear >= WearAdvisorService::THRESHOLD_SWAP) {
                    $failsAt = $race['race'];
                }
            }

            $status = match (true) {
                $failsAt === null      => self::STATUS_OK,
                $failsAt === $nextRace => self::STATUS_NOW,
                default                => self::STATUS_LATER,
            };

            $out[$label] = [
                'level'    => $part['level'],
                'wear'     => $part['wear'],
                'ends'     => $ends,
                'cells'    => $cells,
                'fails_at' => $failsAt,
                'status'   => $status,
                'verdict'  => match ($status) {
                    self::STATUS_NOW   => 'Replace now',
                    self::STATUS_LATER => 'Replace before race ' . $failsAt,
                    default            => $horizon === null ? 'No wear data' : 'Lasts to race ' . $horizon,
                },
            ];
        }

        [$status, $headline] = $this->headline($out, $horizon);

        return [
            'races'          => $raceRows,
            'parts'          => $out,
            'status'         => $status,
            'headline'       => $headline,
            'horizon'        => $horizon,
            'missing_tracks' => $missing,
        ];
    }

    /**
     * What a new part at its current level costs, from GPRO's replacement
     * options. Null when the payload offers no such option: an unknown price
     * must not read as a free part.
     *
     * @param array<string, mixed> $carData GPRO Car payload
     * @return array<string, ?int> part label → price
     */
    public function partPrices(array $carData): array
    {
        $out = [];
        foreach (CarWearService::PARTS_MAP as $label => $map) {
            $level = (string) ($carData[$map['lvl']] ?? '');
            $out[$label] = null;
            $options = $carData[$map['options']] ?? null;
            if (!is_array($options)) {
                continue;
            }
            foreach ($options as $opt) {
                // A positive action buys a new part; zero keeps it and a
                // negative one is a free downgrade.
                if (
                    is_array($opt)
                    && (int) ($opt['value']['value'] ?? 0) > 0
                    && (string) ($opt['newLvl'] ?? '') === $level
                ) {
                    $out[$label] = (int) ($opt['value']['cost'] ?? 0);
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * How hard each round is on each part for this driver, before clear-track
     * risk (which multiplies it by the part level and so depends on choices
     * made race by race).
     *
     * With the car's parts and their prices, each round also gets risk advice:
     * the money in parts that extra clear-track risk wears out there, compared
     * with the rest of the season. Where the wear lands on cheap parts, pushing
     * is cheap.
     *
     * @param list<array{race: int, track_name: ?string, base: ?array<string, float>}> $rounds
     * @param array<string, array{level: int, wear: int}> $parts
     * @param array<string, ?int> $prices
     * @return list<array{
     *   race: int, track_name: ?string, is_past: bool, is_next: bool,
     *   parts: ?array<string, int>, overall: ?int, hardest: ?string, rating: ?string,
     *   advice: ?string,
     * }>
     */
    public function seasonWear(
        array $rounds,
        int $nextRace,
        float $driverFactor,
        array $parts = [],
        array $prices = [],
    ): array {
        $carParts = $parts;
        $priced = $carParts !== [] && $prices !== [] && !in_array(null, $prices, true);
        $pushCosts = [];

        $out = [];
        foreach ($rounds as $round) {
            $parts = null;
            $overall = null;
            $hardest = null;
            if ($round['base'] !== null) {
                $parts = [];
                foreach (array_keys(CarWearService::PARTS_MAP) as $label) {
                    $base = $round['base'][$label] ?? 0.0;
                    $parts[$label] = (int) round($this->wear->raceWear($base, self::MAX_LEVEL, $driverFactor, 0));
                }
                // The first part with the highest figure, so a tie reads in PARTS_MAP order.
                $hardest = (string) array_search(max($parts), $parts, true);
                $overall = (int) round(array_sum($parts) / count($parts));

                if ($priced) {
                    $pushCosts[$round['race']] = $this->pushCost($round['base'], $carParts, $prices, $driverFactor);
                }
            }

            $out[] = [
                'race'       => $round['race'],
                'track_name' => $round['track_name'],
                'is_past'    => $nextRace > 0 && $round['race'] < $nextRace,
                'is_next'    => $round['race'] === $nextRace,
                'parts'      => $parts,
                'overall'    => $overall,
                'hardest'    => $hardest,
                'rating'     => null,
                'advice'     => null,
            ];
        }

        // Light / Average / Heavy is relative to this season: an absolute cut
        // would call every round "heavy" for a driver who wears the car fast.
        $overalls = array_filter(array_column($out, 'overall'), static fn (?int $o): bool => $o !== null);
        if ($overalls !== []) {
            $mean = array_sum($overalls) / count($overalls);
            foreach ($out as &$row) {
                if ($row['overall'] !== null) {
                    $row['rating'] = match (true) {
                        $row['overall'] > $mean * (1 + self::RATING_BAND) => self::RATING_HEAVY,
                        $row['overall'] < $mean * (1 - self::RATING_BAND) => self::RATING_LIGHT,
                        default                                           => self::RATING_AVERAGE,
                    };
                }
            }
            unset($row);
        }

        if ($pushCosts !== []) {
            $mean = array_sum($pushCosts) / count($pushCosts);
            foreach ($out as &$row) {
                $cost = $pushCosts[$row['race']] ?? null;
                if ($cost !== null) {
                    $row['advice'] = match (true) {
                        $cost > $mean * (1 + self::RATING_BAND) => self::ADVICE_HOLD,
                        $cost < $mean * (1 - self::RATING_BAND) => self::ADVICE_PUSH,
                        default                                 => self::ADVICE_NORMAL,
                    };
                }
            }
            unset($row);
        }

        return $out;
    }

    /**
     * Money in parts the reference risk wears out at one round, over and above
     * a race run with no risk at all.
     *
     * @param array<string, float> $base
     * @param array<string, array{level: int, wear: int}> $parts
     * @param array<string, ?int> $prices
     */
    private function pushCost(array $base, array $parts, array $prices, float $driverFactor): float
    {
        $cost = 0.0;
        foreach ($parts as $label => $part) {
            $b = $base[$label] ?? 0.0;
            $extra = $this->wear->raceWear($b, $part['level'], $driverFactor, self::PUSH_REFERENCE_CTR)
                - $this->wear->raceWear($b, $part['level'], $driverFactor, 0);
            $cost += $extra / 100 * (float) ($prices[$label] ?? 0);
        }
        return $cost;
    }

    private static function cellFor(int $wear): string
    {
        return match (true) {
            $wear >= WearAdvisorService::THRESHOLD_SWAP  => self::CELL_FAIL,
            $wear >= WearAdvisorService::THRESHOLD_RISKY => self::CELL_RISKY,
            default                                      => self::CELL_OK,
        };
    }

    /**
     * @param array<string, array{status: string}> $parts
     * @return array{0: string, 1: string}
     */
    private function headline(array $parts, ?int $horizon): array
    {
        if ($horizon === null) {
            return [self::STATUS_OK, "No wear data for the next race's track."];
        }

        $now = count(array_filter($parts, static fn (array $p): bool => $p['status'] === self::STATUS_NOW));
        if ($now > 0) {
            return [
                self::STATUS_NOW,
                $now === 1 ? "1 part won't finish the next race." : "{$now} parts won't finish the next race.",
            ];
        }

        $later = count(array_filter($parts, static fn (array $p): bool => $p['status'] === self::STATUS_LATER));
        if ($later > 0) {
            return [
                self::STATUS_LATER,
                'Every part finishes the next race; '
                    . ($later === 1 ? '1 wears out' : "{$later} wear out")
                    . " by race {$horizon}.",
            ];
        }

        return [self::STATUS_OK, "Every part lasts to race {$horizon}."];
    }

    private static function intOr(mixed $value, int $fallback): int
    {
        return is_numeric($value) ? (int) $value : $fallback;
    }
}
