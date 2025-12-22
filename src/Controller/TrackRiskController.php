<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Repository\TrackRepository;

class TrackRiskController
{
    public function __construct(
        private readonly TrackRepository $repo,
        private array $config
    ) {
    }

    public function update(Request $request): void
    {

        if (empty($this->config['settings']['is_dev'])) {
            $this->redirectBack((string)$request->post('track_name'));
            return;
        }

        $track = (string)$request->post('track_name');
        $over = (int)$request->post('overtaking_risk');
        $def = (int)$request->post('defense_risk');

        $q1Risks = [];
        $divisions = $this->config['config']['app']['divisions'];

        foreach ($divisions as $div) {
            $q1Risks[$div] = (string)$request->post('q1_' . $div);
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
