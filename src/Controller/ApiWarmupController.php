<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Security\Authorize;
use App\Service\AuthService;
use App\Service\GproSyncService;
use App\Service\HarvestService;
use App\Support\SessionBudget;
use App\Sync\SyncPlanner;

final readonly class ApiWarmupController
{
    public function __construct(
        private GproSyncService $syncService,
        private Authorize $authorize,
        private HarvestService $harvest,
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

        // A manual re-sync settles any login sync still owed.
        unset($_SESSION[AuthService::SYNC_PENDING], $_SESSION[AuthService::IDLE_SYNC_OFFERED]);
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
     * The login sync a warm page left owed, run once the browser is idle. Login
     * mode: the plan spends only what changed, so it is cheap as well as late.
     */
    public function idle(Request $request): void
    {
        $user = $this->authorize->requireAuth();

        header('Content-Type: application/json');

        if (empty($_SESSION[AuthService::SYNC_PENDING])) {
            echo json_encode(['status' => 'idle', 'fetched' => [], 'harvest_due' => $this->harvest->isDue($user)]);
            return;
        }

        unset($_SESSION[AuthService::SYNC_PENDING], $_SESSION[AuthService::IDLE_SYNC_OFFERED]);
        $budget = SessionBudget::release((int) $user['id']);

        $outcome = $this->syncService->sync($user, SyncPlanner::MODE_LOGIN);

        $budget->persist();

        // Asked after the sync: the page starts the harvest loop only on this
        // answer, so a slice never runs alongside the sync.
        echo json_encode([
            'status' => $outcome->status,
            'fetched' => $outcome->fetched,
            'harvest_due' => $this->harvest->isDue($user),
        ]);
    }
}
