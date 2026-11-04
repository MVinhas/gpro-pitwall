<?php

declare(strict_types=1);

namespace App\Engineer;

/**
 * What the CTR planner needs beyond the budget: the driver's energy cap and the pace a CTR point is worth.
 * $gainPerPoint is dollars over the remaining season per CTR point; $placesPerPoint is the next race's places
 * per CTR point.
 */
final readonly class CtrPolicy
{
    public const int SAFETY_PLACES = 3;

    public function __construct(
        public int $energyCap,
        public bool $energyEstimate,
        public ?int $energyStart,
        public int $gainPerPoint,
        public float $placesPerPoint,
        public bool $paceKnown,
        public bool $safety,
    ) {
    }

    public static function none(): self
    {
        return new self(EnergyModel::HARD_MAX, true, null, 0, 0.0, false, false);
    }

    /**
     * Safety mode: the projected finish is within SAFETY_PLACES of the relegation zone, or inside it.
     */
    public static function safetyFor(?int $projectedPos, ?int $relegationPos): bool
    {
        return $projectedPos !== null && $relegationPos !== null
            && $projectedPos >= $relegationPos - self::SAFETY_PLACES;
    }
}
