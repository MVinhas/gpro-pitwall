<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Security\Authorize;
use App\Service\GproApiClient;
use App\Service\RecruitmentService;

class RecruitmentController
{
    public function __construct(
        private readonly RecruitmentService $service,
        private readonly GproApiClient $apiClient,
        private readonly Authorize $authorize,
    ) {
    }

    public function analyze(Request $request): void
    {
        $this->authorize->requirePremium();

        $division = (string) $request->post('target_division', 'Rookie');

        try {
            $forceRefresh = (bool) $request->post('refresh_market');
            $market = $this->apiClient->getMarketFile('drivers', $forceRefresh);

            $results = $this->service->analyze(
                $market['rows'],
                $division,
                (bool) $request->post('filter_offers'),
            );

            $_SESSION['recruitment_results'] = $results;
            $_SESSION['recruitment_updated_at'] = $market['updated_at'];
            $_SESSION['recruitment_error'] = null;
        } catch (\Throwable $exception) {
            $_SESSION['recruitment_results'] = [];
            $_SESSION['recruitment_updated_at'] = null;
            $_SESSION['recruitment_error'] = $exception->getMessage();
        }

        $query = http_build_query([
            'main_tab'     => 'Recruitment Analyzer',
            'division_tab' => $division,
            'page'         => 1,
        ]);
        header("Location: /?{$query}");
        exit;
    }
}
