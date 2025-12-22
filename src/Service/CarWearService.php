<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

class CarWearService
{
    /** @var array<string, float> */
    private array $driverFactors;

    /** @var array<int, float> */
    private array $levelFactors;

    /**
     * @param array<string, mixed> $secrets
     */
    public function __construct(
        private readonly PDO $db,
        array $secrets
    ) {
        $this->driverFactors = $secrets['driver_wear_factors'] ?? [];
        $this->levelFactors  = $secrets['part_level_factors'] ?? [];
    }

    /**
     * @param array<string, mixed> $trackData
     * @param array<string, mixed> $carData
     * @param array<string, mixed> $driver
     */
    public function calculateWear(
        array $trackData,
        array $carData,
        array $driver,
        int $risk
    ): array {
        $trackId = $trackData['id'] ?? 0;

        $stmt = $this->db->prepare(
            "SELECT * FROM tracks WHERE id = :id OR name = :name"
        );
        $stmt->execute([
            ':id'   => $trackId,
            ':name' => $trackData['name'] ?? '',
        ]);

        $trackDb = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$trackDb) {
            return ['error' => "Track not found: " . ($trackData['name'] ?? $trackId)];
        }

        $laps = (int) ($trackData['laps'] ?? $trackDb['laps']);

        $conc = (int) ($driver['concentration'] ?? 0);
        $tal  = (int) ($driver['talent'] ?? 0);
        $exp  = (int) ($driver['experience'] ?? 0);

        $driverFactor =
            ($this->driverFactors['concentration'] ?? 1.0) ** $conc *
            ($this->driverFactors['talent'] ?? 1.0) ** $tal *
            ($this->driverFactors['experience'] ?? 1.0) ** $exp;

        $results = [];

        $partsMap = [
            'Chassis' => ['db' => 'wear_chassis', 'lvl' => 'lvlChassis', 'wear' => 'usaChassis'],
            'Engine' => ['db' => 'wear_engine', 'lvl' => 'lvlEngine', 'wear' => 'usaEngine'],
            'Front Wing' => ['db' => 'wear_fwing', 'lvl' => 'lvlFWing', 'wear' => 'usaFWing'],
            'Rear Wing' => ['db' => 'wear_rwing', 'lvl' => 'lvlRWing', 'wear' => 'usaRWing'],
            'Underbody' => ['db' => 'wear_underbody', 'lvl' => 'lvlUnderbody', 'wear' => 'usaUnderbody'],
            'Sidepods' => ['db' => 'wear_sidepod', 'lvl' => 'lvlSidepods', 'wear' => 'usaSidepods'],
            'Cooling' => ['db' => 'wear_cooling', 'lvl' => 'lvlCooling', 'wear' => 'usaCooling'],
            'Gearbox' => ['db' => 'wear_gearbox', 'lvl' => 'lvlGear', 'wear' => 'usaGear'],
            'Brakes' => ['db' => 'wear_brakes', 'lvl' => 'lvlBrakes', 'wear' => 'usaBrakes'],
            'Suspension' => ['db' => 'wear_suspension', 'lvl' => 'lvlSusp', 'wear' => 'usaSusp'],
            'Electronics' => ['db' => 'wear_electronics', 'lvl' => 'lvlElectronics', 'wear' => 'usaElectronics'],
        ];

        foreach ($partsMap as $label => $map) {
            $trackBase = (float) ($trackDb[$map['db']] ?? 0.0);
            $level     = (int) ($carData[$map['lvl']] ?? 1);
            $levelFactor = $this->levelFactors[$level] ?? 1.019326794;

            $riskModifier = $levelFactor ** $risk;
            $estWear = $trackBase * $riskModifier * $driverFactor;

            $startWear = (int) ($carData[$map['wear']] ?? 0);

            $results[$label] = [
                'level' => $level,
                'start' => $startWear,
                'est'   => round($estWear, 1),
                'end'   => $startWear + round($estWear, 1),
            ];
        }

        return [
            'track_name' => $trackDb['name'],
            'laps'       => $laps,
            'parts'      => $results,
        ];
    }
}
