<?php

declare(strict_types=1);

namespace App\Service;

use App\Cache\CacheInterface;
use App\Repository\ApiArchiveRepository;
use App\Support\Env;
use App\Support\RaceWindow;
use Closure;
use DateTimeImmutable;
use RuntimeException;

/**
 * Friendly facade over the GPRO public API: one method per endpoint, with
 * per-method cache keys. The actual HTTP work lives in GproApiFetcher; this
 * class composes fetch + cache + archive + apiRequestsRemaining bookkeeping.
 *
 * Manager payloads are fetched only inside withFetching(), the gate for sync
 * runs; every other read is served from the cache or the newest archived
 * copy, whatever its age, so rendering a page never spends the API budget.
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

    /** Owner of the raw-payload archive for this client; null disables it. */
    private ?int $archiveUserId = null;

    /** Closed by default: only work run through withFetching() may call GPRO. */
    private bool $fetching = false;

    public function __construct(
        private readonly GproFetcher $fetcher,
        private readonly CacheInterface $cache,
        private readonly ?ApiArchiveRepository $archive = null,
        private readonly ?\Closure $clock = null,
    ) {
    }

    /**
     * @param int|null $userId Owner of the archived payloads. Null (the
     *   default) turns the archive off, so a caller that cannot name the
     *   token's owner can never read or write another user's rows.
     */
    public function setToken(string $token, ?int $userId = null): void
    {
        $this->fetcher->setToken($token);
        // Bind the cache namespace to the exact credential that produces the
        // data. Hash so the raw token never lands in a cache key/filename.
        $this->scope = self::scopeFor($token);
        $this->archiveUserId = $userId;
    }

    /**
     * A copy of this client scoped to $token's cache, for reads only.
     *
     * setToken() mutates the shared client AND its fetcher, and the controller
     * that runs after the layout decides which token those should hold. A
     * layout-level read (the header strip, which renders on every page) must
     * not re-scope that shared instance behind the controller's back, so it
     * works on a clone that only ever changes the cache namespace.
     */
    public function withScopeFor(string $token): self
    {
        $copy = clone $this;
        $copy->scope = self::scopeFor($token);
        // The token may belong to someone other than the archive's owner.
        $copy->archiveUserId = null;
        // A read-only copy: never let it inherit a sync run's open gate.
        $copy->fetching = false;

        return $copy;
    }

    /**
     * Runs $work with GPRO fetches allowed, then puts the gate back as it was,
     * even when $work throws, so a failed sync never leaves it open.
     *
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    public function withFetching(Closure $work): mixed
    {
        $previous = $this->fetching;
        $this->fetching = true;
        try {
            return $work();
        } finally {
            $this->fetching = $previous;
        }
    }

    /** The cache/archive namespace of the token this client is set to. */
    public function tokenScope(): string
    {
        return $this->scope;
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
        return $this->getCached('office_data', '/gb/backend/api/v2/office', $forceRefresh);
    }

    /**
     * Whether the account currently has a driver under contract. After a
     * driver terminates (or is fired), GPRO returns driId as an empty string,
     * so a plain isset() check isn't enough — treat empty/zero as "no pilot".
     */
    public function hasPilot(bool $forceRefresh = false): bool
    {
        $office = $this->getOfficeData($forceRefresh);
        $driId = trim((string) ($office['driId'] ?? ''));
        return $driId !== '' && $driId !== '0';
    }

    /** @return array<string, mixed> */
    public function getMyPilotDetails(bool $forceRefresh = false): array
    {
        // The office only names the driver: $forceRefresh applies to the
        // profile, never to a second office call.
        $office = $this->getOfficeData();
        $driId = trim((string) ($office['driId'] ?? ''));
        if ($driId === '' || $driId === '0') {
            throw new RuntimeException('No driver under contract');
        }

        return $this->getCached(
            'driver_profile_' . $driId,
            "/gb/backend/api/v2/DriProfile?id={$driId}",
            $forceRefresh
        );
    }

    /** @return array<string, mixed> */
    public function getNextRaceProfile(bool $forceRefresh = false): array
    {
        return $this->getCached(
            'next_race_profile',
            '/gb/backend/api/v2/TrackProfile',
            $forceRefresh,
        );
    }

    /** @return array<string, mixed> */
    public function getCarData(bool $forceRefresh = false): array
    {
        return $this->getCached(
            'car_data',
            '/gb/backend/api/v2/UpdateCar',
            $forceRefresh,
        );
    }

    /** @return array<string, mixed> */
    public function getRaceSetup(bool $forceRefresh = false): array
    {
        return $this->getCached(
            'race_setup',
            '/gb/backend/api/v2/RaceSetup',
            $forceRefresh,
        );
    }

    /** @return array<string, mixed> */
    public function getTesting(bool $forceRefresh = false): array
    {
        return $this->getCached(
            'testing',
            '/gb/backend/api/v2/Testing',
            $forceRefresh,
        );
    }

    /** @return array<string, mixed> */
    public function getStaffAndFacilities(bool $forceRefresh = false): array
    {
        return $this->getCached(
            'staff_facilities',
            '/gb/backend/api/v2/StaffAndFacilities',
            $forceRefresh,
        );
    }

    /** @return array<string, mixed> */
    public function getTechnicalDirector(bool $forceRefresh = false): array
    {
        try {
            return $this->getCached('td_profile', '/gb/backend/api/v2/TDProfile', $forceRefresh);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Most recent race analysis for the authenticated manager. Called with no
     * SR query parameter so GPRO returns the latest available race — the spec
     * warns that omitting it can be slow for long-tenured supporters, but the
     * alternative (walking racesToSelect) costs one call per race.
     *
     * Each sync overwrites the one entry; a manager who never races again
     * simply keeps serving the same payload, and the telemetry ingest
     * de-duplicates it away.
     *
     * @return array<string, mixed>
     */
    public function getRaceAnalysis(bool $forceRefresh = false): array
    {
        try {
            return $this->getCached(
                'race_analysis',
                '/gb/backend/api/v2/RaceAnalysis',
                $forceRefresh,
            );
        } catch (\Throwable) {
            // A manager who is not a supporter, or has not raced, gets an
            // error or an NA payload here. Telemetry is strictly best-effort:
            // never let it break a sync.
            return [];
        }
    }

    /**
     * Reads share one cache entry across users, but every real fetch is also
     * archived under the caller: the payload names the manager's own supplier,
     * which is what a per-season record of it needs.
     *
     * @return array<string, mixed>
     */
    public function getTyreSuppliers(bool $forceRefresh = false): array
    {
        return $this->getCached(
            'tyre_suppliers',
            '/gb/backend/api/v2/TyreSuppliers',
            $forceRefresh,
            shared: true,
            archiveShared: true,
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
        return $this->getCached('manager_menu', '/gb/backend/api/v2/Menu', $forceRefresh);
    }

    /** @return array<string, mixed> */
    public function getMoneyLevels(bool $forceRefresh = false): array
    {
        return $this->getCached('money_levels', '/gb/backend/api/v2/MoneyLevels', $forceRefresh);
    }

    /**
     * Group standings with each manager's driver/TD OA (ViewStaff). Used to
     * rank the manager's own driver against the rest of the group.
     *
     * @return array<string, mixed>
     */
    public function getGroupStaff(bool $forceRefresh = false): array
    {
        return $this->getCached('group_staff', '/gb/backend/api/v2/ViewStaff', $forceRefresh);
    }

    /**
     * Season standings of the token account's own group (no Group parameter:
     * GPRO defaults to it). A per-race snapshot, refreshed by each sync.
     *
     * @return array<string, mixed>
     */
    public function getStandings(bool $forceRefresh = false): array
    {
        return $this->getCached(
            'standings',
            '/gb/backend/api/v2/Standings',
            $forceRefresh,
        );
    }

    /**
     * One page of the economy history table, newest first.
     *
     * @return array<string, mixed>
     */
    public function getEconomyHistory(int $page = 1, bool $forceRefresh = false): array
    {
        return $this->getCached(
            'economy_history_' . $page,
            "/gb/backend/api/v2/EconomyHistory?Page={$page}&show=table",
            $forceRefresh,
        );
    }

    /**
     * Staff and facilities of a finished season. Immutable once fetched.
     *
     * @return array<string, mixed>
     */
    public function getSfHistory(int $season, bool $forceRefresh = false): array
    {
        return $this->getImmutable(
            'sf_history_' . $season,
            "/gb/backend/api/v2/SFHistory?season={$season}",
            $forceRefresh,
        );
    }

    /**
     * Car character points of a finished season. Immutable once fetched.
     *
     * @return array<string, mixed>
     */
    public function getCcpHistory(int $season, bool $forceRefresh = false): array
    {
        return $this->getImmutable(
            'ccp_history_' . $season,
            "/gb/backend/api/v2/CCPHistory?season={$season}",
            $forceRefresh,
        );
    }

    /**
     * The analysis of one named, finished race. Unlike getRaceAnalysis() it
     * lets a failure through: the caller decides whether a missing race matters.
     *
     * @return array<string, mixed>
     */
    public function getRaceAnalysisFor(int $season, int $race, bool $forceRefresh = false): array
    {
        return $this->getImmutable(
            "race_analysis_{$season}_{$race}",
            "/gb/backend/api/v2/RaceAnalysis?SR={$season},{$race}",
            $forceRefresh,
        );
    }

    /** @return array<string, mixed> */
    public function getCalendar(bool $forceRefresh = false): array
    {
        return $this->getCached('calendar', '/gb/backend/api/v2/Calendar', $forceRefresh);
    }

    /**
     * The calendar the last sync stored (cache, else archive) — never spends
     * an API call, even inside withFetching(). For surfaces (e.g. Recruitment)
     * that want the calendar opportunistically.
     *
     * @return array<string, mixed>
     */
    public function getCachedCalendar(): array
    {
        return $this->stored($this->userKey('calendar'), '/gb/backend/api/v2/Calendar') ?? [];
    }

    /**
     * Whether every payload the screens read is stored (cache or archive, of
     * any age), so rendering one has data to show. Never fetches.
     */
    public function isWarm(): bool
    {
        $office = $this->stored($this->userKey('office_data'), '/gb/backend/api/v2/office');
        if ($office === null) {
            return false;
        }

        $screens = [
            'calendar' => '/gb/backend/api/v2/Calendar',
            'car_data' => '/gb/backend/api/v2/UpdateCar',
            'race_setup' => '/gb/backend/api/v2/RaceSetup',
            'next_race_profile' => '/gb/backend/api/v2/TrackProfile',
        ];
        foreach ($screens as $key => $endpoint) {
            if ($this->stored($this->userKey($key), $endpoint) === null) {
                return false;
            }
        }

        $driId = trim((string) ($office['driId'] ?? ''));
        if ($driId === '' || $driId === '0') {
            return true;
        }

        return $this->stored(
            $this->userKey('driver_profile_' . $driId),
            "/gb/backend/api/v2/DriProfile?id={$driId}",
        ) !== null;
    }

    /**
     * The Menu the last sync stored — never spends an API call. Used by the
     * global billboard, which renders on every tab.
     *
     * @return array<string, mixed>
     */
    public function getCachedMenu(): array
    {
        return $this->stored($this->userKey('manager_menu'), '/gb/backend/api/v2/Menu') ?? [];
    }

    /**
     * The office the last sync stored — never spends an API call. Same
     * contract as getCachedMenu().
     *
     * @return array<string, mixed>
     */
    public function getCachedOfficeData(): array
    {
        return $this->stored($this->userKey('office_data'), '/gb/backend/api/v2/office') ?? [];
    }

    /**
     * The group's money levels the last sync stored — never spends an API
     * call, so the billboard can rank the manager's cash against the group.
     *
     * @return array<string, mixed>
     */
    public function getCachedMoneyLevels(): array
    {
        return $this->stored($this->userKey('money_levels'), '/gb/backend/api/v2/MoneyLevels') ?? [];
    }

    /**
     * The group's staff as last stored — never spends an API call. The
     * harvest files it once per window; a stale answer beats none.
     *
     * @return array<string, mixed>
     */
    public function getCachedGroupStaff(): array
    {
        return $this->stored($this->userKey('group_staff'), '/gb/backend/api/v2/ViewStaff') ?? [];
    }

    /**
     * The same track list for everyone, but filed under each user who fetches
     * it, like the tyre feed: a sync asks for it once a season, and a rotated
     * shared cache then still has the caller's copy to serve.
     *
     * @return array<string, mixed>
     */
    public function getAllTracksPreview(bool $forceRefresh = false): array
    {
        return $this->getCached(
            'all_tracks_preview',
            '/gb/backend/api/v2/Tracks',
            $forceRefresh,
            shared: true,
            archiveShared: true,
        );
    }

    /** @return array<string, mixed> */
    public function getSponsorNegotiations(bool $forceRefresh = false): array
    {
        return $this->getCached(
            'sponsor_negotiations',
            '/gb/backend/api/v2/NegOverview',
            $forceRefresh,
        );
    }

    /** @return array<string, mixed> */
    public function getSponsorProfile(int $sponsorId, bool $forceRefresh = false): array
    {
        return $this->getCached(
            'sponsor_profile_' . $sponsorId,
            "/gb/backend/api/v2/NegotiateSponsor?id={$sponsorId}",
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

    /** Whether the market dump is cached, so reading it spends no fetch. */
    public function hasCachedMarketFile(string $market = 'drivers'): bool
    {
        return $this->cache->has('market_file_' . $market);
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
     * Stored payload first; only a forced call, or a miss inside
     * withFetching(), reaches GPRO. Outside the gate a miss is [].
     *
     * @param bool $shared When true the key is global (same payload for every
     *   user — static track list, season-wide supplier data).
     *   Default false: the key is namespaced to the current user so per-user
     *   payloads never leak across accounts.
     * @param bool $archiveShared A shared payload is not archived unless this
     *   is set, in which case a real fetch is filed under the calling user,
     *   and a shared-cache miss serves that user's own row without writing it
     *   to the shared key (it may be another season's; only a fetch is shared).
     * @return array<string, mixed>
     */
    private function getCached(
        string $key,
        string $endpoint,
        bool $force,
        bool $shared = false,
        bool $archiveShared = false,
    ): array {
        $key = $shared ? $key : $this->userKey($key);
        $archived = !$shared || $archiveShared;
        if (!$force) {
            $stored = $this->stored($key, $archived ? $endpoint : null, recache: !$shared);
            if ($stored !== null) {
                return $stored;
            }
        }

        $data = $this->fetch($endpoint);
        if ($data === null) {
            return [];
        }
        // No expiry: the next sync overwrites the entry, and until then the
        // newest payload is the right one to show.
        $this->cache->set($key, $data, null);
        $this->rememberApiLimit($data);
        if ($archived) {
            $this->archivePut(...self::splitEndpoint($endpoint), window: $this->archiveWindow(), data: $data);
        }

        return $data;
    }

    /**
     * A payload that never changes once GPRO has produced it (a named finished
     * race, a finished season), so it is filed under the empty window and an
     * existing row is served whatever its age or the current window. A forced
     * call still fetches (inside withFetching()) and refreshes the row.
     *
     * @return array<string, mixed>
     */
    private function getImmutable(string $key, string $endpoint, bool $force): array
    {
        $key = $this->userKey($key);
        if (!$force) {
            $cached = $this->cache->get($key);
            if (is_array($cached)) {
                /** @var array<string, mixed> $cached */
                return $cached;
            }
            $row = $this->readArchive(...self::splitEndpoint($endpoint), window: '');
            if ($row !== null) {
                $this->cache->set($key, $row['payload'], null);

                return $row['payload'];
            }
        }

        $data = $this->fetch($endpoint);
        if ($data === null) {
            return [];
        }
        $this->cache->set($key, $data, null);
        $this->rememberApiLimit($data);
        $this->archivePut(...self::splitEndpoint($endpoint), window: '', data: $data);

        return $data;
    }

    /**
     * The one place a manager payload leaves for GPRO. Null, with no call
     * made, unless a sync run opened the gate (withFetching()).
     *
     * @return array<string, mixed>|null
     */
    private function fetch(string $endpoint): ?array
    {
        return $this->fetching ? $this->fetcher->fetchJson($endpoint) : null;
    }

    /**
     * The newest payload already held for $key, without fetching: the cache,
     * else the newest archived row for $endpoint under this token, whatever
     * its window or age (re-cached without expiry unless $recache is false).
     * A null $endpoint reads the cache only. Neither touches the remaining-calls counter: a stored
     * payload's apiRequestsRemaining is older than the session's, and writing
     * it back would make the header badge ping-pong between tabs.
     *
     * @return array<string, mixed>|null
     */
    private function stored(string $key, ?string $endpoint, bool $recache = true): ?array
    {
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            /** @var array<string, mixed> $cached */
            return $cached;
        }
        if ($endpoint === null) {
            return null;
        }
        $payload = $this->latestArchived(...self::splitEndpoint($endpoint));
        if ($payload !== null && $recache) {
            $this->cache->set($key, $payload, null);
        }

        return $payload;
    }

    /**
     * "/gb/backend/api/v2/DriProfile?id=7" → ['DriProfile', 'id=7'].
     *
     * @return array{string, string}
     */
    private static function splitEndpoint(string $path): array
    {
        $versioned = strpos($path, '/v2/');
        $rest = $versioned === false ? ltrim($path, '/') : substr($path, $versioned + 4);
        $parts = explode('?', $rest, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    /**
     * Archive failures must never break a page or a sync: log the class only
     * (a message could carry payload data) and carry on as if there were none.
     *
     * @return array{payload: array<string, mixed>, fetched_at: string, season: ?int, race: ?int}|null
     */
    private function readArchive(string $endpoint, string $params, string $window): ?array
    {
        if ($this->archive === null || $this->archiveUserId === null) {
            return null;
        }
        try {
            return $this->archive->getRow($this->archiveUserId, $this->scope, $endpoint, $params, $window);
        } catch (\Throwable $e) {
            error_log('[ApiArchive] ' . $e::class);

            return null;
        }
    }

    /**
     * Newest archived payload of the endpoint under this client's owner and
     * token, of any window or age. Same failure contract as readArchive().
     *
     * @return array<string, mixed>|null
     */
    private function latestArchived(string $endpoint, string $params): ?array
    {
        if ($this->archive === null || $this->archiveUserId === null) {
            return null;
        }
        try {
            return $this->archive->latest($this->archiveUserId, $endpoint, $params, $this->scope);
        } catch (\Throwable $e) {
            error_log('[ApiArchive] ' . $e::class);

            return null;
        }
    }

    /** @param array<string, mixed> $data */
    private function archivePut(string $endpoint, string $params, string $window, array $data): void
    {
        if ($this->archive === null || $this->archiveUserId === null) {
            return;
        }
        // The office payload names its own season and race, and a race
        // analysis the race it analyses (the office's raceNb is the NEXT
        // race); every other endpoint is dated by the office the page was
        // built from.
        if ($endpoint === 'RaceAnalysis') {
            [$season, $race] = [$data['selSeasonNb'] ?? null, $data['selRaceNb'] ?? null];
        } else {
            $stamp = $endpoint === 'office' ? $data : $this->getCachedOfficeData();
            [$season, $race] = [$stamp['seasonNb'] ?? null, $stamp['raceNb'] ?? null];
        }
        try {
            $this->archive->put(
                $this->archiveUserId,
                $this->scope,
                $endpoint,
                $params,
                $window,
                self::intOrNull($season),
                self::intOrNull($race),
                $data,
            );
        } catch (\Throwable $e) {
            error_log('[ApiArchive] ' . $e::class);
        }
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
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

    private function now(): DateTimeImmutable
    {
        return $this->clock === null ? new DateTimeImmutable('now') : ($this->clock)();
    }

    /**
     * Window the archive files rows under. It rolls at race start, so the last
     * pre-race snapshot survives as the one that raced. An empty GPRO_RACE_DAYS
     * yields '' (every row filed under one window); reads never depend on it.
     */
    public function archiveWindow(): string
    {
        return RaceWindow::raceStartIdFor(
            $this->now(),
            RaceWindow::parseDays(Env::get('GPRO_RACE_DAYS', '2,5')),
        );
    }

    /**
     * When the race of the current archive window is over (21:00 Paris), or
     * null when windowing is disabled.
     */
    public function postRaceAt(): ?DateTimeImmutable
    {
        $window = $this->archiveWindow();

        return $window === '' ? null : RaceWindow::postRaceAt($window);
    }

    /**
     * Notes that this window's RaceAnalysis was asked for after the race
     * ended, even though the answer was an error: the next login does not ask
     * again. Per user, like every cache key here.
     */
    public function markAnalysisAttempt(): void
    {
        $window = $this->archiveWindow();
        if ($window === '' || $this->now() < RaceWindow::postRaceAt($window)) {
            return;
        }
        $this->cache->set($this->userKey('analysis_attempt:w' . $window), time(), 8 * 86400);
    }

    public function hasAnalysisAttempt(): bool
    {
        $window = $this->archiveWindow();

        return $window !== '' && $this->cache->has($this->userKey('analysis_attempt:w' . $window));
    }

    /**
     * How many backfill (`SR=`) analyses this user's harvest has fetched in the
     * current race window: the planner's allowance is per window, not per slice.
     */
    public function backfillCount(): int
    {
        $count = $this->cache->get($this->userKey('backfill_count:w' . $this->archiveWindow()));

        return is_numeric($count) ? (int) $count : 0;
    }

    public function addBackfill(): void
    {
        $this->cache->set(
            $this->userKey('backfill_count:w' . $this->archiveWindow()),
            $this->backfillCount() + 1,
            8 * 86400,
        );
    }

    /**
     * Remembers that a harvest fetch failed, so the next slices plan other work
     * instead of retrying it at once; it is tried again after $ttl seconds.
     */
    public function markHarvestFailed(string $endpoint, string $params, int $ttl = 3600): void
    {
        $this->cache->set($this->harvestFailedKey($endpoint, $params), time(), $ttl);
    }

    public function hasHarvestFailed(string $endpoint, string $params): bool
    {
        return $this->cache->has($this->harvestFailedKey($endpoint, $params));
    }

    private function harvestFailedKey(string $endpoint, string $params): string
    {
        return $this->userKey('harvest_failed:w' . $this->archiveWindow() . ':' . $endpoint . ':' . $params);
    }

    /**
     * Files the office a sync is about to plan against under params 'sync', so
     * the next sync detects changes against it and not against a page-level
     * office fetch (which archives under ''). Read-through only ever asks for
     * '', so this row is never served as a page's office.
     *
     * @param array<string, mixed> $office
     */
    public function archiveSyncOffice(array $office): void
    {
        $this->archivePut('office', 'sync', $this->archiveWindow(), $office);
    }
}
