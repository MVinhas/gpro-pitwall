<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\SeasonBudget;
use App\Engineer\SeasonObjective;
use App\Engineer\UpgradeRanker;
use App\Service\CarWearService;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UpgradeRanker::class)]
final class UpgradeRankerTest extends TestCase
{
    private SeasonBudget $budget;

    protected function setUp(): void
    {
        $wear = new CarWearService(new PDO('sqlite::memory:'), [
            'driver_wear_factors' => [],
            'part_level_factors' => [1 => 1.02, 2 => 1.03],
        ]);
        $this->budget = new SeasonBudget($wear);
    }

    /**
     * @param array<string, array{power: int, handling: int, acceleration: int}> $contribution
     */
    private function ranker(array $contribution): UpgradeRanker
    {
        return new UpgradeRanker($this->budget, $contribution);
    }

    /**
     * @return array<string, array{power: int, handling: int, acceleration: int}>
     */
    private function engineOnly(): array
    {
        return ['Engine' => ['power' => 2, 'handling' => 0, 'acceleration' => 0]];
    }

    /**
     * @return list<array{race: int, track_name: ?string, base: ?array<string, float>}>
     */
    private function rounds(float $base = 5.0, int $count = 2, int $first = 8): array
    {
        $rounds = [];
        for ($i = 0; $i < $count; $i++) {
            $rounds[] = [
                'race' => $first + $i,
                'track_name' => null,
                'base' => ['Engine' => $base, 'Chassis' => $base],
            ];
        }

        return $rounds;
    }

    /**
     * @return array<string, array{level: int, start: int|float}>
     */
    private function parts(int $start = 0): array
    {
        return [
            'Engine' => ['level' => 1, 'start' => $start],
            'Chassis' => ['level' => 1, 'start' => 0],
        ];
    }

    /**
     * The plan the ranker compares against: the season forecast of the parts as they are.
     *
     * @param list<array{race: int, track_name: ?string, base: ?array<string, float>}> $rounds
     * @param array<string, array{level: int, start: int|float}> $parts
     * @param array<string, ?int> $prices
     * @return array<string, mixed>
     */
    private function plan(array $rounds, array $parts, array $prices, int $ctr = 0): array
    {
        $forecast = $this->budget->forecast($rounds, $parts, $prices, 1.0, $ctr, 100, 10_000_000, 0, 0);

        return ['ctr' => $ctr, 'rows' => $forecast['rows']];
    }

    /**
     * @param array<string, mixed> $plan
     * @param list<array{race: int, track_name: ?string, base: ?array<string, float>}> $rounds
     * @param array<int, ?array{power: int, handling: int, acceleration: int}> $demands
     * @param array<string, array{level: int, start: int|float}> $parts
     * @param array<string, ?int> $prices
     * @param array<string, list<array{level: int, price: int}>> $options
     * @return list<array{part: string, from: int, to: int, price: int, net_cost: int, gain: float, affordable: bool}>
     */
    private function rank(
        UpgradeRanker $ranker,
        array $plan,
        array $rounds,
        array $demands,
        array $parts,
        array $prices,
        array $options,
        int $budget = 0,
    ): array {
        return $ranker->rank(
            $plan,
            SeasonObjective::HOLD,
            $rounds,
            $demands,
            $parts,
            $prices,
            $options,
            1.0,
            10_000_000,
            0,
            0,
            $budget,
        );
    }

    /**
     * @return array<int, array{power: int, handling: int, acceleration: int}>
     */
    private function evenDemand(): array
    {
        return [
            8 => ['power' => 10, 'handling' => 5, 'acceleration' => 5],
            9 => ['power' => 5, 'handling' => 5, 'acceleration' => 10],
        ];
    }

    public function testGainWeightsEachRaceByItsTrackDemand(): void
    {
        $rounds = $this->rounds();
        $parts = $this->parts();
        $prices = ['Engine' => 100_000, 'Chassis' => 100_000];

        $out = $this->rank(
            $this->ranker($this->engineOnly()),
            $this->plan($rounds, $parts, $prices),
            $rounds,
            $this->evenDemand(),
            $parts,
            $prices,
            ['Engine' => [['level' => 2, 'price' => 100_000]]],
        );

        $this->assertCount(1, $out);
        $this->assertSame(1.5, $out[0]['gain']);
        $this->assertSame('Engine', $out[0]['part']);
        $this->assertSame(1, $out[0]['from']);
        $this->assertSame(2, $out[0]['to']);
        $this->assertSame(100_000, $out[0]['price']);
    }

