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

        return SeasonStandings::build($payload, $idm) + [
            'fetched_at' => $this->archive->latestFetchedAt($userId, 'Standings', '', $tokenScope),
        ];
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
