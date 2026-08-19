<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Security\Authorize;
use App\Service\RaceIntelligenceService;
use Twig\Environment;

/**
 * Admin-only race intelligence screen. Reads aggregates over the anonymous
 * corpus; there is no per-user view to expose because the corpus stores no
 * user column.
 */
final readonly class AdminTelemetryController
{
    public function __construct(
        private RaceIntelligenceService $intelligence,
        private Authorize $authorize,
        private Environment $twig,
    ) {
    }

    public function index(Request $request): void
    {
        $admin = $this->authorize->requireAdmin();

        echo $this->twig->render('admin/telemetry.twig', array_merge(
            $this->intelligence->report(),
            [
                'is_logged_in' => true,
                'user'         => $admin,
                'csrf_token'   => $_SESSION['csrf_token'] ?? '',
                'api_limit'    => $_SESSION['api_limit'] ?? '?',
            ],
        ));
    }
}
