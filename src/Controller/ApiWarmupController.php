<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Security\Authorize;
use App\Service\AuthService;
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

        header('Content-Type: application/json');

        if (empty($user['api_token'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'status' => 'needs_token', 'message' => 'No API token configured']);
            return;
        }

        // A manual re-sync settles any first sync still owed.
        unset($_SESSION[AuthService::SYNC_PENDING]);
        $status = $this->syncService->trySyncForUser($user, true);
        if ($status === 'synced') {
            $_SESSION[AuthService::HARVEST_OWED] = true;
        }

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
     * Kept for pages opened before the sync became manual-only: they still
     * POST here once idle. It answers "idle" and does nothing.
     */
    public function idle(Request $request): void
    {
        $this->authorize->requireAuth();

        header('Content-Type: application/json');
        echo json_encode(['status' => 'idle', 'fetched' => [], 'harvest_due' => false]);
    }
}
