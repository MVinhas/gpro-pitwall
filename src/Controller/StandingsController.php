<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Sections;
use App\Security\Authorize;
use App\Service\GproApiClient;
use App\Service\StandingsService;
use Twig\Environment;

/**
 * The group's season table and what it takes to win, promote or stay up.
 * Read from the archive only: the page never calls GPRO.
 */
final readonly class StandingsController
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private StandingsService $standings,
        private Authorize $authorize,
        private Environment $twig,
        private array $config,
    ) {
    }

    public function index(Request $request): void
    {
        $user = $this->authorize->requireAuth();

        $token = is_string($user['api_token'] ?? null) ? trim($user['api_token']) : '';
        $scope = $token === '' ? null : GproApiClient::scopeFor($token);
        $view = $this->standings->build((int) $user['id'], $scope);

        unset($user['api_token']);

        $app = is_array($this->config['app'] ?? null) ? $this->config['app'] : [];

        echo $this->twig->render('standings/index.twig', array_merge($view, [
            'is_logged_in'  => true,
            'user'          => $user,
            'csrf_token'    => $_SESSION['csrf_token'] ?? '',
            'api_limit'     => $_SESSION['api_limit'] ?? '?',
            'api_limit_updated_at' => $_SESSION['api_limit_updated_at'] ?? null,
            'main_sections'   => Sections::visible(
                is_array($app['main_sections'] ?? null) ? array_values($app['main_sections']) : [],
                !empty($user['is_admin']),
            ),
            'active_main_tab' => 'Standings',
            'section_paths'   => Sections::all(),
            'active_division' => null,
            'active_track'    => null,
        ]));
    }
}
