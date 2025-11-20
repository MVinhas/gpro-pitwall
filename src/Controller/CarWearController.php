<?php
namespace App\Controller;

use App\Http\Request;
use App\Service\CarWearService;
use App\Service\GproApiClient;
use App\Service\GproDataMapper;

class CarWearController
{
    public function __construct(
        private CarWearService $service,
        private GproApiClient $api,
        private GproDataMapper $mapper
    ) {}

    public function handle(Request $request): void
    {
        $risk = (int)$request->post('risk', 40);

        try {
            // 1. Fetch Context (Season, Race, Track Name)
            $office = $this->api->getOfficeData();
            
            // 2. Fetch Track Details (Laps, Distance)
            $trackProfile = $this->api->getNextRaceProfile();
            
            // 3. Fetch Car & Driver
            $carData = $this->api->getCarData();
            $pilotData = $this->api->getMyPilotDetails();
            $driver = $this->mapper->mapDriver($pilotData);

            // 4. Calculate
            // Merge Office track name if profile is missing it
            if (empty($trackProfile['name']) && !empty($office['trackName'])) {
                $trackProfile['name'] = $office['trackName'];
            }

            $results = $this->service->calculateWear($trackProfile, $carData, $driver, $risk);
            
            // Add Metadata for UI
            if (is_array($results)) {
                $results['season'] = $office['seasonNb'] ?? '?';
                $results['race'] = $office['raceNb'] ?? '?';
            }

            $_SESSION['wear_results'] = $results;
            $_SESSION['wear_inputs'] = ['risk' => $risk];
            $_SESSION['wear_error'] = null;

        } catch (\Exception $e) {
            $_SESSION['wear_error'] = "Error: " . $e->getMessage();
            $_SESSION['wear_results'] = null;
        }

        session_write_close();
        header("Location: /?main_tab=Car Wear");
        exit;
    }
}