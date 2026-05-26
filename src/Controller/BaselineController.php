<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Repository\PilotRepository;
use App\Repository\DivisionMetadataRepository;
use App\Security\Authorize;
use App\Service\PilotCalculatorService;

class BaselineController
{
    public function __construct(
        private readonly PilotRepository $pilotRepo,
        private readonly DivisionMetadataRepository $metaRepo,
        private readonly PilotCalculatorService $calculator,
        private readonly array $statsSchema,
        private readonly Authorize $authorize,
    ) {
    }

    public function addPilot(Request $request): void
    {
        $this->authorize->requirePremium();

        $division = (string)$request->post('division');
        if ($division === '') {
            $this->redirectBack($division);
            return;
        }

        $data = [];
        foreach ($this->statsSchema as $col) {
            $val = $request->post($col);
            if ($val === null || $val === '') {
                $this->redirectBack($division);
                return;
            }

            $data[$col] = (int)$val;
        }

        $adjusted = $this->calculator->adjustPilotStats($data, $division);
        $this->pilotRepo->addPilot(array_merge($adjusted, ['division' => $division]));

        $this->redirectBack($division);
    }

    public function updateSeason(Request $request): void
    {
        $this->authorize->requirePremium();

        $division = (string)$request->post('division');
        if ($division === '') {
            $this->redirectBack($division);
            return;
        }

        $season = (int)$request->post('season_number');
        if ($season > 0) {
            $this->metaRepo->updateSeason($division, $season);
        }

        $this->redirectBack($division);
    }

    public function undoLastPilot(Request $request): void
    {
        $this->authorize->requirePremium();

        $division = (string)$request->post('division');
        if ($division === '') {
            $this->redirectBack($division);
            return;
        }

        $this->pilotRepo->deleteLastPilot($division);
        $this->redirectBack($division);
    }

    public function clearStats(Request $request): void
    {
        $this->authorize->requirePremium();

        $division = (string)$request->post('division');
        if ($division === '') {
            $this->redirectBack($division);
            return;
        }

        $this->pilotRepo->clearDivision($division);
        $this->redirectBack($division);
    }

    private function redirectBack(string $division): void
    {
        $params = http_build_query([
            'main_tab' => 'Division Baseline',
            'division_tab' => $division ?: 'Rookie'
        ]);
        header("Location: /?{$params}");
        exit;
    }
}
