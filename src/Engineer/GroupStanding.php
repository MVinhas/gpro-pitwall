<?php

declare(strict_types=1);

namespace App\Engineer;

/**
 * @phpstan-type StaffStanding array{rank: int, of: int, own: int, band: 'top'|'middle', band_average: int, nudge: bool}
 * @phpstan-type CarStanding array{level: int, rank: int, of: int}
 */
final readonly class GroupStanding
{
    /**
     * @param array<string, mixed> $groupStaff the /ViewStaff payload
     * @param array<string, mixed> $menu the /Menu payload
     * @return StaffStanding|null
     */
    public static function staff(array $groupStaff, array $menu, string $objective): ?array
    {
        $own = null;
        $all = [];
        foreach (self::managers($groupStaff) as $manager) {
            if (!is_numeric($manager['staffOA'] ?? null)) {
                continue;
            }
            $oa = (int) $manager['staffOA'];
            $all[] = $oa;
            if (self::isOwn($manager, $menu)) {
                $own = $oa;
            }
        }
        // Thirds of one or two managers rank no one against anyone.
        if ($own === null || count($all) < 3) {
            return null;
        }

        rsort($all);
        $of = count($all);
        $rank = 1 + count(array_filter($all, static fn (int $oa): bool => $oa > $own));
        $third = intdiv($of + 2, 3);
        $hold = $objective === SeasonObjective::HOLD;

        $middle = array_slice($all, $third, max(0, $of - 2 * $third));
        $useMiddle = $hold && $middle !== [];
        $band = $useMiddle ? $middle : array_slice($all, 0, $third);

        return [
            'rank' => $rank,
            'of' => $of,
            'own' => $own,
            'band' => $useMiddle ? 'middle' : 'top',
            'band_average' => (int) round(array_sum($band) / count($band)),
            'nudge' => match ($objective) {
                SeasonObjective::PROMOTE => $rank > $third,
                SeasonObjective::HOLD => $rank > $of - $third,
                default => false,
            },
        ];
    }

    /**
     * @param array<string, mixed> $moneyLevels the /MoneyLevels payload
     * @param array<string, mixed> $menu the /Menu payload
     * @return CarStanding|null
     */
    public static function car(array $moneyLevels, array $menu): ?array
    {
        $managers = self::managers($moneyLevels);
        foreach ($managers as $manager) {
            if (!self::isOwn($manager, $menu)) {
                continue;
            }

            if (!is_numeric($manager['carLevel'] ?? null) || !is_numeric($manager['carLevelRank'] ?? null)) {
                return null;
            }

            return [
                'level' => (int) $manager['carLevel'],
                'rank' => (int) $manager['carLevelRank'],
                'of' => count($managers),
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private static function managers(array $payload): array
    {
        $managers = [];
        foreach (is_array($payload['managers'] ?? null) ? $payload['managers'] : [] as $manager) {
            if (is_array($manager)) {
                /** @var array<string, mixed> $manager */
                $managers[] = $manager;
            }
        }

        return $managers;
    }

    /**
     * @param array<string, mixed> $manager
     * @param array<string, mixed> $menu
     */
    private static function isOwn(array $manager, array $menu): bool
    {
        $mine = $menu['IDM'] ?? null;
        $theirs = $manager['IDM'] ?? null;

        return is_scalar($mine) && is_scalar($theirs) && (string) $theirs === (string) $mine;
    }
}
