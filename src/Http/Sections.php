<?php

declare(strict_types=1);

namespace App\Http;

/**
 * The map between a screen's canonical name and its URL path.
 *
 * Screens used to be selected with `/?main_tab=Race%20Strategy`, which made the
 * path meaningless — every screen was the same URL, a relative link resolved
 * against whatever page you were on, and the section name leaked its display
 * spelling into every bookmark. Each screen now has a real path, and this class
 * is the only place the two are related.
 *
 * Canonical names stay exactly as they are in config/game_constants.php: they
 * are what the templates switch on, so renaming a URL must never mean renaming
 * a section.
 */
final class Sections
{
    /**
     * Canonical section name => URL path.
     *
     * Paths are lowercase and shorter than the display names on purpose: a URL
     * is typed and read aloud, a tab label is not.
     */
    private const array PATHS = [
        'Cockpit'              => '/cockpit',
        'Race Strategy'        => '/strategy',
        'Testing'              => '/testing',
        'Training Planner'     => '/training',
        'Recruitment Analyzer' => '/recruitment',
        'Debrief'              => '/debrief',
        'Division Baseline'    => '/divisions/baseline',
        'Division Differences' => '/divisions/differences',
    ];

    /**
     * Spellings that must keep resolving. The first three are the short labels
     * the nav has always shown; 'Car Wear' is the tab retired in 1.15.9, whose
     * bookmarks should land on the cockpit card that replaced it.
     */
    private const array ALIASES = [
        'Strategy'    => 'Race Strategy',
        'Training'    => 'Training Planner',
        'Recruitment' => 'Recruitment Analyzer',
        'Car Wear'    => 'Cockpit',
    ];

    /** The screen a bare '/' means for a signed-in manager. */
    public const string DEFAULT_SECTION = 'Cockpit';

    /** Resolve a display spelling or alias to its canonical section name. */
    public static function canonical(string $section): string
    {
        return self::ALIASES[$section] ?? $section;
    }

    /** The path for a section, or null when the name is not a section. */
    public static function pathFor(string $section): ?string
    {
        return self::PATHS[self::canonical($section)] ?? null;
    }

    /** The section a path selects, or null when the path is not a section. */
    public static function sectionFor(string $path): ?string
    {
        $path = rtrim($path, '/');
        if ($path === '') {
            return null;
        }

        return array_search($path, self::PATHS, true) ?: null;
    }

    /**
     * Every section path, for the route table.
     *
     * @return array<string, string> canonical name => path
     */
    public static function all(): array
    {
        return self::PATHS;
    }

    /**
     * Build a link to a section, carrying the state that belongs in the query
     * string.
     *
     * The division and the track are *selections within* a screen rather than
     * screens of their own, so they stay query parameters — putting them in the
     * path would make "the same screen, different filter" look like a different
     * resource.
     *
     * @param array<string, string|null> $params
     */
    public static function url(string $section, array $params = []): string
    {
        $path = self::pathFor($section) ?? '/';

        $query = array_filter(
            $params,
            static fn (?string $v): bool => $v !== null && $v !== '',
        );

        return $query === [] ? $path : $path . '?' . http_build_query($query);
    }
}
