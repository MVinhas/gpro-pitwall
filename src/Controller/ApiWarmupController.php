<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Security\Authorize;
use App\Service\AuthService;
use App\Service\GproSyncService;
use App\Sync\SyncPlanner;

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

        // A manual re-sync settles any login sync still owed.
        unset($_SESSION[AuthService::SYNC_PENDING]);
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

        unset($_SESSION[AuthService::SYNC_PENDING], $_SESSION[AuthService::IDLE_SYNC_OFFERED]);
        // PHP holds the session file lock until the request ends; releasing it
        // keeps the manager's next navigation from queueing behind the sync.
        $released = session_status() === PHP_SESSION_ACTIVE && session_write_close();
        $stamp = $_SESSION['api_limit_updated_at'] ?? null;

        $outcome = $this->syncService->sync($user, SyncPlanner::MODE_LOGIN);

        if ($released && ($_SESSION['api_limit_updated_at'] ?? null) !== $stamp) {
            $this->storeBudget($_SESSION['api_limit'] ?? null, $_SESSION['api_limit_updated_at'] ?? null);
        }

        echo json_encode(['status' => $outcome->status, 'fetched' => $outcome->fetched]);
    }

    /**
     * The sync wrote the remaining-calls figure while the session was released,
     * so it only reached memory. Reopen the session just long enough to save it,
     * so the status line and the next page show the figure after the sync.
     */
    private function storeBudget(mixed $limit, mixed $updatedAt): void
    {
        if (!session_start()) {
            return;
        }
        $_SESSION['api_limit'] = $limit;
        $_SESSION['api_limit_updated_at'] = $updatedAt;
        session_write_close();
    }
}
