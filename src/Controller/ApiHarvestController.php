<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Security\Authorize;
use App\Service\HarvestService;
use App\Support\SessionBudget;

final readonly class ApiHarvestController
{
    public function __construct(
        private HarvestService $harvest,
        private Authorize $authorize,
    ) {
    }

    /**
     * One idle slice of background GPRO work. Every registered user harvests
     * their own data; which endpoints are worth a call is the planner's business.
     */
    public function run(Request $request): void
    {
        $user = $this->authorize->requireAuth();

        header('Content-Type: application/json');

        // The slice can take seconds: the manager's next navigation must not
        // queue behind the session lock meanwhile.
        $budget = SessionBudget::release((int) $user['id']);

        $result = $this->harvest->runSlice($user);

        $budget->persist();

        echo json_encode([
            'status' => $result->status,
            'calls' => $result->calls,
            'projected' => $result->projected,
        ]);
    }
}
