<?php

declare(strict_types=1);

namespace App\Engineer;

/**
 * @phpstan-import-type InForce from SeasonObjective
 * @phpstan-import-type Plan from CtrPlanner
 * @phpstan-type Outlook array{known: bool, committed: ?int, income: ?int, free_cash: ?int, end_committed: ?int}
 * @phpstan-type PartAdvice array{part: string, level: int, start: int, est: float, end: float}
 * @phpstan-type WearAdvice array{
 *     swap: list<PartAdvice>,
 *     risky: list<PartAdvice>,
 *     watch: list<PartAdvice>,
 *     headline: string
 * }
 * @phpstan-type TestingTarget array{
 *     offset: int,
 *     season: string,
 *     race: int,
 *     track_id: ?int,
 *     track_name: ?string,
 *     power: ?int,
 *     handling: ?int,
 *     acceleration: ?int
 * }
 * @phpstan-type Risk array{
 *     overtake: int,
 *     defend: int,
 *     phrase: string,
 *     tip: ?string,
 *     distance_tip: string,
 *     settings: array<string, mixed>
 * }
 */
final readonly class EngineerInputs
{
    /**
     * @param InForce $objective
     * @param Outlook $outlook
     * @param ?WearAdvice $wearAdvice
     * @param list<array{part: string, cost: int}> $optionalSwaps
     * @param ?array{name: string, score: float, cost: int, gains: array<string, float>} $trainingPick
     * @param list<string> $alwaysRed
     * @param ?TestingTarget $testingTarget
     * @param ?Risk $risk
     * @param ?Plan $plan
     */
    public function __construct(
        public array $objective,
        public array $outlook,
        public int $cash,
        public int $currentCtr,
        public ?int $ctrCeiling,
        public ?int $ctrSurvive,
        public array $alwaysRed,
        public ?string $pushAdvice,
        public ?array $wearAdvice,
        public ?int $forcedSwapCost,
        public array $optionalSwaps,
        public ?array $trainingPick,
        public ?array $testingTarget,
        public bool $testingOpen,
        public ?string $testingTrack,
        public ?int $testingCost,
        public ?array $risk,
        public ?int $boostLitres,
        public bool $weatherStale,
        public ?array $plan,
    ) {
    }
}
