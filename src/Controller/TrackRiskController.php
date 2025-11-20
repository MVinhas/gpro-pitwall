<?php
namespace App\Controller;

use App\Http\Request;
use App\Repository\TrackRepository;

class TrackRiskController
{
    public function __construct(
        private TrackRepository $repo,
        private array $config
    ) {}

    public function update(Request $request): void
    {
        if (!$this->config['settings']['is_dev']) {
            $this->redirectBack($request->post('track_name'));
            return;
        }

        $track = $request->post('track_name');
        $over = (int)$request->post('overtaking_risk');
        $def = (int)$request->post('defense_risk');
        
        $q1Risks = [];
        foreach ($this->config['config']['app']['divisions'] as $div) {
            $q1Risks[$div] = $request->post('q1_' . $div);
        }

        $this->repo->updateRisks($track, $over, $def, $q1Risks);
        $this->redirectBack($track);
    }

    private function redirectBack(string $track): void
    {
        $params = http_build_query([
            'main_tab' => 'Track Risks',
            'track' => $track
        ]);
        header("Location: /?{$params}");
        exit;
    }
}