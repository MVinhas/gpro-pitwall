<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Repository\UserRepository;
use App\Service\GproSyncService;

final readonly class ApiWarmupController
{
    public function __construct(
        private UserRepository $userRepo,
        private GproSyncService $syncService
    ) {
    }

    public function warmup(Request $request): void
    {
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $user = $this->userRepo->findById((int) $userId);
        if (!$user || empty($user['api_token'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No API token configured']);
            return;
        }
        $this->syncService->trySyncForUser($user, true);
    }
}
