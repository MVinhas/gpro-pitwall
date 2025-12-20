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

    public function getMarketData(): array
    {
        return $this->getCached(
            'market_data',
            '/gb/backend/api/v2/AvailDrivers',
            $this->ttlVeryShort()
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

    private function getCached(string $key, string $endpoint, int $ttl, bool $force = false): array
    {
        if (!$force) {
            $cached = $this->cache->get($key);
            if ($cached !== null) {
                if (isset($cached['apiRequestsRemaining'])) {
                    $_SESSION['api_limit'] = $cached['apiRequestsRemaining'];
                }

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
                'User-Agent: GPRO-Assistant/1.0',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

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

        if (isset($data['apiRequestsRemaining'])) {
            $_SESSION['api_limit'] = $data['apiRequestsRemaining'];
        }

        return $data;
    }

    private function ttlShort(): int
    {
        return (int) ($_ENV['CACHE_TTL_SHORT'] ?? 259200);
    }

    private function ttlVeryShort(): int
    {
        return (int) ($_ENV['CACHE_TTL_VERY_SHORT'] ?? 3600);
    }
}
