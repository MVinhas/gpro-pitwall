<?php

declare(strict_types=1);

namespace App\Service;

use App\Cache\CacheInterface;
use RuntimeException;

/**
 * Friendly facade over the GPRO public API: one method per endpoint, with
 * per-method cache keys and TTLs. The actual HTTP work lives in
 * GproApiFetcher; this class composes fetch + cache + apiRequestsRemaining
 * bookkeeping.
 */
final class GproApiClient
{
    /**
     * Per-user cache namespace, derived from the active token. Every
     * user-specific cache key is prefixed with this so one manager's data
     * (driver, car, sponsors, budget…) can never be served to another.
     * Empty until setToken() runs.
     */
    private string $scope = '';

    public function __construct(
        private readonly GproApiFetcher $fetcher,
        private readonly CacheInterface $cache,
    ) {
    }

    public function setToken(string $token): void
    {
        $this->fetcher->setToken($token);
        // Bind the cache namespace to the exact credential that produces the
        // data. Hash so the raw token never lands in a cache key/filename.
        $this->scope = self::scopeFor($token);
    }

    /** Stable per-user cache namespace for a token (no raw token exposed). */
    public static function scopeFor(string $token): string
    {
        return substr(hash('sha256', $token), 0, 16);
    }

    /**
     * Cache key under which a token's remaining-API-budget counter lives.
     * Public + static so callers/tests can address it without a live client.
     */
    public static function budgetKeyForToken(string $token): string
    {
        return 'u' . self::scopeFor($token) . ':api_requests_remaining';
    }

    /** Prefix a per-user key with the active token's namespace. */
    private function userKey(string $key): string
    {
        return 'u' . $this->scope . ':' . $key;
    }

    /** Cache key for this user's remaining-API-budget counter. */
    private function apiLimitKey(): string
    {
        return $this->userKey('api_requests_remaining');
    }

