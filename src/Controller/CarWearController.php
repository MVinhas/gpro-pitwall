<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Security\Authorize;
use App\Service\CarWearService;
use App\Service\GproApiClient;
use App\Service\GproDataMapper;

class CarWearController
{
    public function __construct(
        private readonly CarWearService $service,
        private readonly GproApiClient $api,
        private readonly GproDataMapper $mapper,
        private readonly Authorize $authorize,
    ) {
    }

    public function handle(Request $request): void
    {
        $user = $this->authorize->requirePremium();

        if (!empty($user['api_token'])) {
            $this->api->setToken($user['api_token']);
        }

        $risk = (int)$request->post('risk', 0);

        try {
            $trackProfile = $this->api->getNextRaceProfile();
            $office = $this->api->getOfficeData();
            $carData = $this->api->getCarData();

            // Driver stats come from the API only — the form no longer exposes
            // them as editable fields. PageController pre-warms the session;
            // we re-fetch here to make sure we use whatever the API reports
            // *now* (so training between visits is reflected).
            $driver = $this->mapper->mapDriver($this->api->getMyPilotDetails());

            if (empty($trackProfile['name']) && !empty($office['trackName'])) {
                $trackProfile['name'] = $office['trackName'];
            }

            $results = $this->service->calculateWear($trackProfile, $carData, $driver, $risk);

            $results['season'] = $office['seasonNb'] ?? '?';
            $results['race'] = $office['raceNb'] ?? '?';

            $_SESSION['wear_results'] = $results;

            $_SESSION['wear_inputs'] = [
                'risk' => $risk,
                'driver' => $driver
            ];
            $_SESSION['wear_error'] = null;
        } catch (\Exception $exception) {
            $_SESSION['wear_error'] = "Error: " . $exception->getMessage();
        }

        session_write_close();
        header("Location: /?main_tab=Car Wear");
        exit;
    }
}
