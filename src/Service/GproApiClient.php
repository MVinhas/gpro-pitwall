<?php
namespace App\Service;

class GproApiClient
{
    private string $baseUrl;
    private string $token;

    public function __construct(array $config)
    {
        $this->baseUrl = rtrim($config['base_url'], '/');
        $this->token = $config['token'];
    }

    // --- PUBLIC METHODS WITH CACHING ---

    public function getOfficeData(bool $forceRefresh = false): array
    {
        // Cache Office for 5 minutes to capture API Limit updates occasionally
        return $this->getCached('office_data', '/gb/backend/api/v2/office', 300, $forceRefresh);
    }

    public function getMyPilotDetails(bool $forceRefresh = false): array
    {
        // 1. Get Office (Cached)
        $office = $this->getOfficeData($forceRefresh);
        
        if (!isset($office['driId'])) {
            throw new \Exception("Could not find Driver ID in Office data.");
        }

        // 2. Get Profile (Cached for 1 hour)
        $cacheKey = 'driver_profile_' . $office['driId'];
        return $this->getCached($cacheKey, "/gb/backend/api/v2/DriProfile?id={$office['driId']}", 3600, $forceRefresh);
    }

    public function getNextRaceProfile(bool $forceRefresh = false): array
    {
        // Cache Track for 1 hour
        return $this->getCached('next_race_profile', '/gb/backend/api/v2/TrackProfile', 3600, $forceRefresh);
    }

    public function getCarData(bool $forceRefresh = false): array
    {
        // Cache Car for 15 minutes
        return $this->getCached('car_data', '/gb/backend/api/v2/UpdateCar', 900, $forceRefresh);
    }

    public function getMarketData(): array
    {
        // Market should probably not be cached too long, maybe 5 mins
        return $this->getCached('market_data', '/gb/backend/api/v2/AvailDrivers', 300);
    }

    // --- INTERNAL CACHING LOGIC ---

    private function getCached(string $key, string $endpoint, int $ttl, bool $force = false): array
    {
        // Check Cache
        if (!$force && isset($_SESSION['api_cache'][$key])) {
            $cached = $_SESSION['api_cache'][$key];
            if (time() < $cached['expires_at']) {
                // Update Global API Limit from cache if available (so UI doesn't show 0)
                if (isset($cached['data']['apiRequestsRemaining'])) {
                    $_SESSION['api_limit'] = $cached['data']['apiRequestsRemaining'];
                }
                return $cached['data'];
            }
        }

        // Fetch Fresh
        $data = $this->get($endpoint);

        // Save to Cache
        $_SESSION['api_cache'][$key] = [
            'expires_at' => time() + $ttl,
            'data' => $data
        ];

        return $data;
    }

    private function get(string $endpoint): array
    {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$this->token}",
            "Accept: application/json",
            "User-Agent: GPRO-Driver-Analyzer/1.0"
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("Connection Error: $error");
        }

        if ($httpCode === 401 || $httpCode === 403) {
            throw new \Exception("Auth Failed ($httpCode). Check Token.");
        }

        if ($httpCode !== 200) {
            throw new \Exception("API Error ($httpCode)");
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid JSON Response");
        }

        // **CAPTURE API LIMIT**
        // The office endpoint usually has this at the root
        if (isset($data['apiRequestsRemaining'])) {
            $_SESSION['api_limit'] = $data['apiRequestsRemaining'];
        }

        return $data;
    }
}