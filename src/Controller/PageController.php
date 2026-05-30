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
use App\Service\PhaMatchService;
use App\Service\BoostFuelService;
use App\Service\RaceWeatherService;
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
        private readonly PhaMatchService $phaMatch,
        private readonly BoostFuelService $boostFuel,
        private readonly RaceWeatherService $raceWeather,
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
            case 'Cockpit':
                if ($hasToken) {
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

                        // Boost-lap fuel cost for the upcoming track (dry coeff;
                        // wet is only relevant if the race runs wet).
                        $boost = $this->trackRepo->findBoostProfile((string) $trackName);
                        if ($boost !== null) {
                            $viewData['boost_costs'] = $this->boostFuel->costTable(
                                $boost['lap_length'],
                                $boost['boost_dry'],
                            );
                        }

                        $w = $raceSetup['weather'] ?? [];
                        $viewData['weather'] = $this->raceWeather->assess($w);
                        $viewData['weather_temps'] = [
                            'q1' => $w['q1Temp'] ?? null,
                            'q2' => $w['q2Temp'] ?? null,
                        ];
                    } catch (\Throwable $e) {
                        $viewData['cockpit_error'] = $e->getMessage();
                    }
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
                $existing = $_SESSION['strategy_results'] ?? null;

                // Pre-warm the form on first visit so the user doesn't see empty
                // inputs. Skip if a real calculation result is already cached.
                if ($hasToken && !is_array($existing)) {
                    try {
                        $pilotRaw = $this->apiClient->getMyPilotDetails();
                        $carRaw   = $this->apiClient->getCarData();
                        $weather  = $this->apiClient->getRaceSetup();
                        $driver = (new GproDataMapper())->mapDriver($pilotRaw);

                        $viewData['strategy_results'] = [
                            'stats' => [
                                'driver' => $driver,
                                'car' => [
                                    'lvlEngine'      => (int)($carRaw['lvlEngine'] ?? 1),
                                    'lvlSusp'        => (int)($carRaw['lvlSusp'] ?? 1),
                                    'lvlElectronics' => (int)($carRaw['lvlElectronics'] ?? 1),
                                ],
                                'staff' => ['concentration' => 0, 'stressHandling' => 0],
                                'td'    => ['experience' => 0, 'pitCoordination' => 0],
                            ],
                            'weather_inputs' => $this->weatherDefaults($weather['weather'] ?? []),
                            'inputs' => ['risk' => 0, 'target_wear' => 15],
                        ];
                    } catch (\Throwable $e) {
                        $viewData['strategy_error'] = $e->getMessage();
                    }
                } else {
                    $viewData['strategy_results'] = $existing;
                }

                $viewData['strategy_error'] = $_SESSION['strategy_error'] ?? $viewData['strategy_error'] ?? null;
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

    /**
     * Is the next race on one of the driver's three favourite tracks?
     * DriProfile exposes favTrack1/2/3 as {name, id} objects.
     *
     * @param array<string, mixed> $pilot
     */
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

    /**
     * @param array<string, mixed> $w
     * @return array<string, array<string, mixed>>
     */
    private function weatherDefaults(array $w): array
    {
        $isWet = static fn(string $key): string =>
            ($w[$key] ?? '') === 'Rain' ? 'Wet' : 'Dry';

        return [
            'Q1'   => ['temp' => $w['q1Temp'] ?? '', 'weather' => $isWet('q1Weather')],
            'Q2'   => ['temp' => $w['q2Temp'] ?? '', 'weather' => $isWet('q2Weather')],
            'Race' => ['weather' => 'Dry'],
        ];
    }
}
