<?php

declare(strict_types=1);

namespace App\Service;

use App\Archive\SupporterGate;
use App\Repository\ApiArchiveRepository;
use App\Standings\SeasonStandings;

/**
 * Feeds the Standings screen from the newest archived Standings payload, the
 * one every sync already fetches, so the screen never spends an API call.
 * The manager's own row is the one whose IDM matches the archived Menu's.
 */
final readonly class StandingsService
{
    public function __construct(private ApiArchiveRepository $archive)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $userId, ?string $tokenScope): array
    {
        $payload = $this->usable($this->archive->latest($userId, 'Standings', '', $tokenScope), 'Standings');
        if ($payload === null) {
            return ['available' => false];
        }

        $menu = $this->usable($this->archive->latest($userId, 'Menu', '', $tokenScope), 'Menu') ?? [];
        $idm = is_numeric($menu['IDM'] ?? null) ? (int) $menu['IDM'] : null;

        $view = SeasonStandings::build($payload, $idm);
        $season = $this->latestSeason($userId, 'Standings');
        $officeSeason = $this->latestSeason($userId, 'office');

        return $view + [
            'fetched_at' => $this->archive->latestFetchedAt($userId, 'Standings', '', $tokenScope),
            'season' => $season,
            // Until the first sync of a new season the archive still holds the old
            // one's final table: say so rather than present it as this season's.
            'final_table' => $view['races_run'] >= $view['races']
                || ($season !== null && $officeSeason !== null && $season < $officeSeason),
        ];
    }

    /** The newest season an endpoint's archived rows are stamped with. */
    private function latestSeason(int $userId, string $endpoint): ?int
    {
        $races = $this->archive->stampedRaces($userId, $endpoint);

        return $races === [] ? null : max(array_column($races, 'season'));
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>|null
     */
    private function usable(?array $payload, string $endpoint): ?array
    {
        return $payload === null || SupporterGate::isUnavailable($endpoint, $payload) ? null : $payload;
    }
}
