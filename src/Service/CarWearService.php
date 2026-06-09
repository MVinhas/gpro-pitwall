<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

class CarWearService
{
    /**
     * Internal part labels → DB column / API field map. Public so the swap
     * advisor can correlate a wear row with the matching API options array
     * without duplicating the mapping.
     *
     * @var array<string, array{db: string, lvl: string, wear: string, options: string}>
     */
    public const array PARTS_MAP = [
        'Chassis'     => ['db' => 'wear_chassis', 'lvl' => 'lvlChassis',
            'wear' => 'usaChassis', 'options' => 'chassisOptions'],
        'Engine'      => ['db' => 'wear_engine', 'lvl' => 'lvlEngine',
            'wear' => 'usaEngine', 'options' => 'engineOptions'],
        'Front Wing'  => ['db' => 'wear_fwing', 'lvl' => 'lvlFWing',
            'wear' => 'usaFWing', 'options' => 'fWingOptions'],
        'Rear Wing'   => ['db' => 'wear_rwing', 'lvl' => 'lvlRWing',
            'wear' => 'usaRWing', 'options' => 'rWingOptions'],
        'Underbody'   => ['db' => 'wear_underbody', 'lvl' => 'lvlUnderbody',
            'wear' => 'usaUnderbody', 'options' => 'underbodyOptions'],
        'Sidepods'    => ['db' => 'wear_sidepod', 'lvl' => 'lvlSidepods',
            'wear' => 'usaSidepods', 'options' => 'sidepodsOptions'],
        'Cooling'     => ['db' => 'wear_cooling', 'lvl' => 'lvlCooling',
            'wear' => 'usaCooling', 'options' => 'coolingOptions'],
        'Gearbox'     => ['db' => 'wear_gearbox', 'lvl' => 'lvlGear',
            'wear' => 'usaGear', 'options' => 'gearOptions'],
        'Brakes'      => ['db' => 'wear_brakes', 'lvl' => 'lvlBrakes',
            'wear' => 'usaBrakes', 'options' => 'brakesOptions'],
        'Suspension'  => ['db' => 'wear_suspension', 'lvl' => 'lvlSusp',
            'wear' => 'usaSusp', 'options' => 'suspOptions'],
        'Electronics' => ['db' => 'wear_electronics', 'lvl' => 'lvlElectronics',
            'wear' => 'usaElectronics', 'options' => 'electronicsOptions'],
    ];

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
     * Driver wear multiplier from concentration / talent / experience.
     * Pulled from secrets — exposed so the swap advisor can re-project
     * per-part wear at hypothetical levels.
     *
     * @param array<string, mixed> $driver
     */
    public function driverFactor(array $driver): float
    {
        $conc = (int) ($driver['concentration'] ?? 0);
        $tal  = (int) ($driver['talent'] ?? 0);
        $exp  = (int) ($driver['experience'] ?? 0);

        return ($this->driverFactors['concentration'] ?? 1.0) ** $conc *
               ($this->driverFactors['talent'] ?? 1.0) ** $tal *
               ($this->driverFactors['experience'] ?? 1.0) ** $exp;
    }

    /**
     * Projects the end-of-race wear (%) of a single part if it sits at
     * the given level for the upcoming race. Mirrors the per-part loop in
     * `calculateWear()` but for one hypothetical configuration — used by
     * `PartSwapAdvisorService` to score the cost-vs-survival of each
     * GPRO-offered swap option.
     */
    public function projectEndWear(
        float $trackBase,
        int $level,
        float $startWear,
        float $driverFactor,
        int $risk,
    ): float {
        $levelFactor = $this->levelFactors[$level] ?? 1.019326794;
        $est = $trackBase * ($levelFactor ** $risk) * $driverFactor;
        return $startWear + round($est, 1);
    }

    /**
     * @param array<string, mixed> $trackData
     * @param array<string, mixed> $carData
     * @param array<string, mixed> $driver
     * @return array<string, mixed>
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

        $driverFactor = $this->driverFactor($driver);

        $results = [];
        foreach (self::PARTS_MAP as $label => $map) {
            $trackBase = (float) ($trackDb[$map['db']] ?? 0.0);
            $level     = (int) ($carData[$map['lvl']] ?? 1);
            $startWear = (int) ($carData[$map['wear']] ?? 0);
            $end       = $this->projectEndWear($trackBase, $level, (float) $startWear, $driverFactor, $risk);

            $results[$label] = [
                'level'      => $level,
                'start'      => $startWear,
                'est'        => round($end - $startWear, 1),
                'end'        => $end,
                'track_base' => $trackBase,
            ];
        }

        return [
            'track_name' => $trackDb['name'],
            'laps'       => $laps,
            'parts'      => $results,
        ];
    }

    /**
     * Testing laps wear the car at roughly half the per-lap rate of a race at
     * the same track. With the full-race base ÷ race-laps × driver factor
     * applied, the residual is a single flat factor, independent of part level
     * (the level factors span only ~1.6% end to end, so they can't account for
     * it). Calibrated against two real sessions (2026-06): a 30-lap and a
     * 100-lap run both yield a best-fit factor of ~0.533.
     */
    private const float TESTING_WEAR_FACTOR = 0.53;

    /**
     * Per-lap car wear (%) for a testing session at the given track, per part,
     * already folding in the driver wear multiplier and the testing factor.
     *
     * The wear_* columns hold full-race-distance wear, so we divide by the
     * track's race lap count to get a per-lap race rate, then scale by
     * TESTING_WEAR_FACTOR. Testing has no clear-track risk, and part level only
     * modulates risk-driven wear in the game's model, so neither enters here.
     *
     * @param array<string, mixed> $trackData
     * @param array<string, mixed> $carData
     * @param array<string, mixed> $driver
     * @return array<string, mixed>
     */
    public function testingWearRates(array $trackData, array $carData, array $driver): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM tracks WHERE id = :id OR name = :name"
        );
        $stmt->execute([
            ':id'   => $trackData['id'] ?? 0,
            ':name' => $trackData['name'] ?? '',
        ]);

        $trackDb = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$trackDb) {
            return ['error' => "Track not found: " . ($trackData['name'] ?? '')];
        }

        $raceLaps = max(1, (int) ($trackDb['laps'] ?? 1));
        $driverFactor = $this->driverFactor($driver);

        $parts = [];
        foreach (self::PARTS_MAP as $label => $map) {
            $trackBase = (float) ($trackDb[$map['db']] ?? 0.0);
            $startWear = (int) ($carData[$map['wear']] ?? 0);
            $perLap    = ($trackBase * $driverFactor * self::TESTING_WEAR_FACTOR) / $raceLaps;

            $parts[$label] = [
                'start'   => $startWear,
                'per_lap' => round($perLap, 4),
            ];
        }

        return [
            'track_name' => $trackDb['name'],
            'race_laps'  => $raceLaps,
            'parts'      => $parts,
        ];
    }
}
