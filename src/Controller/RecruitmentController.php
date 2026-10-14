<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Sections;
use App\Security\Authorize;
use App\Service\GproApiClient;

class RecruitmentController
{
    public function __construct(
        private readonly GproApiClient $apiClient,
        private readonly Authorize $authorize,
    ) {
    }

    /**
     * Re-pulls the driver market past the cache. The screen comes back with
     * the filters the session remembers; a failed pull keeps the cached copy
     * on screen and says so.
     */
    public function refresh(Request $request): void
    {
        $this->authorize->requireAuth();

        try {
            $this->apiClient->getMarketFile('drivers', true);
        } catch (\Throwable $exception) {
            error_log('[Recruitment] ' . $exception::class . ': ' . $exception->getMessage());
            $_SESSION['recruitment_refresh_failed'] = true;
        }

        header('Location: ' . Sections::url('Recruitment Analyzer'), true, 303);
        exit;
    }
}
