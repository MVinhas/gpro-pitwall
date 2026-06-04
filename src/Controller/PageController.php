<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Repository\DivisionMetadataRepository;
use App\Repository\UserRepository;
use App\Service\IdealPilotService;
use App\Service\InsightService;
use App\Service\RecruitmentService;
use App\Service\TrainingService;
use App\Service\GproApiClient;
use App\Service\GproDataMapper;
use App\Service\PhaMatchService;
use App\Service\RaceWeatherService;
use App\Service\CarWearService;
use App\Service\WearAdvisorService;
use App\Service\PartSwapAdvisorService;
use App\Service\TestingProjectionService;
use App\Service\SponsorAdvisorService;
use App\Service\TestingTargetsService;
use App\Service\TrainingAdvisorService;
use App\Controller\CarWearController;
use App\Controller\StrategyController;
use Twig\Environment;

class PageController
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly IdealPilotService $idealPilotService,
        private readonly InsightService $insightService,
        private readonly DivisionMetadataRepository $metaRepo,
        private readonly TrainingService $trainingService,
        private readonly UserRepository $userRepo,
        private readonly GproApiClient $apiClient,
        private readonly PhaMatchService $phaMatch,
        private readonly RaceWeatherService $raceWeather,
        private readonly CarWearService $carWear,
        private readonly WearAdvisorService $wearAdvisor,
        private readonly PartSwapAdvisorService $swapAdvisor,
        private readonly TestingProjectionService $testingProjection,
        private readonly SponsorAdvisorService $sponsorAdvisor,
        private readonly TestingTargetsService $testingTargets,
        private readonly TrainingAdvisorService $trainingAdvisor,
        private readonly StrategyController $strategyController,
        private readonly CarWearController $carWearController,
        private readonly GproDataMapper $mapper,
        private readonly RecruitmentService $recruitmentService,
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
        $isAdmin   = !empty($user['is_admin']);

        if (!$isLoggedIn) {
            echo $this->twig->render('landing.twig', [
                'csrf_token'   => $_SESSION['csrf_token'] ?? '',
                'is_logged_in' => false,
                'flash'        => $_SESSION['flash'] ?? null,
            ]);
            unset($_SESSION['flash']);
            return;
        }

        if (!$hasToken) {
            $_SESSION['flash'] = $_SESSION['flash']
                ?? 'Add your GPRO API token to unlock the cockpit.';
            header('Location: /control_panel');
            exit;
        }

        $this->apiClient->setToken($user['api_token']);

        $divisions    = $this->config['app']['divisions'];
        $tracks       = $this->config['app']['tracks'];
        $mainSections = $this->config['app']['main_sections'];

        if (!$isAdmin) {
            $mainSections = array_filter(
                $mainSections,
                fn (string $section): bool => !in_array(
                    $section,
                    ['Division Baseline', 'Division Differences'],
                    true,
                ),
            );
        }

        $mainSections = array_values(
            array_filter($mainSections, fn (string $s): bool => $s !== 'Login')
        );

        $defaultTab = 'Cockpit';
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

        $trackNames = array_values($tracks);
        $defaultTrack = $trackNames[0] ?? '';

        $activeTrack = $request->get('track', $defaultTrack);

        // Resolve a numeric track id ("2") to its name first, then validate
        // the result is a known track name. Anything else falls back to the
        // default so the UI never displays a raw id.
        if (is_numeric($activeTrack) && isset($tracks[(int) $activeTrack])) {
            $activeTrack = $tracks[(int) $activeTrack];
        }

        if (!in_array($activeTrack, $trackNames, true)) {
            $activeTrack = $defaultTrack;
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
            'api_limit_updated_at' => $_SESSION['api_limit_updated_at'] ?? null,
            'flash'           => $_SESSION['flash'] ?? null,
            'is_logged_in'    => $isLoggedIn,
            'user'            => $user,
            'can_submit'      => $isLoggedIn,
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
            case 'Cockpit':
                $cockpitRisk = max(0, min(100, (int) $request->get('cockpit_risk', 0)));
                $viewData['cockpit_risk'] = $cockpitRisk;
                try {
                    $raceSetup = $this->apiClient->getRaceSetup();
                    $carData   = $this->apiClient->getCarData();
                    $pilot     = $this->apiClient->getMyPilotDetails();

                    $trackId = (int) ($raceSetup['trackId'] ?? 0);
                    $viewData['pha'] = $this->phaMatch->evaluate(
                        [
                            'power'        => $raceSetup['trackPower'] ?? 0,
                            'handling'     => $raceSetup['trackHandl'] ?? 0,
                            'acceleration' => $raceSetup['trackAccel'] ?? 0,
                        ],
                        [
                            'power'        => $carData['carPower'] ?? 0,
                            'handling'     => $carData['carHandl'] ?? 0,
                            'acceleration' => $carData['carAccel'] ?? 0,
                        ],
                        $this->isFavouriteTrack($pilot, $trackId),
                    );
                    $trackName = $raceSetup['trackName'] ?? $activeTrack;
                    $viewData['pha_track_name'] = $trackName;

                    $w = $raceSetup['weather'] ?? [];
                    $viewData['weather'] = $this->raceWeather->assess($w);
                    $viewData['weather_temps'] = [
                        'q1' => $w['q1Temp'] ?? null,
                        'q2' => $w['q2Temp'] ?? null,
                    ];

                    $wear = $this->carWear->calculateWear(
                        // Resolve the track by name only. raceSetup.trackId is
                        // GPRO's track id, NOT our local tracks.id (an
                        // autoincrement PK), so passing it would let the
                        // "id = :id OR name = :name" lookup match an unrelated
                        // row and read the wrong per-part base wear. The Car
                        // Wear tab feeds id=0 (TrackProfile has no id) and is
                        // correct — match that.
                        [
                            'id'   => 0,
                            'name' => $trackName,
                            'laps' => $raceSetup['laps'] ?? null,
                        ],
                        $carData,
                        $this->mapper->mapDriver($pilot),
                        $cockpitRisk,
                    );
                    $menu = $this->apiClient->getMenu();
                    $division = $this->divisionFromMenu($menu);
                    $cash = (int) ($menu['cash'] ?? 0);

                    $moneyLevels = $this->apiClient->getMoneyLevels();
                    $groupCarLevels = array_values(array_filter(array_map(
                        static fn(array $m): int => (int) ($m['carLevel'] ?? 0),
                        $moneyLevels['managers'] ?? [],
                    ), static fn(int $lvl): bool => $lvl > 0));

                    $viewData['testing_projection'] = $this->testingProjection->project([
                        'power'        => $carData['carPower'] ?? 0,
                        'handling'     => $carData['carHandl'] ?? 0,
                        'acceleration' => $carData['carAccel'] ?? 0,
                    ]);

                    $office = $this->apiClient->getOfficeData();
                    $currentRace = (int) ($office['raceNb'] ?? 0);
                    if ($currentRace > 0) {
                        $calendar  = $this->apiClient->getCalendar();
                        $allTracks = $this->apiClient->getAllTracksPreview();
                        $targets = $this->testingTargets->targetsFor(
                            $currentRace,
                            $calendar,
                            $allTracks,
                        );
                        foreach ($targets as &$target) {
                            $target['favourite'] = $target['track_id'] !== null
                                && $this->isFavouriteTrack($pilot, $target['track_id']);
                        }
                        unset($target);
                        $viewData['testing_targets'] = $targets;
                    }

                    $negotiations = $this->apiClient->getSponsorNegotiations();
                    $ongoing = [];
                    foreach ($negotiations['ongNegs'] ?? [] as $neg) {
                        $sponsorId = (int) ($neg['sponsorId'] ?? 0);
                        if ($sponsorId <= 0) {
                            continue;
                        }
                        try {
                            $profile = $this->apiClient->getSponsorProfile($sponsorId);
                        } catch (\Throwable) {
                            continue;
                        }
                        $advice = $this->sponsorAdvisor->adviseFor($profile);
                        $ongoing[] = [
                            'name'             => (string) ($neg['name'] ?? $profile['name'] ?? 'Unknown'),
                            'progress'         => (string) ($neg['progress'] ?? '0'),
                            'priority'         => (string) ($neg['priority'] ?? ''),
                            'contested'        => (string) ($neg['contested'] ?? ''),
                            'attention'        => (int) ($neg['attention'] ?? 0),
                            'characteristics'  => $advice['characteristics'],
                            'answers'          => $advice['answers'],
                        ];
                    }
                    $viewData['sponsor_negotiations'] = [
                        'car_spots_taken' => (int) ($negotiations['carSpotsTaken'] ?? 0),
                        'car_spots_total' => count($negotiations['carSpots'] ?? []),
                        'ongoing'         => $ongoing,
                    ];

                    $trainings = $this->trainingService->getAllTrainings();
                    if ($trainings !== []) {
                        $ideal = $division !== null
                            ? $this->idealPilotService->getIdealPilot($division)
                            : null;
                        $viewData['training_division'] = $division;
                        $viewData['training_baseline_count'] = (int) ($ideal['count'] ?? 0);
                        $picks = $this->trainingAdvisor->rank(
                            $trainings,
                            $this->mapper->mapDriver($pilot),
                            $ideal,
                        );
                        if ($picks !== []) {
                            $viewData['training_picks'] = array_slice($picks, 0, 3);
                        }
                    }

                    if (!isset($wear['error'])) {
                        $advice = $this->wearAdvisor->classify($wear['parts']);
                        $viewData['wear_advice'] = $advice;

                        $forcedSwaps = array_merge($advice['swap'], $advice['risky']);
                        if ($forcedSwaps !== []) {
                            $viewData['swap_advice'] = $this->swapAdvisor->advise(
                                $forcedSwaps,
                                $carData,
                                $wear['parts'],
                                $this->mapper->mapDriver($pilot),
                                [
                                    'power'        => $raceSetup['trackPower'] ?? 0,
                                    'handling'     => $raceSetup['trackHandl'] ?? 0,
                                    'acceleration' => $raceSetup['trackAccel'] ?? 0,
                                ],
                                [
                                    'power'        => $carData['carPower'] ?? 0,
                                    'handling'     => $carData['carHandl'] ?? 0,
                                    'acceleration' => $carData['carAccel'] ?? 0,
                                ],
                                $cockpitRisk,
                                $groupCarLevels,
                                $cash,
                            );
                        }
                    }
                } catch (\Throwable $e) {
                    $viewData['cockpit_error'] = $e->getMessage();
                }
                break;

            case 'Division Baseline':
                if ($isAdmin) {
                    $viewData['all_ideal_pilots'] = $allIdealPilots;
                    $viewData['division_metadata'] =
                        $this->metaRepo->getMetadata($activeDivision);
                }
                break;

            case 'Division Differences':
                if ($isAdmin) {
                    $viewData['insights_data'] =
                        $this->insightService->generateInsights($allIdealPilots);
                }
                break;

            case 'Recruitment Analyzer':
                $this->handleRecruitmentTab($request, $viewData);
                break;

            case 'Training Planner':
                $viewData['trainings'] = $this->trainingService->getAllTrainings();

                // Pre-fill the driver from the API so the user doesn't have to
                // "Import Driver" on first visit. Cached as session state for
                // subsequent renders.
                $imported = $_SESSION['imported_driver'] ?? null;
                if (!$imported) {
                    try {
                        $this->apiClient->setToken($user['api_token']);
                        $pilotRaw = $this->apiClient->getMyPilotDetails();
                        $imported = $this->mapper->mapDriver($pilotRaw);
                        $_SESSION['imported_driver'] = $imported;
                    } catch (\Throwable $e) {
                        // Show the form unpopulated; the explicit Import button
                        // is still there as a fallback.
                    }
                }
                $viewData['imported_driver'] = $imported;

                if (isset($_SESSION['training_results'])) {
                    $viewData['training_results'] = $_SESSION['training_results'];
                    unset($_SESSION['training_results']);
                }
                if (isset($_SESSION['training_schedule'])) {
                    $viewData['schedule'] = $_SESSION['training_schedule'];
                    unset($_SESSION['training_schedule']);
                }
                break;

            case 'Car Wear':
                // Auto-populate on first visit (mirrors Race Strategy). The
                // calc reads exactly what GproApiClient has already cached
                // for the cockpit pass, so no extra API call is spent.
                $existing = $_SESSION['wear_results'] ?? null;

                if (!is_array($existing)) {
                    $risk = (int) ($_SESSION['wear_inputs']['risk'] ?? 0);
                    $this->apiClient->setToken($user['api_token']);
                    $result = $this->carWearController->runCalc($risk);
                    if (isset($result['error'])) {
                        $viewData['wear_error'] = $result['error'];
                    } else {
                        $viewData['wear_results'] = $result['results'];
                        $_SESSION['wear_inputs'] = [
                            'risk'   => $risk,
                            'driver' => $result['driver'],
                        ];
                    }
                } else {
                    $viewData['wear_results'] = $existing;
                }

                $viewData['wear_inputs'] = $_SESSION['wear_inputs'] ?? [];
                $viewData['wear_error']  = $_SESSION['wear_error'] ?? $viewData['wear_error'] ?? null;
                unset($_SESSION['wear_error']);
                break;

            case 'Race Strategy':
                $existing = $_SESSION['strategy_results'] ?? null;

                if (is_array($existing)) {
                    $viewData['strategy_results'] = $existing;
                } else {
                    // First visit (or fresh session) — run the calc with the
                    // synced data + zero overrides so the user lands on a fully
                    // populated panel without having to click Calculate.
                    $this->apiClient->setToken($user['api_token']);
                    $result = $this->strategyController->runCalc($request);
                    if (isset($result['error'])) {
                        $viewData['strategy_error'] = $result['error'];
                    } else {
                        $viewData['strategy_results'] = $result;
                    }
                }

                $viewData['strategy_error'] = $_SESSION['strategy_error'] ?? $viewData['strategy_error'] ?? null;
                unset($_SESSION['strategy_error']);
                break;
        }

        $fragment = (string) $request->get('fragment', '');
        if ($fragment === 'cockpit_wear' && $activeMainTab === 'Cockpit') {
            echo $this->twig->render('partials/_cockpit_wear.twig', $viewData);
            return;
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

        if (isset($_SESSION['recruitment_updated_at'])) {
            $viewData['recruitment_updated_at'] = $_SESSION['recruitment_updated_at'];
            unset($_SESSION['recruitment_updated_at']);
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

        $pageRows = array_slice($allResults, $offset, $perPage);

        // Favourite-track match counts for this page only. Calendar is read
        // from cache — never a fresh API call; the per-user sync warms it.
        // If the cache is cold, the column degrades to "—".
        $calendar = $this->apiClient->getCachedCalendar();
        $viewData['favourite_tracks_available'] = $calendar !== [];
        if ($calendar !== []) {
            /** @var array<int, string> $trackNames */
            $trackNames = $this->config['app']['tracks'] ?? [];
            $pageRows = $this->recruitmentService->tagFavouriteTracks(
                $pageRows,
                $this->recruitmentService->seasonRaceTrackIds($calendar),
                $trackNames,
            );
        }

        $viewData['recruitment_results'] = $pageRows;
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

    /**
     * Is the next race on one of the driver's three favourite tracks?
     * DriProfile exposes favTrack1/2/3 as {name, id} objects.
     *
     * @param array<string, mixed> $pilot
     */
    /**
     * Extracts the manager's current division (e.g. "Rookie") from a Menu
     * response whose `group` field is shaped like "Rookie - 31".
     *
     * @param array<string, mixed> $menu
     */
    private function divisionFromMenu(array $menu): ?string
    {
        $group = (string) ($menu['group'] ?? '');
        if ($group === '') {
            return null;
        }
        $first = trim(explode('-', $group, 2)[0]);
        return in_array($first, $this->config['app']['divisions'], true) ? $first : null;
    }

    /** @param array<string, mixed> $pilot */
    private function isFavouriteTrack(array $pilot, int $trackId): bool
    {
        if ($trackId <= 0) {
            return false;
        }

        foreach (['favTrack1', 'favTrack2', 'favTrack3'] as $key) {
            $fav = $pilot[$key] ?? null;
            if (is_array($fav) && (int) ($fav['id'] ?? 0) === $trackId) {
                return true;
            }
        }
        return false;
    }
}
