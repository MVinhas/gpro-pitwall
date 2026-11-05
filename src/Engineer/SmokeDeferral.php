<?php

declare(strict_types=1);

namespace App\Engineer;

use App\Service\WearAdvisorService;

/**
 * Prices what letting a smoking part run one more race saves over the rest of the season.
 *
 * @phpstan-import-type Round from SeasonBudget
 */
final class SmokeDeferral
{
    /**
     * Repairs with the swap now minus repairs with the swap next race; null when it can't be priced.
     *
     * @param list<Round> $rounds remaining races, next race first
     * @param array{level: int, start: int|float, training?: int|float} $partData
     */
    public static function saving(
        SeasonBudget $budget,
        array $rounds,
        string $part,
        array $partData,
        ?int $price,
        float $driverFactor,
        int $ctr,
    ): ?int {
        if ($price === null) {
            return null;
        }

        $now = $budget->forecast(
            $rounds,
            [$part => $partData],
            [$part => $price],
            $driverFactor,
            $ctr,
            WearAdvisorService::THRESHOLD_SWAP,
            0,
            0,
            0,
        );
        $later = $budget->forecast(
            $rounds,
            [$part => $partData],
            [$part => $price],
            $driverFactor,
            $ctr,
            WearAdvisorService::THRESHOLD_SWAP,
            0,
            0,
            0,
            true,
        );

        if ($now['partial'] !== [] || $later['partial'] !== []) {
            return null;
        }

        return array_sum(array_column($now['rows'], 'repairs')) - array_sum(array_column($later['rows'], 'repairs'));
    }
}
