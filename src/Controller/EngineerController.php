<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Security\Authorize;
use App\Service\RaceEngineerService;

final readonly class EngineerController
{
    public function __construct(
        private RaceEngineerService $engineer,
        private Authorize $authorize,
    ) {
    }

    public function setObjective(Request $request): void
    {
        $user = $this->authorize->requireAdmin();
        $season = (string) $request->post('season');
        $objective = (string) $request->post('objective');

        if (ctype_digit($season)) {
            $this->engineer->pin((int) $user['id'], (int) $season, $objective);
        }

        header('Location: /engineer');
        exit;
    }

    public function setChoice(Request $request): void
    {
        $user = $this->authorize->requireAdmin();
        $season = (string) $request->post('season');
        $race = (string) $request->post('race');

        if (ctype_digit($season) && ctype_digit($race)) {
            $this->engineer->choose(
                (int) $user['id'],
                (int) $season,
                (int) $race,
                (string) $request->post('item'),
                (string) $request->post('choice'),
            );
        }

        header('Location: /engineer');
        exit;
    }
}
