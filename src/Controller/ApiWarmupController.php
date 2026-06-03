<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Security\Authorize;
use App\Service\GproApiClient;
use App\Service\GproSyncService;

final readonly class ApiWarmupController
{
    public function __construct(
        private GproSyncService $syncService,
        private GproApiClient $apiClient,
        private Authorize $authorize,
    ) {
    }

    public function warmup(Request $request): void
    {
        // Whitelisted: free users can sync their OWN data — that's the
        // prerequisite for the app to show them anything personalised.
        $user = $this->authorize->requireAuth();

        header('Content-Type: application/json');

        if (empty($user['api_token'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'status' => 'needs_token', 'message' => 'No API token configured']);
            return;
        }

        $status = $this->syncService->trySyncForUser($user, true);

        $messages = [
            'synced'              => 'API cache ready',
            'in_progress'         => 'A sync is already running',
            'deferred_low_budget' => 'Sync paused — GPRO API budget low',
            'needs_token'         => 'No API token configured',
            'failed'              => 'Sync failed — check your API token',
        ];

        // 'synced' and 'in_progress' are both non-error outcomes from the
        // user's perspective; everything else is a soft failure.
        $ok = in_array($status, ['synced', 'in_progress'], true);
        if (!$ok) {
            http_response_code(409);
        }

        echo json_encode([
            'success' => $ok,
            'status'  => $status,
            'message' => $messages[$status] ?? 'Unknown sync state',
        ]);
    }

    /**
     * Spend exactly one API call to refresh the budget counter. The user
     * explicitly opted in via the modal (refreshing during idle is
     * otherwise expensive, so we never do it automatically).
     */
    public function refreshBudget(Request $request): void
    {
        $user = $this->authorize->requireAuth();

        header('Content-Type: application/json');

        if (empty($user['api_token'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'No API token configured',
            ]);
            return;
        }

        $this->apiClient->setToken($user['api_token']);
        $this->apiClient->refreshBudgetCounter();

        echo json_encode([
            'success'    => true,
            'remaining'  => $_SESSION['api_limit'] ?? null,
            'updated_at' => $_SESSION['api_limit_updated_at'] ?? null,
        ]);
    }
}
