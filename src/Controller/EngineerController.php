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
}
