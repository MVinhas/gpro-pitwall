<?php

declare(strict_types=1);

namespace App\Archive;

use App\Repository\ApiArchiveRepository;
use App\Repository\StandingsRepository;
use App\Telemetry\PayloadReader;

/**
 * Projects the group standings a manager faced on entering one race. The own
 * place is the manager whose IDM matches the archived Menu's; the per-manager
 * colour, arrow and rookie flags are kept raw, because what they mean for
 * promotion and relegation is game-rules work for a later phase.
 */
final class StandingsProjector
{
    use PayloadReader;

    private const array MANAGER_FIELDS = [
        'name', 'pos', 'tyre', 'natCode', 'IDM', 'manArrow', 'manBgColor', 'negative', 'cash',
        'retires', 'goesRookie', 'didSoftReset', 'champs', 'pts',
    ];

    public function __construct(
        private readonly ApiArchiveRepository $archive,
        private readonly StandingsRepository $repository,
        private readonly RaceSnapshotReader $snapshots,
    ) {
    }

    public function project(int $userId, int $season, int $race): void
    {
        $payload = $this->snapshots->usable($userId, 'Standings', $season, $race, '');
        if ($payload === null || !is_array($payload['managers'] ?? null)) {
            $this->repository->delete($userId, $season, $race);

            return;
        }

        $menu = $this->snapshots->usable($userId, 'Menu', $season, $race, '') ?? $this->latestMenu($userId);
        $idm = $this->int($menu['IDM'] ?? null);

        $managers = [];
        $own = null;
        foreach ($this->listOf($payload['managers']) as $manager) {
            $kept = array_intersect_key($manager, array_flip(self::MANAGER_FIELDS));
            $managers[] = $kept;
            if ($idm !== null && $this->int($manager['IDM'] ?? null) === $idm) {
                $own = $kept;
            }
        }

        $this->repository->save([
            'user_id' => $userId,
            'season' => $season,
            'race' => $race,
            'own_pos' => $own === null ? null : $this->int($own['pos'] ?? null),
            'own_pts' => $own === null ? null : $this->int($own['pts'] ?? null),
            'group_label' => $this->str($payload['group'] ?? null),
            'managers_json' => json_encode($managers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * IDM never changes, so a Menu from another race is as good as this race's.
     *
     * @return array<string, mixed>
     */
    private function latestMenu(int $userId): array
    {
        $menu = $this->archive->latest($userId, 'Menu');

        return $menu === null || SupporterGate::isUnavailable('Menu', $menu) ? [] : $menu;
    }
}
