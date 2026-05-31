<?php

declare(strict_types=1);

namespace App\Service;

use App\Cache\CacheInterface;
use RuntimeException;

final class GproApiClient
{
    private readonly string $baseUrl;

    private ?string $token = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        array $config,
        private readonly CacheInterface $cache
    ) {
        $this->baseUrl = rtrim((string) $config['base_url'], '/');
    }

    public function setToken(string $token): void
    {
        $this->token = trim($token);
    }

    public function getOfficeData(bool $forceRefresh = false): array
    {
        return $this->getCached(
            'office_data',
            '/gb/backend/api/v2/office',
            $this->ttlShort(),
            $forceRefresh
        );
    }

    public function getMyPilotDetails(bool $forceRefresh = false): array
    {
        $office = $this->getOfficeData($forceRefresh);

        if (!isset($office['driId'])) {
            throw new RuntimeException('Driver ID missing from office data');
        }

        return $this->getCached(
            'driver_profile_' . $office['driId'],
            "/gb/backend/api/v2/DriProfile?id={$office['driId']}",
            $this->ttlShort(),
            $forceRefresh
        );
    }

    public function getNextRaceProfile(bool $forceRefresh = false): array
    {
        return $this->getCached(
            'next_race_profile',
            '/gb/backend/api/v2/TrackProfile',
            $this->ttlShort(),
            $forceRefresh
        );
    }

    public function getCarData(bool $forceRefresh = false): array
    {
        return $this->getCached(
            'car_data',
            '/gb/backend/api/v2/UpdateCar',
            $this->ttlShort(),
            $forceRefresh
        );
    }

    public function getRaceSetup(bool $forceRefresh = false): array
    {
        return $this->getCached(
            'race_setup',
            '/gb/backend/api/v2/RaceSetup',
            $this->ttlShort(),
            $forceRefresh
        );
    }

    public function getStaffAndFacilities(bool $forceRefresh = false): array
    {
        return $this->getCached(
            'staff_facilities',
            '/gb/backend/api/v2/StaffAndFacilities',
            $this->ttlShort(),
            $forceRefresh
        );
    }

    public function getTechnicalDirector(bool $forceRefresh = false): array
    {
        try {
            return $this->getCached(
                'td_profile',
                '/gb/backend/api/v2/TDProfile',
                $this->ttlShort(),
                $forceRefresh
            );
        } catch (\Throwable) {
            return [];
        }
    }

    public function getTyreSuppliers(bool $forceRefresh = false): array
    {
        return $this->getCached(
            'tyre_suppliers',
            '/gb/backend/api/v2/TyreSuppliers',
            120000,
            $forceRefresh
        );
    }

    /**
     * Manager menu. Carries `group` ("Rookie - 31"), `groupShort` ("R31"),
     * cash, team metadata — used for division-aware features (training
     * advisor, sponsors).
     */
    public function getMenu(bool $forceRefresh = false): array
    {
        return $this->getCached(
            'manager_menu',
            '/gb/backend/api/v2/Menu',
            $this->ttlShort(),
            $forceRefresh
        );
    }

    /**
     * Group-wide money and car levels. Returns one entry per manager in
     * the user's group with `cash` and `carLevel` — used by the swap
     * advisor to learn the peer car-strength envelope.
     */
    public function getMoneyLevels(bool $forceRefresh = false): array
    {
        return $this->getCached(
            'money_levels',
            '/gb/backend/api/v2/MoneyLevels',
            $this->ttlShort(),
            $forceRefresh
        );
    }

    /**
     * Currently signed sponsors and ongoing negotiations. `carSpots`
     * lists the 5 ad positions (filled or empty); `ongNegs` lists
     * proposals in flight, each with the sponsorId needed to fetch
     * the sponsor's profile for negotiation-answer recommendations.
     */
    public function getSponsorNegotiations(bool $forceRefresh = false): array
    {
        return $this->getCached(
            'sponsor_negotiations',
            '/gb/backend/api/v2/NegOverview',
            $this->ttlShort(),
            $forceRefresh
        );
    }

    /**
     * One sponsor's detailed profile (the 6 characteristics + metadata).
     * Cached per-sponsor so repeat cockpit renders don't refetch.
     */
    public function getSponsorProfile(int $sponsorId, bool $forceRefresh = false): array
    {
        return $this->getCached(
            'sponsor_profile_' . $sponsorId,
            "/gb/backend/api/v2/NegotiateSponsor?id={$sponsorId}",
            $this->ttlShort(),
            $forceRefresh
        );
    }

    /**
     * Cache key for the last-seen apiRequestsRemaining, so the sync guard can
     * read the budget without spending an API call.
     */
    public const string API_LIMIT_KEY = 'api_requests_remaining';

    /**
     * Last-known remaining API budget, or null if never observed.
     * Read from the shared cache so it survives across requests.
     */
    public function lastKnownRemaining(): ?int
    {
        $v = $this->cache->get(self::API_LIMIT_KEY);
        return is_numeric($v) ? (int) $v : null;
    }

    private function rememberApiLimit(array $data): void
    {
        if (!isset($data['apiRequestsRemaining'])) {
            return;
        }

        $remaining = (int) $data['apiRequestsRemaining'];
        $_SESSION['api_limit'] = $remaining;
        // Hold a little longer than a sync cycle so the guard can read it
        // between race-weekend visits.
        $this->cache->set(self::API_LIMIT_KEY, $remaining, 3600);
    }

    private function getCached(string $key, string $endpoint, int $ttl, bool $force = false): array
    {
        if (!$force) {
            $cached = $this->cache->get($key);
            if ($cached !== null) {
                $this->rememberApiLimit($cached);
                return $cached;
            }
        }

        $data = $this->request($endpoint);
        $this->cache->set($key, $data, $ttl);

        return $data;
    }

    private function request(string $endpoint): array
    {
        if (empty($this->token)) {
            throw new RuntimeException('GPRO API token not set for current user');
        }

        $ch = curl_init($this->baseUrl . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$this->token}",
                'Accept: application/json',
                'User-Agent: GPRO-Assistant/0.2.0',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        // curl_close() is a no-op since PHP 8.0 and deprecated in 8.5; the
        // handle is freed when $ch goes out of scope.
        unset($ch);

        if ($error) {
            throw new RuntimeException($error);
        }

        if ($httpCode !== 200) {
            throw new RuntimeException("API error ({$httpCode})");
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON response');
        }

        $this->rememberApiLimit($data);

        return $data;
    }

    private function ttlShort(): int
    {
        return (int) ($_ENV['CACHE_TTL_SHORT'] ?? 259200);
    }
}
