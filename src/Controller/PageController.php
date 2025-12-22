<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Repository\TrackRepository;
use App\Repository\DivisionMetadataRepository;
use App\Repository\UserRepository;
use App\Service\IdealPilotService;
use App\Service\InsightService;
use App\Service\TrainingService;
use App\Service\GproApiClient;
use App\Service\GproDataMapper;
use Twig\Environment;

class PageController
{
    public function __construct(
        private readonly IdealPilotService $idealPilotService,
        private readonly InsightService $insightService,
        private readonly TrackRepository $trackRepo,
        private readonly DivisionMetadataRepository $metaRepo,
        private readonly TrainingService $trainingService,
        private readonly UserRepository $userRepo,
        private readonly GproApiClient $apiClient,
        private readonly Environment $twig,
        private array $config
    ) {
    }

    public function index(Request $request): void
    {
        $isLoggedIn = !empty($_SESSION['user_id']);
        $userId = $isLoggedIn ? (int) $_SESSION['user_id'] : 0;
        $user = $userId ? $this->userRepo->findById($userId) : null;

        if ($isLoggedIn && !$user) {
            session_destroy();
            $isLoggedIn = false;
            $user = null;
        }

        $hasToken  = !empty($user['api_token']);
        $isPremium = !empty($user['is_premium']);
        $isAdmin   = !empty($user['is_admin']);

        if ($isLoggedIn && $hasToken) {
            $this->apiClient->setToken($user['api_token']);
        }

        $divisions    = $this->config['app']['divisions'];
        $tracks       = $this->config['app']['tracks'];
        $mainSections = $this->config['app']['main_sections'];

        if (!$isAdmin) {
            $mainSections = array_filter(
                $mainSections,
                fn (string $section): bool =>
                    !in_array($section, ['Division Baseline', 'Track Risks'], true)
            );
        }

        $mainSections = array_values(
            array_filter($mainSections, fn (string $s): bool => $s !== 'Login')
        );

        $defaultTab = 'Recruitment Analyzer';
        if (!in_array($defaultTab, $mainSections, true)) {
            $defaultTab = $mainSections[0] ?? '';
        }

        $activeMainTab = (string) $request->get('main_tab', $defaultTab);
        if (!in_array($activeMainTab, $mainSections, true)) {
            $activeMainTab = $defaultTab;
        }

        $activeDivision = (string) $request->get('division_tab', 'Rookie');
        if (!in_array($activeDivision, $divisions, true)) {
            $activeDivision = 'Rookie';
        }

        $activeTrack = $request->get('track', 2);
        $trackIds   = array_keys($tracks);
        $trackNames = array_values($tracks);

        if (!in_array($activeTrack, $trackIds, true) && !in_array($activeTrack, $trackNames, true)) {
            $activeTrack = $trackNames[0] ?? 2;
        }

        if (is_numeric($activeTrack) && isset($tracks[(int) $activeTrack])) {
            $activeTrack = $tracks[(int) $activeTrack];
        }

        $viewData = [
            'active_main_tab' => $activeMainTab,
            'active_division' => $activeDivision,
            'active_track'    => $activeTrack,
            'divisions'       => $divisions,
            'tracks'          => $tracks,
            'main_sections'   => $mainSections,
            'config'          => $this->config,
            'settings'        => $this->config['settings'],
            'api_limit'       => $_SESSION['api_limit'] ?? '?',
            'flash'           => $_SESSION['flash'] ?? null,
            'is_logged_in'    => $isLoggedIn,
            'user'            => $user,
            'can_submit'      => ($isLoggedIn && $isPremium && $hasToken),
        ];

        unset($_SESSION['flash']);

        $allIdealPilots = [];
        if (in_array($activeMainTab, ['Division Baseline', 'Division Differences'], true)) {
            foreach ($divisions as $division) {
                $allIdealPilots[$division] =
                    $this->idealPilotService->getIdealPilot($division);
            }
        }

        switch ($activeMainTab) {
            case 'Division Baseline':
                if ($isAdmin) {
                    $viewData['all_ideal_pilots'] = $allIdealPilots;
                    $viewData['division_metadata'] =
                        $this->metaRepo->getMetadata($activeDivision);
                }
                break;

            case 'Division Differences':
                $viewData['insights_data'] =
                    $this->insightService->generateInsights($allIdealPilots);
                break;

            case 'Track Risks':
                if ($isAdmin) {
                    $viewData['track_risks'] =
                        $this->trackRepo->getTrackRisks((string) $activeTrack, $divisions);
                }
                break;

            case 'Recruitment Analyzer':
                $this->handleRecruitmentTab($request, $viewData);
                break;

            case 'Training Planner':
                $viewData['trainings'] = $this->trainingService->getAllTrainings();
                $viewData['imported_driver'] = $_SESSION['imported_driver'] ?? null;

                if (isset($_SESSION['training_results'])) {
                    $viewData['training_results'] = $_SESSION['training_results'];
                    unset($_SESSION['training_results']);
                }
                break;

            case 'Car Wear':
                if ($hasToken && !isset($_SESSION['wear_inputs']['driver'])) {
                    try {
                        $pilotData = $this->apiClient->getMyPilotDetails();
                        $_SESSION['wear_inputs']['driver'] =
                            (new GproDataMapper())->mapDriver($pilotData);
                    } catch (\Throwable $e) {
                        $_SESSION['wear_error'] = $e->getMessage();
                    }
                }

                $viewData['wear_results'] = $_SESSION['wear_results'] ?? null;
                $viewData['wear_inputs']  = $_SESSION['wear_inputs'] ?? [];
                $viewData['wear_error']   = $_SESSION['wear_error'] ?? null;
                unset($_SESSION['wear_error']);
                break;

            case 'Race Strategy':
                $viewData['strategy_results'] = $_SESSION['strategy_results'] ?? null;
                $viewData['strategy_error']   = $_SESSION['strategy_error'] ?? null;
                unset($_SESSION['strategy_error']);
                break;
        }

        echo $this->twig->render('index.twig', $viewData);
    }

