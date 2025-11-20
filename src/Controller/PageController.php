<?php
namespace App\Controller;

use App\Http\Request;
use App\Repository\TrackRepository;
use App\Service\IdealPilotService;
use App\Service\InsightService;
use App\Repository\DivisionMetadataRepository;
use App\Service\TrainingService;
use Twig\Environment;

class PageController
{
    public function __construct(
        private IdealPilotService $idealPilotService,
        private InsightService $insightService,
        private TrackRepository $trackRepo,
        private DivisionMetadataRepository $metaRepo,
        private TrainingService $trainingService,
        private Environment $twig,
        private array $config
    ) {}

    public function index(Request $request, array $extraData = []): void
    {
        $divisions = $this->config['app']['divisions'];
        $tracks = $this->config['app']['tracks'];
        $mainSections = $this->config['app']['main_sections'];

        $activeMainTab = $request->get('main_tab', 'Recruitment Analyzer');
        if (!in_array($activeMainTab, $mainSections)) $activeMainTab = 'Recruitment Analyzer';

        $activeDivision = $request->get('division_tab', 'Rookie');
        if (!in_array($activeDivision, $divisions)) $activeDivision = 'Rookie';

        $activeTrack = $request->get('track', 2); // Default Buenos Aires
        if (!array_key_exists($activeTrack, $tracks) && !in_array($activeTrack, $tracks)) {
             $activeTrack = array_values($tracks)[0];
        }

        $viewData = [
            'active_main_tab' => $activeMainTab,
            'active_division' => $activeDivision,
            'active_track' => $activeTrack,
            'divisions' => $divisions,
            'tracks' => $tracks,
            'main_sections' => $mainSections,
            'config' => $this->config,
            'settings' => $this->config['settings'],
            'api_limit' => $_SESSION['api_limit'] ?? '?' // <--- NEW
        ];

        if (!empty($extraData)) {
            $viewData = array_merge($viewData, $extraData);
        }

        $allIdealPilots = [];
        foreach ($divisions as $division) {
            $allIdealPilots[$division] = $this->idealPilotService->getIdealPilot($division);
        }

        if ($activeMainTab === 'Division Baseline') {
            $viewData['all_ideal_pilots'] = $allIdealPilots;
            $viewData['division_metadata'] = $this->metaRepo->getMetadata($activeDivision);
        }

        if ($activeMainTab === 'Division Differences') {
            $viewData['insights_data'] = $this->insightService->generateInsights($allIdealPilots);
        }

        if ($activeMainTab === 'Track Risks') {
            $viewData['track_risks'] = $this->trackRepo->getTrackRisks($activeTrack, $divisions);
        }

        // --- Recruitment Pagination & Sorting ---
        if ($activeMainTab === 'Recruitment Analyzer') {
            if (isset($_SESSION['recruitment_error'])) {
                $viewData['recruitment_error'] = $_SESSION['recruitment_error'];
                unset($_SESSION['recruitment_error']);
            }

            if (!empty($_SESSION['recruitment_results'])) {
                $allResults = $_SESSION['recruitment_results'];
                
                // 1. HANDLE SORTING
                // Get sort parameters from URL (default to rating desc)
                $sortCol = $request->get('sort', 'rating');
                $sortOrder = $request->get('order', 'desc');

                // Sort the full array
                usort($allResults, function($a, $b) use ($sortCol, $sortOrder) {
                    $valA = $a[$sortCol] ?? 0;
                    $valB = $b[$sortCol] ?? 0;
                    
                    if ($valA == $valB) return 0;
                    
                    // Compare numeric or string
                    if (is_numeric($valA) && is_numeric($valB)) {
                        $cmp = ($valA < $valB) ? -1 : 1;
                    } else {
                        $cmp = strcasecmp((string)$valA, (string)$valB);
                    }
                    
                    return ($sortOrder === 'desc') ? -$cmp : $cmp;
                });

                // 2. HANDLE PAGINATION
                $totalItems = count($allResults);
                $perPage = 20; // FIX: Set to 20 per page
                
                $currentPage = max(1, (int)$request->get('page', 1));
                $totalPages = ceil($totalItems / $perPage);
                
                if ($currentPage > $totalPages && $totalPages > 0) $currentPage = $totalPages;

                $offset = ($currentPage - 1) * $perPage;
                $paginatedResults = array_slice($allResults, $offset, $perPage);

                // Pass data to view
                $viewData['recruitment_results'] = $paginatedResults;
                $viewData['pagination'] = [
                    'current' => $currentPage,
                    'total_pages' => $totalPages,
                    'total_items' => $totalItems
                ];
                $viewData['sort'] = [
                    'col' => $sortCol,
                    'order' => $sortOrder
                ];
            }
        }

        if ($activeMainTab === 'Training Planner') {
            $viewData['trainings'] = $this->trainingService->getAllTrainings();
            
            // Check for imported driver in session
            if (isset($_SESSION['imported_driver'])) {
                $viewData['imported_driver'] = $_SESSION['imported_driver'];
                // Don't unset immediately so they can refresh, 
                // but usually better to keep it. Let's keep it for the session.
            }
            
            // Check for calculation results
            if (isset($_SESSION['training_results'])) {
                $viewData['training_results'] = $_SESSION['training_results'];
                unset($_SESSION['training_results']);
            }
        }

        if ($activeMainTab === 'Car Wear') {
            $viewData['wear_results'] = $_SESSION['wear_results'] ?? null;
            $viewData['wear_inputs'] = $_SESSION['wear_inputs'] ?? [];
            $viewData['wear_error'] = $_SESSION['wear_error'] ?? null;
            // Clear error after showing
            unset($_SESSION['wear_error']);
        }

        echo $this->twig->render('index.twig', $viewData);
    }
}