    public function testRaceWithUnknownDemandAddsNothing(): void
    {
        $rounds = $this->rounds();
        $parts = $this->parts();
        $prices = ['Engine' => 100_000, 'Chassis' => 100_000];
        $options = ['Engine' => [['level' => 2, 'price' => 100_000]]];
        $plan = $this->plan($rounds, $parts, $prices);
        $ranker = $this->ranker($this->engineOnly());

        $one = $this->rank($ranker, $plan, $rounds, [8 => ['power' => 10, 'handling' => 5, 'acceleration' => 5], 9 => null], $parts, $prices, $options);
        $this->assertSame(1.0, $one[0]['gain']);

        $none = $this->rank($ranker, $plan, $rounds, [8 => null, 9 => null], $parts, $prices, $options);
        $this->assertSame([], $none);

        $zero = $this->rank($ranker, $plan, $rounds, [8 => ['power' => 0, 'handling' => 0, 'acceleration' => 0]], $parts, $prices, $options);
        $this->assertSame([], $zero);
    }

    public function testNetCostChargesExtraRepairsOfTheHigherLevel(): void
    {
        // At CTR 50 level 1 wears 26.9 a race and survives three; level 2 wears 43.8 and needs a swap in race 3.
        $rounds = $this->rounds(10.0, 3);
        $parts = $this->parts();
        $prices = ['Engine' => 100_000, 'Chassis' => 100_000];
        $plan = $this->plan($rounds, $parts, $prices, 50);
        $this->assertSame(0, array_sum(array_column($plan['rows'], 'repairs')));

        $out = $this->rank(
            $this->ranker($this->engineOnly()),
            $plan,
            $rounds,
            $this->evenDemand(),
            $parts,
            $prices,
            ['Engine' => [['level' => 2, 'price' => 250_000]]],
        );

        $this->assertSame(250_000 + 250_000, $out[0]['net_cost']);
    }

    public function testUpgradingAPartThePlanReplacesCostsTheDifference(): void
    {
        $rounds = $this->rounds(10.0);
        $parts = $this->parts(95);
        $prices = ['Engine' => 1_000_000, 'Chassis' => 100_000];
        $plan = $this->plan($rounds, $parts, $prices);
        $this->assertSame(1_000_000, $plan['rows'][0]['repairs']);

        $out = $this->rank(
            $this->ranker($this->engineOnly()),
            $plan,
            $rounds,
            $this->evenDemand(),
            $parts,
            $prices,
            ['Engine' => [['level' => 2, 'price' => 1_300_000]]],
        );

        $this->assertSame(300_000, $out[0]['net_cost']);
    }

    public function testFreeOrCheaperUpgradesRankFirst(): void
    {
        $rounds = $this->rounds(10.0);
        $parts = $this->parts(95);
        $prices = ['Engine' => 1_000_000, 'Chassis' => 100_000];
        $contribution = [
            'Engine' => ['power' => 1, 'handling' => 0, 'acceleration' => 0],
            'Chassis' => ['power' => 9, 'handling' => 0, 'acceleration' => 0],
        ];

        $out = $this->rank(
            $this->ranker($contribution),
            $this->plan($rounds, $parts, $prices),
            $rounds,
            $this->evenDemand(),
            $parts,
            $prices,
            [
                'Chassis' => [['level' => 2, 'price' => 100_000]],
                'Engine' => [['level' => 2, 'price' => 800_000]],
            ],
        );

        $this->assertSame(['Engine', 'Chassis'], array_column($out, 'part'));
        $this->assertSame(-200_000, $out[0]['net_cost']);
        $this->assertSame(100_000, $out[1]['net_cost']);
    }

    public function testPaidUpgradesRankByGainPerNetDollarThenByPartLabel(): void
    {
        $rounds = $this->rounds();
        $parts = $this->parts();
        $prices = ['Engine' => 100_000, 'Chassis' => 100_000];
        $contribution = [
            'Engine' => ['power' => 1, 'handling' => 0, 'acceleration' => 0],
            'Chassis' => ['power' => 3, 'handling' => 0, 'acceleration' => 0],
        ];
        $plan = $this->plan($rounds, $parts, $prices);
        $ranker = $this->ranker($contribution);

        $out = $this->rank(
            $ranker,
            $plan,
            $rounds,
            $this->evenDemand(),
            $parts,
            $prices,
            [
                'Engine' => [['level' => 2, 'price' => 100_000]],
                'Chassis' => [['level' => 2, 'price' => 200_000]],
            ],
        );
        // Chassis: 2.25 gain for 200k beats Engine: 0.75 for 100k.
        $this->assertSame(['Chassis', 'Engine'], array_column($out, 'part'));

        $better = $this->rank(
            $ranker,
            $plan,
            $rounds,
            $this->evenDemand(),
            $parts,
            $prices,
            [
                'Engine' => [['level' => 2, 'price' => 100_000]],
                'Chassis' => [['level' => 2, 'price' => 400_000]],
            ],
        );
        $this->assertSame(['Engine', 'Chassis'], array_column($better, 'part'));
    }