    /** @return array<string, mixed> */
    public function getOfficeData(bool $forceRefresh = false): array
    {
        return $this->getCached('office_data', '/gb/backend/api/v2/office', $this->ttlShort(), $forceRefresh);
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
            $forceRefresh,
        );
    }

    /** @return array<string, mixed> */
    public function getCarData(bool $forceRefresh = false): array
    {
        return $this->getCached('car_data', '/gb/backend/api/v2/UpdateCar', $this->ttlShort(), $forceRefresh);
    }

    /** @return array<string, mixed> */
    public function getRaceSetup(bool $forceRefresh = false): array
    {
        return $this->getCached('race_setup', '/gb/backend/api/v2/RaceSetup', $this->ttlShort(), $forceRefresh);
    }

    /** @return array<string, mixed> */
    public function getStaffAndFacilities(bool $forceRefresh = false): array
    {
        return $this->getCached(
            'staff_facilities',
            '/gb/backend/api/v2/StaffAndFacilities',
            $this->ttlShort(),
            $forceRefresh,
        );
    }

    /** @return array<string, mixed> */
    public function getTechnicalDirector(bool $forceRefresh = false): array
    {
        try {
            return $this->getCached('td_profile', '/gb/backend/api/v2/TDProfile', $this->ttlShort(), $forceRefresh);
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
            $forceRefresh,
            shared: true,
        );
    }

    /**
     * Resolves a tyre supplier by its GPRO id from the TyreSuppliers feed.
     * The feed is the single source of truth for supplier characteristics
     * (durability, performance, peak temp, …), which GPRO can change per
     * season — so we never hardcode them. Returns null if the id isn't
     * found (e.g. feed unavailable); callers fall back to secrets.
     *
     * @return array<string, mixed>|null
     */
    public function findTyreSupplierById(int $supplierId): ?array
    {
        $suppliers = $this->getTyreSuppliers()['suppliers'] ?? [];
        if (!is_array($suppliers)) {
            return null;
        }
        foreach ($suppliers as $supplier) {
            if (is_array($supplier) && (int) ($supplier['id'] ?? 0) === $supplierId) {
                return $supplier;
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    public function getMenu(bool $forceRefresh = false): array
    {
        return $this->getCached('manager_menu', '/gb/backend/api/v2/Menu', $this->ttlShort(), $forceRefresh);
    }

    /** @return array<string, mixed> */
    public function getMoneyLevels(bool $forceRefresh = false): array
    {
        return $this->getCached('money_levels', '/gb/backend/api/v2/MoneyLevels', $this->ttlShort(), $forceRefresh);
    }

    /** @return array<string, mixed> */
    public function getCalendar(bool $forceRefresh = false): array
    {
        return $this->getCached('calendar', '/gb/backend/api/v2/Calendar', $this->ttlShort(), $forceRefresh);
    }

    /**
     * Returns the calendar only if it's already in cache — never spends an
     * API call. Used by surfaces (e.g. Recruitment) that want the calendar
     * opportunistically but must not trigger a fetch. The per-user sync
     * already warms this key, so it's normally a hit.
     *
     * @return array<string, mixed>
     */
    public function getCachedCalendar(): array
    {
        $cached = $this->cache->get($this->userKey('calendar'));
        return is_array($cached) ? $cached : [];
    }

    /** @return array<string, mixed> */
    public function getAllTracksPreview(bool $forceRefresh = false): array
    {
        return $this->getCached('all_tracks_preview', '/gb/backend/api/v2/Tracks', 21600, $forceRefresh, shared: true);
    }

    /** @return array<string, mixed> */
    public function getSponsorNegotiations(bool $forceRefresh = false): array
    {
        return $this->getCached(
            'sponsor_negotiations',
            '/gb/backend/api/v2/NegOverview',
            $this->ttlShort(),
            $forceRefresh,
        );
    }

    /** @return array<string, mixed> */
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
     * Hourly-refreshed full-market dump. GPRO returns
     * `{"Last updated": "...", "<market>": [...]}`; this method unwraps it
     * to `{updated_at, rows}` so callers don't have to dance around the
     * space in the key name.
     *
     * @return array{updated_at: ?string, rows: list<array<string, mixed>>}
     */
    public function getMarketFile(string $market = 'drivers', bool $forceRefresh = false): array
    {
        $key = 'market_file_' . $market;
        if (!$forceRefresh) {
            $cached = $this->cache->get($key);
            if (is_array($cached)) {
                /** @var array{updated_at: ?string, rows: list<array<string, mixed>>} $cached */
                return $cached;
            }
        }

        $data = $this->fetcher->fetchMarketFile($market);
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

    /**
     * Last-known remaining API budget, or null if never observed.
     * Read from the shared cache so it survives across requests.
     */
    public function lastKnownRemaining(): ?int
    {
        $v = $this->cache->get($this->apiLimitKey());
        return is_numeric($v) ? (int) $v : null;
    }

    /**
     * @param bool $shared When true the key is global (same payload for every
     *   user — market dump, static track list, season-wide supplier data).
     *   Default false: the key is namespaced to the current user so per-user
     *   payloads never leak across accounts.
     * @return array<string, mixed>
     */
    private function getCached(string $key, string $endpoint, int $ttl, bool $force, bool $shared = false): array
    {
        $key = $shared ? $key : $this->userKey($key);
        if (!$force) {
            $cached = $this->cache->get($key);
            if ($cached !== null) {
                /** @var array<string, mixed> $cached */
                // Cache hits do NOT update the remaining-calls counter:
                // the cached payload carries an apiRequestsRemaining value
                // from the moment it was originally fetched, which is older
                // than what's in the session right now. Writing it back
                // would rewind the counter and make the header badge
                // ping-pong as the user navigates between tabs.
                return $cached;
            }
        }

        $data = $this->fetcher->fetchJson($endpoint);
        $this->cache->set($key, $data, $ttl);
        $this->rememberApiLimit($data);

        return $data;
    }

    /**
     * Spend exactly one API call to refresh the apiRequestsRemaining counter
     * to its real current value. Used by PageController to keep the header
     * badge honest during idle periods (cache hits don't refresh it).
     */
    public function refreshBudgetCounter(): void
    {
        try {
            // getOfficeData is the lightest authenticated endpoint we use.
            // Force-refresh so the response (and its apiRequestsRemaining)
            // is read fresh from the API, not from cache.
            $this->getOfficeData(forceRefresh: true);
        } catch (\Throwable) {
            // Swallow — a probe failure shouldn't block page rendering.
            // The next attempt will retry.
        }
    }

    /** @param array<string, mixed> $data */
    private function rememberApiLimit(array $data): void
    {
        if (!isset($data['apiRequestsRemaining'])) {
            return;
        }

        $remaining = (int) $data['apiRequestsRemaining'];
        $_SESSION['api_limit'] = $remaining;
        $_SESSION['api_limit_updated_at'] = time();
        $this->cache->set($this->apiLimitKey(), $remaining, 3600);
    }

    private function ttlShort(): int
    {
        return (int) ($_ENV['CACHE_TTL_SHORT'] ?? 259200);
    }
}
