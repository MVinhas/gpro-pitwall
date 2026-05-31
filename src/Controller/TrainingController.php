<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Security\Authorize;
use App\Service\PilotCalculatorService;
use App\Service\TrainingService;

class TrainingController
{
    public function __construct(
        private readonly TrainingService $trainingService,
        private readonly PilotCalculatorService $calculator,
        private readonly Authorize $authorize,
    ) {
    }

    public function handle(Request $request): void
    {
        $this->authorize->requirePremium();

        $action = $request->post('action');
        if ($action === 'calculate_training') {
            $this->calculateTraining($request);
        }

        header("Location: /?main_tab=Training Planner");
        exit;
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

        $sessions = $request->post('sessions');
        $schedule = is_array($sessions) ? $sessions : [];

        $plan = $this->trainingService->planSchedule($stats, $schedule);

        $programSummary = array_map(
            static fn(array $p): string => $p['name'] . ' x ' . $p['count'],
            $plan['per_program'],
        );

        $_SESSION['training_results'] = [
            'start'       => $plan['start'],
            'end'         => $plan['end'],
            'start_oa'    => round($this->calculator->calculateOverall($plan['start']), 1),
            'end_oa'      => round($this->calculator->calculateOverall($plan['end']), 1),
            'total_cost'  => $plan['total_cost'],
            'program'     => $programSummary === [] ? 'No sessions selected' : implode(' · ', $programSummary),
            'per_program' => $plan['per_program'],
        ];
        $_SESSION['training_schedule'] = $schedule;
    }
}