    public function testOnlyTheBestLevelPerPartIsKept(): void
    {
        $rounds = $this->rounds();
        $parts = $this->parts();
        $prices = ['Engine' => 100_000, 'Chassis' => 100_000];

        $out = $this->rank(
            $this->ranker($this->engineOnly()),
            $this->plan($rounds, $parts, $prices),
            $rounds,
            $this->evenDemand(),
            $parts,
            $prices,
            ['Engine' => [['level' => 2, 'price' => 100_000], ['level' => 3, 'price' => 300_000]]],
        );

        $this->assertCount(1, $out);
        $this->assertSame(2, $out[0]['to']);
    }

    public function testAnAffordableLevelBeatsABetterOneTheBudgetCannotPay(): void
    {
        $rounds = $this->rounds();
        $parts = $this->parts();
        $prices = ['Engine' => 100_000, 'Chassis' => 100_000];

        // L3 scores better (3.0 for 400k against 1.5 for 300k) but only L2 fits 350k.
        $out = $this->rank(
            $this->ranker($this->engineOnly()),
            $this->plan($rounds, $parts, $prices),
            $rounds,
            $this->evenDemand(),
            $parts,
            $prices,
            ['Engine' => [['level' => 2, 'price' => 300_000], ['level' => 3, 'price' => 400_000]]],
            350_000,
        );

        $this->assertCount(1, $out);
        $this->assertSame(2, $out[0]['to']);
        $this->assertTrue($out[0]['affordable']);
    }

    public function testPartWithoutContributionIsNeverListed(): void
    {
        $rounds = $this->rounds();
        $parts = $this->parts();
        $prices = ['Engine' => 100_000, 'Chassis' => 100_000];

        $out = $this->rank(
            $this->ranker($this->engineOnly()),
            $this->plan($rounds, $parts, $prices),
            $rounds,
            $this->evenDemand(),
            $parts,
            $prices,
            ['Chassis' => [['level' => 2, 'price' => 100_000]]],
        );

        $this->assertSame([], $out);
    }

    public function testNoOptionsGiveAnEmptyList(): void
    {
        $rounds = $this->rounds();
        $parts = $this->parts();
        $prices = ['Engine' => 100_000, 'Chassis' => 100_000];

        $out = $this->rank(
            $this->ranker($this->engineOnly()),
            $this->plan($rounds, $parts, $prices),
            $rounds,
            $this->evenDemand(),
            $parts,
            $prices,
            [],
        );

        $this->assertSame([], $out);
    }

    public function testAffordableComparesNetCostWithTheBudget(): void
    {
        $rounds = $this->rounds();
        $parts = $this->parts();
        $prices = ['Engine' => 100_000, 'Chassis' => 100_000];
        $plan = $this->plan($rounds, $parts, $prices);
        $options = ['Engine' => [['level' => 2, 'price' => 400_000]]];
        $ranker = $this->ranker($this->engineOnly());

        $exact = $this->rank($ranker, $plan, $rounds, $this->evenDemand(), $parts, $prices, $options, 400_000);
        $this->assertSame(400_000, $exact[0]['net_cost']);
        $this->assertTrue($exact[0]['affordable']);

        $short = $this->rank($ranker, $plan, $rounds, $this->evenDemand(), $parts, $prices, $options, 399_999);
        $this->assertFalse($short[0]['affordable']);
    }

    public function testInfeasibleForecastDropsTheCandidate(): void
    {
        // A fresh level-2 engine would reach the limit inside one race (110 > 100).
        $rounds = $this->rounds(110.0, 2);
        $parts = $this->parts();
        $prices = ['Engine' => 100_000, 'Chassis' => 100_000];
        $plan = ['ctr' => 0, 'rows' => $this->plan($this->rounds(), $parts, $prices)['rows']];

        $out = $this->rank(
            $this->ranker($this->engineOnly()),
            $plan,
            $rounds,
            $this->evenDemand(),
            $parts,
            $prices,
            ['Engine' => [['level' => 2, 'price' => 100_000]]],
        );

        $this->assertSame([], $out);
    }
}
