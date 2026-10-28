<?php

declare(strict_types=1);

namespace App\Engineer;

/**
 * @phpstan-import-type BudgetRow from SeasonBudget
 * @phpstan-import-type Demand from UpgradeRanker
 * @phpstan-type Candidate array{level: int, cost: int, is_free: bool, start: float, fit: float}
 * @phpstan-type PickKind 'free_downgrade'|'downgrade'|'sidegrade'|'upgrade'
 * @phpstan-type PickReason 'most_pha'|'top3_keep'|'top3_pays'|'budget_short'
 * @phpstan-type Pick array{level: int, kind: PickKind, cost: int, season_pha: float, net_cost: int,
 *   affordable: bool, reason: PickReason}
 * @phpstan-type Option array{level: int, kind: PickKind, cost: int, free: bool, season_pha: float, net_cost: int,
 *   affordable: bool, fit: float, rows: list<BudgetRow>}
 */
readonly class SwapLevelPicker
{
    public const array REASONS = [
        'most_pha' => 'Most PHA the season budget allows',
        'top3_keep' => 'Top-3 car: same level, no extra spend',
        'top3_pays' => 'Top-3 car: this upgrade pays for itself',
        'budget_short' => 'Budget short: cheapest option that keeps your car in place',
    ];

    private const float EQUAL_PHA = 0.5;

    private const array NO_CONTRIBUTION = ['power' => 0, 'handling' => 0, 'acceleration' => 0];

    /**
     * @param array<string, Demand> $contribution part label → PHA points one level adds
     */
    public function __construct(private SeasonBudget $budget, private array $contribution)
    {
    }

    /**
     * The level to fit when $part is swapped, judged over the remaining season. Each candidate is forecast with
     * the part at its level and start wear, future replacements at that level's paid price; one with no known
     * paid price, or whose forecast is infeasible, is dropped. The objective and the car's group rank decide
     * which downgrades may be taken; among the affordable rest the most season PHA wins. With nothing
     * affordable, the free option goes first, then the smallest shortfall. Null when no candidate is left.
     *
     * @param list<Candidate> $candidates
     * @param array<int, ?int> $paidPrice level → price of a new part at that level
     * @return Pick|null
     */
    public function pick(SwapSeason $s, string $part, int $currentLevel, array $candidates, array $paidPrice): ?array
    {
        $limit = CtrPlanner::limit($s->objective);
        $planned = array_sum(array_column($s->plan['rows'], 'repairs'));
        $contribution = $this->contribution[$part] ?? self::NO_CONTRIBUTION;

        $options = [];
        foreach ($candidates as $candidate) {
            $price = $paidPrice[$candidate['level']] ?? null;
            if ($price === null) {
                continue;
            }

            $forecast = $this->budget->forecast(
                $s->rounds,
                [$part => ['level' => $candidate['level'], 'start' => $candidate['start']] + ($s->parts[$part] ?? [])]
                    + $s->parts,
                [$part => $price] + $s->prices,
                $s->driverFactor,
                $s->plan['ctr'],
                $limit,
                $s->cash,
                $s->committed,
                $s->income,
            );
            if ($forecast['infeasible']) {
                continue;
            }

            $levels = $candidate['level'] - $currentLevel;
            $options[] = [
                'level' => $candidate['level'],
                'kind' => self::kind($levels, $candidate['is_free']),
                'cost' => $candidate['cost'],
                'free' => $candidate['is_free'],
                'season_pha' => SeasonPha::gain($contribution, $levels, $s->rounds, $s->demands),
                'net_cost' => $candidate['cost'] + array_sum(array_column($forecast['rows'], 'repairs')) - $planned,
                'affordable' => UpgradeRanker::paysUpFront($s->plan, $forecast['rows'], $candidate['cost']),
                'fit' => $candidate['fit'],
                'rows' => $forecast['rows'],
            ];
        }

        $chosen = self::choose($s, $options);
        if ($chosen === null) {
            return null;
        }
        [$option, $reason] = $chosen;

        return [
            'level' => $option['level'],
            'kind' => $option['kind'],
            'cost' => $option['cost'],
            'season_pha' => round($option['season_pha'], 1),
            'net_cost' => $option['net_cost'],
            'affordable' => $option['affordable'],
            'reason' => $reason,
        ];
    }

    /**
     * @param list<Option> $options
     * @return array{Option, PickReason}|null
     */
    private static function choose(SwapSeason $s, array $options): ?array
    {
        $affordable = self::only(
            $options,
            static fn (array $o): bool => $o['affordable'] && self::allows($s, $o['kind'], false),
        );

        if ($affordable === []) {
            $short = self::only($options, static fn (array $o): bool => self::allows($s, $o['kind'], true));
            if ($short === []) {
                return null;
            }
            $shortfall = static fn (array $o): int => -UpgradeRanker::headroomPaying($s->plan, $o['rows'], $o['cost']);
            usort(
                $short,
                static fn (array $a, array $b): int => [$b['free'], $shortfall($a)] <=> [$a['free'], $shortfall($b)],
            );

            return [$short[0], 'budget_short'];
        }

        if ($s->objective === SeasonObjective::PROMOTE && $s->floor->topThree()) {
            $pays = self::only(
                $affordable,
                static fn (array $o): bool => $o['kind'] === 'upgrade' && $o['net_cost'] <= 0,
            );
            if ($pays !== []) {
                return [self::mostPha($pays), 'top3_pays'];
            }
            $same = self::only($affordable, static fn (array $o): bool => $o['kind'] === 'sidegrade');
            if ($same !== []) {
                return [self::mostPha($same), 'top3_keep'];
            }
        }

        return [self::mostPha($affordable), 'most_pha'];
    }

    /**
     * Whether the objective lets the car take this kind of move. Promote never pays for a downgrade, and takes a
     * free one only when the money is short.
     *
     * @param PickKind $kind
     */
    private static function allows(SwapSeason $s, string $kind, bool $moneyShort): bool
    {
        if ($kind !== 'free_downgrade' && $kind !== 'downgrade') {
            return true;
        }

        return match ($s->objective) {
            SeasonObjective::PROMOTE => $moneyShort && $kind === 'free_downgrade' && $s->floor->keepsTopHalf(),
            SeasonObjective::HOLD => $s->floor->keepsTopHalf(),
            SeasonObjective::SURVIVE => $s->floor->keepsAboveRelegation(),
            default => false,
        };
    }

    /**
     * The most season PHA; within EQUAL_PHA of it, the lower net cost, then the better fit for this race.
     *
     * @param non-empty-list<Option> $options
     * @return Option
     */
    private static function mostPha(array $options): array
    {
        $best = max(array_column($options, 'season_pha'));
        $close = self::only($options, static fn (array $o): bool => $o['season_pha'] >= $best - self::EQUAL_PHA);
        usort(
            $close,
            static fn (array $a, array $b): int => [$a['net_cost'], $b['fit'], $b['season_pha']]
                <=> [$b['net_cost'], $a['fit'], $a['season_pha']],
        );

        return $close[0];
    }

    /**
     * @param list<Option> $options
     * @param callable(Option): bool $keep
     * @return list<Option>
     */
    private static function only(array $options, callable $keep): array
    {
        return array_values(array_filter($options, $keep));
    }

    /**
     * @return PickKind
     */
    private static function kind(int $levels, bool $free): string
    {
        return match (true) {
            $levels < 0 => $free ? 'free_downgrade' : 'downgrade',
            $levels === 0 => 'sidegrade',
            default => 'upgrade',
        };
    }
}
