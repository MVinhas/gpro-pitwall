<?php

declare(strict_types=1);

namespace App\Engineer;

/**
 * @phpstan-import-type InForce from SeasonObjective
 * @phpstan-import-type Plan from CtrPlanner
 * @phpstan-import-type StaffStanding from GroupStanding
 * @phpstan-import-type TyreAdvice from TyreSupplierAdvisor
 * @phpstan-import-type CarStanding from GroupStanding
 * @phpstan-type Outlook array{known: bool, committed: ?int, income: ?int, free_cash: ?int, end_committed: ?int}
 * @phpstan-type PartAdvice array{part: string, level: int, start: int, est: float, end: float}
 * @phpstan-import-type PickReason from SwapLevelPicker
 * @phpstan-type SwapPick array{part: string, level: int, kind: string, cost: int, net_cost: int, reason: PickReason}
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
     * @param ?array{name: string, score: float, cost: int, gains: array<string, float>} $trainingPick
     * @param ?TestingTarget $testingTarget
     * @param ?Risk $risk
     * @param ?Plan $plan
     * @param ?StaffStanding $staffStanding
     * @param ?CarStanding $carStanding
     * @param list<SwapPick> $swapPicks the season-long level picked for each swapped part that has one
     * @param int $testingLaps the Cockpit's training laps, whose testing wear every plan counts
     * @param ?TyreAdvice $tyreAdvice set only while GPRO's tyre contract window is open and unsigned
     * @param list<string> $stuckSmoke parts that reach 100% this race even at CTR 0, while the car is updated
     * @param ?int $energyCap the highest CTR the driver's energy allows, on every path with or without a plan
     * @param ?int $energyStart the driver's expected energy at the race start, when the office reports it
     */
    public function __construct(
        public array $objective,
        public array $outlook,
        public int $cash,
        public int $currentCtr,
        public ?int $ctrCeiling,
        public ?string $pushAdvice,
        public ?array $wearAdvice,
        public ?int $forcedSwapCost,
        public ?array $trainingPick,
        public ?array $testingTarget,
        public bool $testingOpen,
        public ?string $testingTrack,
        public int $testingCost,
        public ?array $risk,
        public ?int $boostLitres,
        public bool $weatherStale,
        public ?array $plan,
        public ?array $staffStanding = null,
        public ?array $carStanding = null,
        public array $swapPicks = [],
        public int $testingLaps = 0,
        public ?array $tyreAdvice = null,
        public ?string $division = null,
        public bool $carUpdated = false,
        public bool $tested = false,
        public bool $driverTrained = false,
        public array $stuckSmoke = [],
        public ?int $energyCap = null,
        public bool $energyEstimate = false,
        public ?int $energyStart = null,
    ) {
    }

    /**
     * @param Plan $plan
     */
    public function withPlan(array $plan): self
    {
        return clone($this, ['plan' => $plan]);
    }

    /**
     * @param list<SwapPick> $picks
     */
    public function withSwapPicks(array $picks): self
    {
        return clone($this, ['swapPicks' => $picks]);
    }

    /**
     * @param ?TyreAdvice $advice
     */
    public function withTyreAdvice(?array $advice): self
    {
        return clone($this, ['tyreAdvice' => $advice]);
    }
}
