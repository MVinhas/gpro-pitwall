<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Security\Authorize;
use App\Service\GproSyncService;

final readonly class ApiWarmupController
{
    public function __construct(
        private GproSyncService $syncService,
        private Authorize $authorize,
    ) {
    }

    public function warmup(Request $request): void
    {
        // Whitelisted: free users can sync their OWN data — that's the
        // prerequisite for the app to show them anything personalised.
        $user = $this->authorize->requireAuth();

        if (empty($user['api_token'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No API token configured']);
            return;
        }

        $this->syncService->trySyncForUser($user, true);
    }
}
