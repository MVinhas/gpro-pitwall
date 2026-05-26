<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Security\Authorize;
use App\Service\GproApiClient;
use App\Service\GproDataMapper;
use App\Service\TrainingService;

class TrainingController
{
    public function __construct(
        private readonly GproApiClient $apiClient,
        private readonly GproDataMapper $mapper,
        private readonly TrainingService $trainingService,
        private readonly Authorize $authorize,
    ) {
    }

    public function handle(Request $request): void
    {
        $user = $this->authorize->requirePremium();

        if (!empty($user['api_token'])) {
            $this->apiClient->setToken($user['api_token']);
        }

        $action = $request->post('action');

        if ($action === 'import_driver') {
            $this->importDriver();
        } elseif ($action === 'calculate_training') {
            $this->calculateTraining($request);
        }


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

        $trainingType = $request->post('training_type');
        $intensity = (int)$request->post('intensity', 1);

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
