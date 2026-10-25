<?php

declare(strict_types=1);

namespace App\Service;

final readonly class FuelModel
{
    private const float FALLBACK_LAP_LENGTH = 4.5;
    private const float MIN_RATE = 0.1;

    /**
     * @param array<string, mixed> $secrets
     */
    public function __construct(private array $secrets)
    {
    }

    /**
     * @param array<string, mixed> $trackRow
     */
    public function lapLength(array $trackRow): float
    {
        $laps = (int) ($trackRow['laps'] ?? 0);

        return $laps > 0 ? (float) ($trackRow['distance'] ?? 0.0) / $laps : self::FALLBACK_LAP_LENGTH;
    }

    /**
     * @param array<string, mixed> $trackRow
     * @param array<string, mixed> $driver
     * @param array<string, mixed> $carLevels
     * @return array{dry: float, wet: float}
     */
    public function perKm(array $trackRow, array $driver, array $carLevels): array
    {
        $ff = $this->secrets['fuel_factors'] ?? [];

        $adjustment = ((float) $driver['concentration'] * $ff['conc'])
            + ((float) $driver['aggressiveness'] * $ff['agg'])
            + ((float) $driver['experience'] * $ff['exp'])
            + ((float) $driver['technical_insight'] * $ff['te'])
            + ((int) $carLevels['lvlEngine'] * $ff['eng_lvl'])
            + ((int) $carLevels['lvlElectronics'] * $ff['ele_lvl'])
            + $ff['constant'];

        $dry = (float) $trackRow['fuel_per_lap'];
        $wet = (float) ($trackRow['fuel_per_lap_wet'] ?? $dry);

        return [
            'dry' => max(self::MIN_RATE, $dry + $adjustment),
            'wet' => max(self::MIN_RATE, $wet + $adjustment),
        ];
    }

    /**
     * @param array<string, mixed> $trackRow
     */
    public function boostExtra(array $trackRow, int $boostLaps, bool $wet): float
    {
        $dryCoeff = (float) ($trackRow['boost_dry'] ?? 0.0);
        $coeff = $wet ? (float) ($trackRow['boost_wet'] ?? $dryCoeff) : $dryCoeff;

        if ($boostLaps <= 0 || $coeff <= 0.0) {
            return 0.0;
        }

        return ceil($boostLaps * $this->lapLength($trackRow) * $coeff);
    }
}
