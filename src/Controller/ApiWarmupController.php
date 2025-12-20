<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Repository\UserRepository;
use App\Service\GproApiClient;
use RuntimeException;

final readonly class ApiWarmupController
{
    public function __construct(
        private GproApiClient $apiClient,
        private UserRepository $userRepo
    ) {
    }

    public function warmup(Request $request): void
    {
        header('Content-Type: application/json');

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

        $this->apiClient->setToken($user['api_token']);

        try {
            $this->apiClient->getOfficeData(true);
            $this->apiClient->getMyPilotDetails(true);
            $this->apiClient->getCarData(true);
            $this->apiClient->getNextRaceProfile(true);
            $this->apiClient->getRaceSetup(true);
            $this->apiClient->getStaffAndFacilities(true);
            $this->apiClient->getTechnicalDirector(true);
            $this->apiClient->getTyreSuppliers(true);

            echo json_encode([
                'success' => true,
                'message' => 'API cache warmed successfully'
            ]);
        } catch (\Throwable $throwable) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => $throwable->getMessage()
            ]);
        }
    }
}
