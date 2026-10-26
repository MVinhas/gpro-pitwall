<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Sections;
use App\Security\Authorize;
use App\Service\SeasonService;
use Twig\Environment;

/** Season — one season's results, money, team changes and model calibration. */
final readonly class SeasonController
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private SeasonService $season,
        private Authorize $authorize,
        private Environment $twig,
        private array $config,
    ) {
    }

    public function index(Request $request): void
    {
        $user = $this->authorize->requireAdmin();

        $requested = $request->get('season');
        $season = is_string($requested) && $requested !== '' && ctype_digit($requested)
            ? (int) $requested
            : null;

        $view = $this->season->build((int) $user['id'], $season);

        unset($user['api_token']);

        /** @var list<string> $sections */
        $sections = $this->config['app']['main_sections'] ?? [];

        echo $this->twig->render('season/index.twig', array_merge($view, [
            'is_logged_in'  => true,
            'user'          => $user,
            'csrf_token'    => $_SESSION['csrf_token'] ?? '',
            'api_limit'     => $_SESSION['api_limit'] ?? '?',
            'api_limit_updated_at' => $_SESSION['api_limit_updated_at'] ?? null,
            'main_sections'   => array_values(array_filter(
                $sections,
                static fn (string $section): bool => $section !== 'Login',
            )),
            'active_main_tab' => 'Season',
            'section_paths'   => Sections::all(),
            'active_division' => null,
            'active_track'    => null,
        ]));
    }
}
