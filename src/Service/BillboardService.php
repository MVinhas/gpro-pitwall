<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The header strip every signed-in page shows: cash, division, next race.
 *
 * It used to be assembled inside PageController, so only the calculator
 * screens had it — the Debrief, the control panel, contact and the admin pages
 * rendered an empty strip. Built here instead, and exposed to Twig once from
 * bootstrap, it follows the layout rather than any one controller.
 *
 * Strictly cache-only. Every figure comes from payloads the per-user sync has
 * already warmed; nothing here can spend API budget, so it is safe to run on
 * every page view. Before the first sync there is nothing to show, and the
 * strip stays empty rather than printing zeros.
 */
final readonly class BillboardService
{
    /** @param list<string> $divisions */
    public function __construct(
        private GproApiClient $apiClient,
        private array $divisions,
    ) {
    }

    /**
     * @return array{cash:int,division:?string,next_track:?string,season:?int,race:?int,cash_rank:?int,cash_total:?int}|null
     */
    public function forToken(string $token): ?array
    {
        // A scoped copy, never setToken(): the shared client belongs to the
        // controller handling this request.
        $client = $this->apiClient->withScopeFor($token);

        $menu = $client->getCachedMenu();
        $office = $client->getCachedOfficeData();

        if ($menu === [] && $office === []) {
            return null;
        }

        $nextTrack = (string) ($office['trackName'] ?? '');
        $season = (int) ($office['seasonNb'] ?? 0);
        $race = (int) ($office['raceNb'] ?? 0);
        $cash = (int) ($menu['cash'] ?? 0);

        $money = $client->getCachedMoneyLevels();
        $managers = $money['managers'] ?? [];
        $rank = self::rankCashAgainstGroup(
            (int) ($menu['IDM'] ?? 0),
            $cash,
            is_array($managers) ? $managers : [],
        );

        $group = trim((string) ($menu['group'] ?? ''));

        return [
            'cash'       => $cash,
            'division'   => self::tierFromGroup($group, $this->divisions) === null ? null : $group,
            'next_track' => $nextTrack !== '' ? $nextTrack : null,
            'season'     => $season > 0 ? $season : null,
            'race'       => $race > 0 ? $race : null,
            'cash_rank'  => $rank['rank'],
            'cash_total' => $rank['total'],
        ];
    }

    /**
     * The division tier from a GPRO group label ("Pro - 8" -> "Pro"), or null
     * when the prefix is not a real division. Guards the strip against showing
     * junk when a payload carries an unexpected group.
     *
     * @param list<string> $divisions
     */
    public static function tierFromGroup(string $group, array $divisions): ?string
    {
        if ($group === '') {
            return null;
        }

        $first = trim(explode('-', $group, 2)[0]);

        return in_array($first, $divisions, true) ? $first : null;
    }

    /**
     * Rank of the manager's cash within a MoneyLevels `managers` list.
     *
     * Derived from the cash values (count of managers with more cash, plus one)
     * rather than the API's `pos` field, so it holds regardless of response
     * order. Nulls when the list is empty or the manager is not in it.
     *
     * @param array<mixed> $managers
     * @return array{rank:?int,total:?int}
     */
    public static function rankCashAgainstGroup(int $idm, int $cash, array $managers): array
    {
        $found = false;
        $ahead = 0;
        $total = 0;

        foreach ($managers as $manager) {
            if (!is_array($manager)) {
                continue;
            }
            $total++;
            if ($idm > 0 && (int) ($manager['IDM'] ?? 0) === $idm) {
                $found = true;
            }
            if ((int) ($manager['cash'] ?? 0) > $cash) {
                $ahead++;
            }
        }

        if (!$found) {
            return ['rank' => null, 'total' => null];
        }

        return ['rank' => $ahead + 1, 'total' => $total];
    }
}