    /**
     * @param array<string, mixed> $viewData
     */
    private function handleRecruitmentTab(Request $request, array &$viewData): void
    {
        if (isset($_SESSION['recruitment_error'])) {
            $viewData['recruitment_error'] = $_SESSION['recruitment_error'];
            unset($_SESSION['recruitment_error']);
        }

        if (empty($_SESSION['recruitment_results'])) {
            return;
        }

        $allResults = $_SESSION['recruitment_results'];

        $sortCol   = (string) $request->get('sort', 'rating');
        $sortOrder = (string) $request->get('order', 'desc');

        usort($allResults, function ($a, $b) use ($sortCol, $sortOrder) {
            $valA = $a[$sortCol] ?? 0;
            $valB = $b[$sortCol] ?? 0;

            if ($valA === $valB) {
                return 0;
            }

            $cmp = is_numeric($valA) && is_numeric($valB)
                ? ($valA < $valB ? -1 : 1)
                : strcasecmp((string) $valA, (string) $valB);

            return $sortOrder === 'desc' ? -$cmp : $cmp;
        });

        $totalItems  = count($allResults);
        $perPage     = (int) ($_ENV['PAGINATION_LIMIT'] ?? 20);
        $currentPage = max(1, (int) $request->get('page', 1));
        $totalPages  = (int) ceil($totalItems / $perPage);

        if ($currentPage > $totalPages && $totalPages > 0) {
            $currentPage = $totalPages;
        }

        $offset = ($currentPage - 1) * $perPage;

        $viewData['recruitment_results'] = array_slice($allResults, $offset, $perPage);
        $viewData['pagination'] = [
            'current'      => $currentPage,
            'total_pages'  => $totalPages,
            'total_items'  => $totalItems,
        ];
        $viewData['sort'] = [
            'col'   => $sortCol,
            'order' => $sortOrder,
        ];
    }
}
