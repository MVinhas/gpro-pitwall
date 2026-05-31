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

    /** @return array<string, mixed> */
    public function getOfficeData(bool $forceRefresh = false): array
    {
        return $this->getCached(
            'office_data',
            '/gb/backend/api/v2/office',
            $this->ttlShort(),
            $forceRefresh
        );
    }

    /** @return array<string, mixed> */
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

    /** @return array<string, mixed> */
    public function getNextRaceProfile(bool $forceRefresh = false): array
    {
        return $this->getCached(
            'next_race_profile',
            '/gb/backend/api/v2/TrackProfile',
            $this->ttlShort(),
            $forceRefresh
        );
    }

    /** @return array<string, mixed> */
    public function getCarData(bool $forceRefresh = false): array
    {
        return $this->getCached(
            'car_data',
            '/gb/backend/api/v2/UpdateCar',
            $this->ttlShort(),
            $forceRefresh
        );
    }

    /** @return array<string, mixed> */
    public function getRaceSetup(bool $forceRefresh = false): array
    {
        return $this->getCached(
            'race_setup',
            '/gb/backend/api/v2/RaceSetup',
            $this->ttlShort(),
            $forceRefresh
        );
    }

    /** @return array<string, mixed> */
    public function getStaffAndFacilities(bool $forceRefresh = false): array
    {
        return $this->getCached(
            'staff_facilities',
            '/gb/backend/api/v2/StaffAndFacilities',
            $this->ttlShort(),
            $forceRefresh
        );
    }

    /** @return array<string, mixed> */
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

    /** @return array<string, mixed> */
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
     *
     * @return array<string, mixed>
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
     *
     * @return array<string, mixed>
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
     * Current + next-season calendar with per-race trackId. Used by
     * the cockpit's testing target-race lookup. `nextSeasonPublished`
     * gates whether `nextSeasonEvents` are usable.
     *
     * @return array<string, mixed>
     */
    public function getCalendar(bool $forceRefresh = false): array
    {
        return $this->getCached(
            'calendar',
            '/gb/backend/api/v2/Calendar',
            $this->ttlShort(),
            $forceRefresh
        );
    }

    /**
     * Every track's PHA + lap metadata in one call. Tracks are stable
     * across the season — cache for hours so repeat cockpit renders
     * don't refetch.
     *
     * @return array<string, mixed>
     */
    public function getAllTracksPreview(bool $forceRefresh = false): array
    {
        return $this->getCached(
            'all_tracks_preview',
            '/gb/backend/api/v2/Tracks',
            21600,
            $forceRefresh
        );
    }

    /**
     * Currently signed sponsors and ongoing negotiations. `carSpots`
     * lists the 5 ad positions (filled or empty); `ongNegs` lists
     * proposals in flight, each with the sponsorId needed to fetch
     * the sponsor's profile for negotiation-answer recommendations.
     *
     * @return array<string, mixed>
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
     *
     * @return array<string, mixed>
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

    /** @param array<string, mixed> $data */
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

    /**
     * Hourly-refreshed full-market dump. Public endpoint (no token),
     * response is gzip-encoded JSON / CSV / XML. We always use JSON,
     * gunzip in-process, and cache the decoded payload for ~55 minutes
     * to stay just inside the upstream refresh cadence.
     *
     * GPRO returns the payload as `{"Last updated": "...", "<market>": [...]}`.
     * This method unwraps it to `{updated_at, rows}` so callers don't
     * have to dance around the space in the key name.
     *
     * @param string $market `drivers` or `tds`
     * @return array{updated_at: ?string, rows: list<array<string, mixed>>}
     */
    public function getMarketFile(string $market = 'drivers', bool $forceRefresh = false): array
    {
        $key = 'market_file_' . $market;
        if (!$forceRefresh) {
            $cached = $this->cache->get($key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $url = $this->baseUrl . "/GetMarketFile.asp?market={$market}&type=json";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '', // accept any encoding; curl will gunzip automatically when supported
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Accept-Encoding: gzip',
                'User-Agent: GPRO-Assistant/0.2.0',
            ],
        ]);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        unset($ch);

        if ($error) {
            throw new RuntimeException("Market file fetch failed: {$error}");
        }
        if ($httpCode !== 200 || !is_string($raw)) {
            throw new RuntimeException("Market file fetch failed: HTTP {$httpCode}");
        }

        // Detect raw gzip (curl didn't unzip — happens when the host doesn't
        // declare Content-Encoding) and gunzip in PHP.
        if (str_starts_with($raw, "\x1f\x8b")) {
            $decoded = @gzdecode($raw);
            if ($decoded === false) {
                throw new RuntimeException('Market file gunzip failed');
            }
            $raw = $decoded;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Market file JSON decode failed');
        }

        $rows = $data[$market] ?? null;
        if (!is_array($rows)) {
            throw new RuntimeException("Market file payload missing `{$market}` array");
        }

        $payload = [
            'updated_at' => isset($data['Last updated']) ? (string) $data['Last updated'] : null,
            'rows'       => array_values(array_filter($rows, 'is_array')),
        ];

        $this->cache->set($key, $payload, 3300);
        return $payload;
    }

    /** @return array<string, mixed> */
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

    /** @return array<string, mixed> */
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
