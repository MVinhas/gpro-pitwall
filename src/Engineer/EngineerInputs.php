<?php

declare(strict_types=1);

namespace App\Engineer;

/**
 * @phpstan-import-type InForce from SeasonObjective
 * @phpstan-import-type Plan from CtrPlanner
 * @phpstan-import-type StaffStanding from GroupStanding
 * @phpstan-import-type TyreAdvice from TyreSupplierAdvisor
 * @phpstan-import-type CarStanding from GroupStanding
 * @phpstan-type SmokePrice array{time: int, break: int, total: int}
 * @phpstan-type SmokeQuote array{
 *     risk: SmokeRisk,
 *     start: float,
 *     price: ?SmokePrice,
 *     saving: ?int,
 *     cost: ?int,
 *     last: bool
 * }
 * @phpstan-type Outlook array{known: bool, committed: ?int, income: ?int, free_cash: ?int, end_committed: ?int}
 * @phpstan-type PartAdvice array{part: string, level: int, start: int, est: float, end: float}
 * @phpstan-import-type PickReason from SwapLevelPicker
 * @phpstan-type SwapPick array{part: string, level: int, kind: string, cost: int, net_cost: int, reason: PickReason,
 *   start: float, price: int}
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
     * @param int $testingLaps the Cockpit's training laps; a replan without testing wear keeps the Cockpit's count
     * @param ?TyreAdvice $tyreAdvice set only while GPRO's tyre contract window is open and unsigned
     * @param list<string> $stuckSmoke parts that reach 100 % this race even at CTR 0, while the car is updated
     * @param ?int $energyCap the highest CTR the driver's energy allows, on every path with or without a plan
     * @param ?int $energyStart the driver's expected energy at the race start, when the office reports it
     * @param ?string $trainingChoice the manager's call for this race's training: 'do', 'skip' or none
     * @param ?string $testingChoice the manager's call for this race's testing: 'do', 'skip' or none
     * @param ?TestingGate $testingGate null derives open or closed from the session and its target
     * @param list<SwapPick> $unlockPicks the swaps that take the parts blocking testing below 90 %
     * @param bool $unlockUnaffordable the gate is blocked because the unlock swaps plus the session don't fit
     * @param ?string $testingSkip why the engineer skipped the session on its own before testing wear was dropped;
     *                             the replan keeps that verdict, since its larger envelope assumes no session
     * @param array<string, SmokeQuote> $smoke when each forced or stuck part reaches 100 % this race, and what
     *                                         its smoke costs against what fitting it a race later saves
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
        public ?string $trainingChoice = null,
        public ?string $testingChoice = null,
        public ?TestingGate $testingGate = null,
        public array $unlockPicks = [],
        public int $season = 0,
        public int $race = 0,
        public bool $unlockUnaffordable = false,
        public ?string $testingSkip = null,
        public array $smoke = [],
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
     * @param list<SwapPick> $picks
     */
    public function withUnlockPicks(array $picks): self
    {
        return clone($this, ['unlockPicks' => $picks]);
    }

    public function withTestingGate(TestingGate $gate, bool $unlockUnaffordable = false): self
    {
        return clone($this, ['testingGate' => $gate, 'unlockUnaffordable' => $unlockUnaffordable]);
    }

    public function withTestingSkip(?string $reason, int $testingLaps): self
    {
        return clone($this, ['testingSkip' => $reason, 'testingLaps' => $testingLaps]);
    }

    /**
     * @param array<string, SmokeQuote> $smoke
     */
    public function withSmoke(array $smoke): self
    {
        return clone($this, ['smoke' => $smoke]);
    }

    /**
     * @param ?TyreAdvice $advice
     */
    public function withTyreAdvice(?array $advice): self
    {
        return clone($this, ['tyreAdvice' => $advice]);
    }
}
