<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Service\CarWearService;
use App\Service\GproApiClient;
use App\Service\GproDataMapper;
use App\Repository\UserRepository;

class CarWearController
{
    public function __construct(
        private readonly CarWearService $service,
        private readonly GproApiClient $api,
        private readonly GproDataMapper $mapper,
        private readonly UserRepository $userRepo
    ) {
    }

    public function handle(Request $request): void
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
            $this->api->setToken($user['api_token']);
        }

        $risk = (int)$request->post('risk', 0);

        try {
            $trackProfile = $this->api->getNextRaceProfile();
            $office = $this->api->getOfficeData();
            $carData = $this->api->getCarData();

            if ($request->post('talent') !== null) {
                $driver = [
                    'concentration' => (int)$request->post('concentration'),
                    'talent'        => (int)$request->post('talent'),
                    'experience'    => (int)$request->post('experience'),
                    'weight'        => 0,
                    'aggressiveness' => 0
                ];
            } else {
                $driver = $_SESSION['wear_inputs']['driver'] ?? $this->mapper->mapDriver(
                    $this->api->getMyPilotDetails()
                );
            }

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
