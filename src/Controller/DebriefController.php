<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Security\Authorize;
use App\Service\DebriefService;
use Twig\Environment;

/**
 * The telemetry area: Bird's Eye View, Track History, and Insights.
 *
 * Every screen requires a login, because "my races" is half of what each one
 * shows. The other half — the anonymous corpus — carries no identity, so the
 * scope switch can offer "everyone" without any screen ever being able to
 * single out another manager.
 */
final readonly class DebriefController
{
    /** Sub-tabs, in the order the nav renders them. */
    private const array TABS = [
        'birdseye' => "Bird's Eye View",
        'track'    => 'Track History',
        'insights' => 'Insights',
    ];

    /** Sort keys the list accepts; anything else falls back to the default. */
    private const array SORTS = [
        'impressive' => 'Most impressive',
        'recent'     => 'Most recent',
        'position'   => 'Best finish',
        'gained'     => 'Most places gained',
        'quali'      => 'Best qualifying',
    ];

    /** @param array<string, mixed> $config */
    public function __construct(
        private DebriefService $dashboard,
        private Authorize $authorize,
        private Environment $twig,
        private array $config,
    ) {
    }

    /** Bird's Eye View — the filtered, ranked race list. */
    public function index(Request $request): void
    {
        $user = $this->authorize->requireAdmin();
        $userId = (int) $user['id'];

        $scope = $request->get('scope') === 'all' ? 'all' : 'mine';
        $sort = $this->sortFrom($request);
        $filters = $this->filtersFrom($request);

        $view = $this->dashboard->birdsEye($userId, $scope, $filters, $sort);

        // The detail panel always shows one of the manager's OWN races; the
        // corpus has no per-race detail to show and never will.
        $season = $this->intOrNull($request->get('season_detail'));
        $race = $this->intOrNull($request->get('race_detail'));

        $this->render('debrief/birdseye.twig', $user, array_merge($view, [
            'detail' => $this->dashboard->raceDetail($userId, $season, $race),
            'sorts'  => self::SORTS,
            'active_tab' => 'birdseye',
        ]));
    }

    /** Track History — everything known about one circuit. */
    public function track(Request $request): void
    {
        $user = $this->authorize->requireAdmin();
        $userId = (int) $user['id'];

        $tracks = $this->dashboard->trackOptions();
        $trackId = $this->intOrNull($request->get('track'));

        // Default to the best-covered track rather than an empty screen.
        if ($trackId === null && $tracks !== []) {
            $trackId = (int) ($tracks[0]['track_id'] ?? 0);
        }

        $view = $trackId === null || $trackId === 0
            ? ['has_data' => false, 'track_id' => null, 'track_name' => null]
            : $this->dashboard->trackHistory($trackId, $userId);

        $this->render('debrief/track.twig', $user, array_merge($view, [
            'tracks'     => $tracks,
            'active_tab' => 'track',
        ]));
    }

    /** Insights — what the corpus says actually wins. */
    public function insights(Request $request): void
    {
        $user = $this->authorize->requireAdmin();

        $trackId = $this->intOrNull($request->get('track'));
        $view = $this->dashboard->insights($trackId);

        $this->render('debrief/insights.twig', $user, array_merge($view, [
            'active_tab' => 'insights',
        ]));
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $view
     */
    private function render(string $template, array $user, array $view): void
    {
        unset($user['api_token']);

        echo $this->twig->render($template, array_merge($view, [
            'tabs'          => self::TABS,
            'is_logged_in'  => true,
            'user'          => $user,
            'csrf_token'    => $_SESSION['csrf_token'] ?? '',
            'api_limit'     => $_SESSION['api_limit'] ?? '?',
            'api_limit_updated_at' => $_SESSION['api_limit_updated_at'] ?? null,

            // The app-wide tab bar renders from these. The Debrief sits on its
            // own routes rather than a ?main_tab= panel, so the values a page
            // controller would normally supply have to be passed here too.
            'main_sections'   => $this->mainSections(),
            'active_main_tab' => 'Debrief',
            'section_paths'   => \App\Http\Sections::all(),
            'active_division' => null,
            'active_track'    => null,
        ]));
    }

    /**
     * The nav's section list. Every entry is admin-only territory by the time
     * this runs — requireAdmin() has already gated the request — so the list is
     * passed through whole rather than re-filtered.
     *
     * @return list<string>
     */
    private function mainSections(): array
    {
        /** @var list<string> $sections */
        $sections = $this->config['app']['main_sections'] ?? [];

        return array_values(array_filter(
            $sections,
            static fn (string $section): bool => $section !== 'Login',
        ));
    }

    /**
     * Reads the filter set off the query string. Values are passed through as
     * strings and whitelisted by key in the repositories — nothing here is ever
     * interpolated into SQL.
     *
     * @return array<string, int|string|null>
     */
    private function filtersFrom(Request $request): array
    {
        $filters = [];
        foreach (['track', 'season', 'race', 'supplier', 'compound', 'level', 'wet'] as $key) {
            $value = $request->get($key);
            $filters[$key] = is_string($value) && $value !== '' ? $value : null;
        }

        foreach (['quali_max', 'finish_max'] as $key) {
            $filters[$key] = $this->intOrNull($request->get($key));
        }

        return $filters;
    }

    private function sortFrom(Request $request): string
    {
        $sort = $request->get('sort');

        return is_string($sort) && isset(self::SORTS[$sort]) ? $sort : 'impressive';
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && $value !== '' && ctype_digit($value)
            ? (int) $value
            : null;
    }
}
