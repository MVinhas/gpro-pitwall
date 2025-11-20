<?php
namespace App\Controller;

use App\Http\Request;
use App\Repository\PilotRepository;
use App\Repository\DivisionMetadataRepository;
use App\Service\PilotCalculatorService;

class BaselineController
{
    public function __construct(
        private PilotRepository $pilotRepo,
        private DivisionMetadataRepository $metaRepo,
        private PilotCalculatorService $calculator,
        private array $config
    ) {}

    public function handle(Request $request): void
    {
        $action = $request->post('action');
        $division = $request->post('division');
        
        // Simple security check for dev mode
        if (!$this->config['settings']['is_dev']) {
            $this->redirectBack($division);
            return;
        }

        match ($action) {
            'add_pilot' => $this->addPilot($request, $division),
            'update_season' => $this->updateSeason($request, $division),
            'undo_last_pilot' => $this->undo($division),
            'clear_stats' => $this->clear($division),
            default => null
        };

        $this->redirectBack($division);
    }

    private function addPilot(Request $request, string $div): void
    {
        $schema = $this->config['config']['app']['stats_schema'];
        $data = [];
        foreach ($schema as $col) {
            $val = $request->post($col);
            if ($val === null || $val === '') return; // Validation fail
            $data[$col] = (int)$val;
        }

        $adjusted = $this->calculator->adjustPilotStats($data, $div);
        $this->pilotRepo->addPilot(array_merge($adjusted, ['division' => $div]));
    }

    private function updateSeason(Request $request, string $div): void
    {
        $season = (int)$request->post('season_number');
        if ($season > 0) $this->metaRepo->updateSeason($div, $season);
    }

    private function undo(string $div): void { $this->pilotRepo->deleteLastPilot($div); }
    private function clear(string $div): void { $this->pilotRepo->clearDivision($div); }

    private function redirectBack(string $div): void
    {
        $params = http_build_query([
            'main_tab' => 'Division Baseline',
            'division_tab' => $div
        ]);
        header("Location: /?{$params}");
        exit;
    }
}