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
     * Repairs with the swap now minus repairs with the swap next race; null when the part's price is unknown.
     * A round whose track wear is unknown charges nothing in either run, so the saving over the known rounds
     * stands: the real calendar nearly always has one such track.
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

        $unpriced = $part . ' price unknown';
        if (in_array($unpriced, $now['partial'], true) || in_array($unpriced, $later['partial'], true)) {
            return null;
        }

        return array_sum(array_column($now['rows'], 'repairs')) - array_sum(array_column($later['rows'], 'repairs'));
    }
}
