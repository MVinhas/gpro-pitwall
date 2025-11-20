<?php
namespace App\Controller;

use App\Http\Request;
use App\Service\GproApiClient;
use App\Service\GproDataMapper;
use App\Service\TrainingService;

class TrainingController
{
    public function __construct(
        private GproApiClient $apiClient,
        private GproDataMapper $mapper,
        private TrainingService $trainingService
    ) {}

    public function handle(Request $request): void
    {
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
            // 1. Fetch from API
            $apiData = $this->apiClient->getMyPilotDetails();
            
            // 2. Map to App Schema
            $driver = $this->mapper->mapDriver($apiData);
            
            // 3. Store in Session for the Form
            $_SESSION['imported_driver'] = $driver;
            $_SESSION['training_error'] = null;

        } catch (\Exception $e) {
            $_SESSION['training_error'] = "Import Failed: " . $e->getMessage();
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
            
            // Log progress every session (or maybe just end result?)
            // Let's log every session for detailed analysis
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