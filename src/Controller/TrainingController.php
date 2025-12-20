<?php

namespace App\Controller;

use App\Http\Request;
use App\Service\GproApiClient;
use App\Service\GproDataMapper;
use App\Service\TrainingService;
use App\Repository\UserRepository;

class TrainingController
{
    public function __construct(
        private readonly GproApiClient $apiClient,
        private readonly GproDataMapper $mapper,
        private readonly TrainingService $trainingService,
        private readonly UserRepository $userRepo
    ) {
    }

    public function handle(Request $request): void
    {
        $isLoggedIn = !empty($_SESSION['user_id']);
        $userId = $isLoggedIn ? (int) $_SESSION['user_id'] : 0;
        $user = $userId ? $this->userRepo->findById($userId) : null;

        if ($isLoggedIn && !$user) {
            session_destroy();
            $isLoggedIn = false;
            $user = null;
        }

        $hasToken  = !empty($user['api_token']);
        $isPremium = !empty($user['is_premium']);
        $isAdmin   = !empty($user['is_admin']);

        if ($isLoggedIn && $hasToken) {
            $this->apiClient->setToken($user['api_token']);
        }

        $action = $request->post('action');

        if ($action === 'import_driver') {
            $this->importDriver();
        } elseif ($action === 'calculate_training') {
            $this->calculateTraining($request);
        }

        // Redirect back to the planner tab
        header("Location: /?main_tab=Training Planner");
        exit;
    }

    private function importDriver(): void
    {
        try {
            $apiData = $this->apiClient->getMyPilotDetails();
            $driver = $this->mapper->mapDriver($apiData);
            $_SESSION['imported_driver'] = $driver;
            $_SESSION['training_error'] = null;
        } catch (\Exception $exception) {
            $_SESSION['training_error'] = "Import Failed: " . $exception->getMessage();
        }
    }

    private function calculateTraining(Request $request): void
    {
        // Get starting stats from form
        $stats = [
            'concentration' => (int)$request->post('concentration'),
            'talent' => (int)$request->post('talent'),
            'aggressiveness' => (int)$request->post('aggressiveness'),
            'experience' => (int)$request->post('experience'),
            'technical_insight' => (int)$request->post('technical_insight'),
            'stamina' => (int)$request->post('stamina'),
            'charisma' => (int)$request->post('charisma'),
            'motivation' => (int)$request->post('motivation'),
            'weight' => (int)$request->post('weight'),
        ];

        $trainingType = $request->post('training_type'); // e.g. 'Fitness'
        $intensity = (int)$request->post('intensity', 1); // Number of sessions

        // Simulate the training sessions loop
        $currentStats = $stats;
        $totalCost = 0;
        $log = [];

        for ($i = 1; $i <= $intensity; $i++) {
            $result = $this->trainingService->predictResult($currentStats, $trainingType);
            $currentStats = $result['stats'];
            $totalCost += $result['cost'];

            $log[] = [
                'session' => $i,
                'stats' => $currentStats,
                'cost_step' => $result['cost']
            ];
        }

        $_SESSION['training_results'] = [
            'start' => $stats,
            'end' => $currentStats,
            'total_cost' => $totalCost,
            'program' => "$trainingType x $intensity",
            'log' => $log
        ];
    }
}
