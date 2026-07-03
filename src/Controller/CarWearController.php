<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Security\Authorize;
use App\Service\CarWearService;
use App\Service\GproApiClient;
use App\Service\GproDataMapper;
use Twig\Environment;

class CarWearController
{
    public function __construct(
        private readonly CarWearService $service,
        private readonly GproApiClient $api,
        private readonly GproDataMapper $mapper,
        private readonly Authorize $authorize,
        private readonly Environment $twig,
    ) {
    }

    public function handle(Request $request): void
    {
        $user = $this->authorize->requireAuth();
        $this->api->setToken($user['api_token']);

        $risk = (int) $request->post('risk', 0);
        $result = $this->runCalc($risk);

        if (isset($result['error'])) {
            $_SESSION['wear_error'] = $result['error'];
        } else {
            $_SESSION['wear_results'] = $result['results'];
            $_SESSION['wear_inputs'] = [
                'risk'   => $risk,
                'driver' => $result['driver'],
            ];
            $_SESSION['wear_error'] = null;
        }

        session_write_close();
        header('Location: /?main_tab=Car Wear');
        exit;
    }

    public function fragment(Request $request): void
    {
        $user = $this->authorize->requireAuth();
        $this->api->setToken($user['api_token']);

        $risk = (int) $request->post('risk', 0);
        $result = $this->runCalc($risk);

        echo $this->twig->render('partials/_car_wear_results.twig', [
            'wear_results' => $result['results'] ?? null,
            'wear_error'   => $result['error'] ?? null,
            'wear_inputs'  => [
                'risk'   => $risk,
                'driver' => $result['driver'] ?? null,
            ],
        ]);
    }

    /**
     * Runs the wear calculation. Returns:
     *   ['results' => array, 'driver' => array]  on success
     *   ['error'   => string]                    on failure
     *
     * No session writes here — the same call powers the redirect-after-POST
     * flow, the no-reload slider refresh, and the auto-populate on first
     * tab open.
     *
     * @return array<string, mixed>
     */
    public function runCalc(int $risk): array
    {
        try {
            $office       = $this->api->getOfficeData();
            $trackProfile = $this->api->getNextRaceProfile();

            // endOfSeason is the authoritative "no next race" signal — mirror
            // StrategyController. trackNotFoundNote alone is NOT reliable: GPRO
            // sets it at the start of a new season (race 1) while the track +
            // laps are already known but pre-race setup isn't done. Treating it
            // as end-of-season showed "Season finished" on a fresh season (#21,
            // regression). Only honour the note when there's genuinely no race.
            $endOfSeason     = !empty($office['endOfSeason']);
            $noRaceScheduled = (int) ($office['raceNb'] ?? 0) === 0;
            if ($endOfSeason || ($noRaceScheduled && !empty($trackProfile['trackNotFoundNote']))) {
                return ['error' => StrategyController::END_OF_SEASON_MESSAGE];
            }

            // No driver under contract: wear can't be projected. Point the user
            // at the Recruitment Analyzer (rendered as a special notice).
            if (!$this->api->hasPilot()) {
                return ['error' => StrategyController::NO_PILOT_MESSAGE];
            }

            $carData      = $this->api->getCarData();
            $driver       = $this->mapper->mapDriver($this->api->getMyPilotDetails());

            if (empty($trackProfile['name']) && !empty($office['trackName'])) {
                $trackProfile['name'] = $office['trackName'];
            }

            $results = $this->service->calculateWear($trackProfile, $carData, $driver, $risk);
            $results['season'] = $office['seasonNb'] ?? '?';
            $results['race']   = $office['raceNb'] ?? '?';

            return ['results' => $results, 'driver' => $driver];
        } catch (\Exception $exception) {
            error_log('[CarWear] ' . $exception::class . ': ' . $exception->getMessage());

            return ['error' => StrategyController::GENERIC_ERROR_MESSAGE];
        }
    }
}
