<?php

declare(strict_types=1);

namespace App\Sync;

use App\Service\GproApiClient;
use LogicException;

/**
 * Maps a planner endpoint (and its params) to the one client call that fetches
 * it fresh, so the sync and the harvest spend calls the same way.
 */
final class EndpointFetcher
{
    public function __construct(private readonly GproApiClient $apiClient)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function fetch(string $endpoint, string $params = ''): array
    {
        return match ($endpoint) {
            Endpoint::DRIVER => $this->apiClient->getMyPilotDetails(true),
            Endpoint::CAR => $this->apiClient->getCarData(true),
            Endpoint::TRACK => $this->apiClient->getNextRaceProfile(true),
            Endpoint::SETUP => $this->apiClient->getRaceSetup(true),
            Endpoint::TESTING => $this->apiClient->getTesting(true),
            Endpoint::STAFF => $this->apiClient->getStaffAndFacilities(true),
            Endpoint::TD => $this->apiClient->getTechnicalDirector(true),
            Endpoint::SPONSORS => $this->apiClient->getSponsorNegotiations(true),
            Endpoint::MONEY => $this->apiClient->getMoneyLevels(true),
            Endpoint::CALENDAR => $this->apiClient->getCalendar(true),
            Endpoint::MENU => $this->apiClient->getMenu(true),
            Endpoint::TYRES => $this->apiClient->getTyreSuppliers(true),
            Endpoint::STANDINGS => $this->apiClient->getStandings(true),
            Endpoint::ECONOMY => $this->apiClient->getEconomyHistory(1, true),
            Endpoint::GROUP_STAFF => $this->apiClient->getGroupStaff(true),
            Endpoint::SF_HISTORY => $this->apiClient->getSfHistory($this->season($endpoint, $params), true),
            Endpoint::CCP_HISTORY => $this->apiClient->getCcpHistory($this->season($endpoint, $params), true),
            Endpoint::ANALYSIS => $this->analysis($params),
            default => throw new LogicException('Not a fetchable endpoint: ' . $endpoint),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function analysis(string $params): array
    {
        if ($params === '') {
            return $this->apiClient->getRaceAnalysis(true);
        }
        if (preg_match('/^SR=(\d+),(\d+)$/', $params, $m) !== 1) {
            throw new LogicException('Unparsable ' . Endpoint::ANALYSIS . ' params');
        }

        return $this->apiClient->getRaceAnalysisFor((int) $m[1], (int) $m[2], true);
    }

    private function season(string $endpoint, string $params): int
    {
        if (preg_match('/^season=(\d+)$/', $params, $m) !== 1) {
            throw new LogicException('Unparsable ' . $endpoint . ' params');
        }

        return (int) $m[1];
    }
}
