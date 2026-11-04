<?php

declare(strict_types=1);

namespace App\Engineer;

use InvalidArgumentException;

/**
 * @phpstan-type CtrNext array{ctr: int, gain_more: int, cost_more: int}
 * @phpstan-type CtrChoice array{ctr: int, kind: 'value'|'safety', gain: int, cost: int, next: ?CtrNext}
 */
final class CtrValue
{
    public const int MARGIN = 100_000;

    /**
     * @param array<int, int> $gainByCtr money gained at each candidate CTR
     * @param array<int, int> $repairsByCtr repair bill at each candidate CTR, same keys
     * @return CtrChoice
     */
    public static function choose(array $gainByCtr, array $repairsByCtr, int $repairsAtZero, bool $safety): array
    {
        if ($gainByCtr === []) {
            throw new InvalidArgumentException('No CTR candidates to choose from.');
        }
        ksort($gainByCtr);

        $candidates = array_keys($gainByCtr);
        $net = [];
        foreach ($candidates as $c) {
            $net[$c] = $gainByCtr[$c] - ($repairsByCtr[$c] - $repairsAtZero);
        }

        $pick = $safety ? $candidates[count($candidates) - 1] : $candidates[0];
        if (!$safety) {
            foreach ($candidates as $c) {
                if ($net[$c] - $net[$pick] > self::MARGIN) {
                    $pick = $c;
                }
            }
        }

        $gain = $gainByCtr[$pick];
        $cost = $repairsByCtr[$pick] - $repairsAtZero;
        $next = null;
        foreach ($candidates as $c) {
            if ($c > $pick) {
                $next = [
                    'ctr' => $c,
                    'gain_more' => $gainByCtr[$c] - $gain,
                    'cost_more' => ($repairsByCtr[$c] - $repairsAtZero) - $cost,
                ];
                break;
            }
        }

        return [
            'ctr' => $pick,
            'kind' => $safety ? 'safety' : 'value',
            'gain' => $gain,
            'cost' => $cost,
            'next' => $next,
        ];
    }
}
