<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\TyreSupplierAdvisor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TyreSupplierAdvisor::class)]
final class TyreSupplierAdvisorTest extends TestCase
{
    private const array FEED = [
        [
            'id' => 1,
            'name' => 'Pipirelli',
            'cost' => 250000,
            'peakTemperature' => 31,
            'dryPerformance' => 2,
            'rainPerformance' => 2,
            'durability' => 2,
            'warmupDistance' => 7,
        ],
        [
            'id' => 2,
            'name' => 'Avonn',
            'cost' => 1620000,
            'peakTemperature' => 33,
            'dryPerformance' => 1,
            'rainPerformance' => 7,
            'durability' => 8,
            'warmupDistance' => 5,
        ],
        [
            'id' => 3,
            'name' => 'Dunnolop',
            'cost' => 2510000,
            'peakTemperature' => 21,
            'dryPerformance' => 4,
            'rainPerformance' => 3,
            'durability' => 4,
            'warmupDistance' => 6,
        ],
        [
            'id' => 4,
            'name' => 'Yokomama',
            'cost' => 2610000,
            'peakTemperature' => 27,
            'dryPerformance' => 3,
            'rainPerformance' => 1,
            'durability' => 7,
            'warmupDistance' => 3,
        ],
        [
            'id' => 5,
            'name' => 'Contimental',
            'cost' => 3330000,
            'peakTemperature' => 37,
            'dryPerformance' => 7,
            'rainPerformance' => 2,
            'durability' => 6,
            'warmupDistance' => 8,
        ],
        [
            'id' => 6,
            'name' => 'Hancock',
            'cost' => 4440000,
            'peakTemperature' => 11,
            'dryPerformance' => 7,
            'rainPerformance' => 4,
            'durability' => 1,
            'warmupDistance' => 2,
        ],
        [
            'id' => 7,
            'name' => 'Badyear',
            'cost' => 4770000,
            'peakTemperature' => 23,
            'dryPerformance' => 5,
            'rainPerformance' => 6,
            'durability' => 5,
            'warmupDistance' => 4,
        ],
        [
            'id' => 8,
            'name' => 'Bridgerock',
            'cost' => 6400000,
            'peakTemperature' => 26,
            'dryPerformance' => 6,
            'rainPerformance' => 5,
            'durability' => 8,
            'warmupDistance' => 5,
        ],
        [
            'id' => 9,
            'name' => 'Michelini',
            'cost' => 8000000,
            'peakTemperature' => 14,
            'dryPerformance' => 8,
            'rainPerformance' => 8,
            'durability' => 2,
            'warmupDistance' => 1,
        ],
    ];

    /**
     * @return list<array{wet: float, temp: ?float, wear: float}>
     */
    private function season(): array
    {
        return array_fill(0, 17, ['wet' => 0.4, 'temp' => 25.0, 'wear' => 1.0]);
    }

    /**
     * @return array{rows: list<array{balance: int}>, cushion: int, target: int}
     */
    private function plan(int $balance): array
    {
        return [
            'rows' => array_fill(0, 17, ['balance' => $balance]),
            'cushion' => 0,
            'target' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $advice
     * @return array<string, ?string>
     */
    private function verdicts(array $advice): array
    {
        return array_column($advice['rows'], 'verdict', 'name');
    }

    /**
     * @param list<array<string, mixed>> $feed
     * @return array<string, mixed>
     */
    private function totals(array $feed, string $division, ?int $talent = null, ?int $baseline = null): array
    {
        $advice = TyreSupplierAdvisor::advise($feed, $division, $this->season(), $talent, $baseline, null, 0);
        self::assertNotNull($advice);

        return array_column($advice['rows'], 'total', 'name');
    }

    public function testWithoutMoneyAProDriverWithoutTheEdgeGetsBadyear(): void
    {
        $advice = TyreSupplierAdvisor::advise(self::FEED, 'Pro', $this->season(), 150, 193, null, 0);

        self::assertNotNull($advice);
        self::assertSame('Badyear', $advice['pick']);
        self::assertSame('best', $advice['reason']);
        self::assertSame(4770000, $advice['cost']);
        self::assertFalse($advice['finances_known']);
        self::assertFalse($advice['wet_edge']);
        self::assertSame(150, $advice['talent']);
        self::assertSame(193, $advice['baseline']);
    }

    public function testTheWetEdgeAtTheBaselineLiftsRainTyres(): void
    {
        $edge = TyreSupplierAdvisor::advise(self::FEED, 'Pro', $this->season(), 193, 193, null, 0);
        $none = TyreSupplierAdvisor::advise(self::FEED, 'Pro', $this->season(), 150, 193, null, 0);

        self::assertNotNull($edge);
        self::assertNotNull($none);
        self::assertTrue($edge['wet_edge']);
        self::assertNotSame('out_dry', $this->verdicts($edge)['Avonn']);
        self::assertGreaterThan(
            array_column($none['rows'], 'total', 'name')['Avonn'],
            array_column($edge['rows'], 'total', 'name')['Avonn'],
        );
        self::assertSame('Badyear', $edge['pick']);
    }

    public function testWithTheEdgeATightSeasonPicksAvonn(): void
    {
        $advice = TyreSupplierAdvisor::advise(
            self::FEED,
            'Pro',
            $this->season(),
            193,
            193,
            $this->plan(40000000),
            2000000,
        );

        self::assertNotNull($advice);
        self::assertSame('Avonn', $advice['pick']);
        self::assertSame('best', $advice['reason']);
        self::assertSame('out_budget', $this->verdicts($advice)['Badyear']);
        self::assertSame('out_budget', $this->verdicts($advice)['Hancock']);
    }

    public function testNoBaselineMeansNoEdge(): void
    {
        $advice = TyreSupplierAdvisor::advise(self::FEED, 'Pro', $this->season(), 250, null, null, 0);

        self::assertNotNull($advice);
        self::assertFalse($advice['wet_edge']);
        self::assertSame('Badyear', $advice['pick']);
    }

    public function testRedLines(): void
    {
        $pro = TyreSupplierAdvisor::advise(self::FEED, 'Pro', $this->season(), 150, 193, null, 0);
        self::assertNotNull($pro);
        $verdicts = $this->verdicts($pro);
        self::assertSame('out_pipirelli', $verdicts['Pipirelli']);
        self::assertSame('out_price', $verdicts['Bridgerock']);
        self::assertSame('out_price', $verdicts['Michelini']);
        self::assertSame('out_dry', $verdicts['Avonn']);
        self::assertSame('out_dry', $verdicts['Yokomama']);

        $master = TyreSupplierAdvisor::advise(self::FEED, 'Master', $this->season(), 150, 193, null, 0);
        self::assertNotNull($master);
        $outs = array_keys(array_filter(
            $this->verdicts($master),
            static fn (?string $v): bool => $v !== null && str_starts_with($v, 'out_') && $v !== 'out_dry',
        ));
        self::assertSame(['Pipirelli'], $outs);

        $edge = TyreSupplierAdvisor::advise(self::FEED, 'Pro', $this->season(), 193, 193, null, 0);
        self::assertNotNull($edge);
        self::assertNotSame('out_dry', $this->verdicts($edge)['Avonn']);
    }

    public function testRookieAndAmateurHaveNoRedLines(): void
    {
        $advice = TyreSupplierAdvisor::advise(self::FEED, 'Amateur', $this->season(), 193, 193, null, 0);

        self::assertNotNull($advice);
        self::assertNotContains('out_pipirelli', $this->verdicts($advice));
        self::assertNotContains('out_price', $this->verdicts($advice));
    }

    public function testTheBudgetDropsWhatTheSeasonCannotPay(): void
    {
        $fits = TyreSupplierAdvisor::advise(
            self::FEED,
            'Pro',
            $this->season(),
            150,
            193,
            $this->plan(50000000),
            2000000,
        );
        self::assertNotNull($fits);
        self::assertSame('Badyear', $fits['pick']);
        self::assertTrue($fits['finances_known']);

        $short = TyreSupplierAdvisor::advise(
            self::FEED,
            'Pro',
            $this->season(),
            150,
            193,
            $this->plan(40000000),
            2000000,
        );
        self::assertNotNull($short);
        $verdicts = $this->verdicts($short);
        self::assertSame('out_budget', $verdicts['Badyear']);
        self::assertSame('out_budget', $verdicts['Hancock']);
        self::assertSame('Dunnolop', $short['pick']);
        self::assertSame('band', $short['reason']);
        self::assertSame('Contimental', $short['best']);
        self::assertSame('close', $verdicts['Contimental']);
    }

    public function testMoneyShortPicksTheCheapestTheRedLinesAllow(): void
    {
        $advice = TyreSupplierAdvisor::advise(self::FEED, 'Pro', $this->season(), 150, 193, $this->plan(1000000), 0);

        self::assertNotNull($advice);
        self::assertSame('Avonn', $advice['pick']);
        self::assertSame('budget_short', $advice['reason']);
        self::assertSame(1620000, $advice['cost']);
        self::assertSame('pick', $this->verdicts($advice)['Avonn']);
        self::assertSame('out_pipirelli', $this->verdicts($advice)['Pipirelli']);
    }

    public function testMoneyShortWithOnlyPipirelliPicksTheCheapestOverall(): void
    {
        $advice = TyreSupplierAdvisor::advise(
            [self::FEED[0]],
            'Pro',
            $this->season(),
            null,
            null,
            $this->plan(1000000),
            0,
        );

        self::assertNotNull($advice);
        self::assertSame('Pipirelli', $advice['pick']);
        self::assertSame('budget_short', $advice['reason']);
    }

    public function testTheTenPercentBandGoesToTheCheaper(): void
    {
        $feed = [
            [
                'name' => 'Alpha',
                'cost' => 3000000,
                'peakTemperature' => 25,
                'dryPerformance' => 6,
                'rainPerformance' => 6,
                'durability' => 5,
                'warmupDistance' => 4,
            ],
            [
                'name' => 'Beta',
                'cost' => 2000000,
                'peakTemperature' => 25,
                'dryPerformance' => 6,
                'rainPerformance' => 6,
                'durability' => 4,
                'warmupDistance' => 4,
            ],
            [
                'name' => 'Gamma',
                'cost' => 1000000,
                'peakTemperature' => 5,
                'dryPerformance' => 1,
                'rainPerformance' => 1,
                'durability' => 1,
                'warmupDistance' => 8,
            ],
        ];
        $totals = $this->totals($feed, 'Master', 193, 193);
        self::assertGreaterThan($totals['Alpha'] * 0.9, $totals['Beta']);
        self::assertLessThan($totals['Alpha'], $totals['Beta']);

        $advice = TyreSupplierAdvisor::advise($feed, 'Master', $this->season(), 193, 193, null, 0);

        self::assertNotNull($advice);
        self::assertSame('Beta', $advice['pick']);
        self::assertSame('Alpha', $advice['best']);
        self::assertSame('band', $advice['reason']);
        self::assertSame('close', $this->verdicts($advice)['Alpha']);
        self::assertSame('pick', $this->verdicts($advice)['Beta']);
    }

    public function testEqualCostInTheBandGoesToTheHigherTotal(): void
    {
        $feed = [
            [
                'name' => 'Alpha',
                'cost' => 2000000,
                'peakTemperature' => 25,
                'dryPerformance' => 6,
                'rainPerformance' => 6,
                'durability' => 5,
                'warmupDistance' => 4,
            ],
            [
                'name' => 'Beta',
                'cost' => 2000000,
                'peakTemperature' => 25,
                'dryPerformance' => 6,
                'rainPerformance' => 6,
                'durability' => 4,
                'warmupDistance' => 4,
            ],
            [
                'name' => 'Gamma',
                'cost' => 1000000,
                'peakTemperature' => 5,
                'dryPerformance' => 1,
                'rainPerformance' => 1,
                'durability' => 1,
                'warmupDistance' => 8,
            ],
        ];

        $advice = TyreSupplierAdvisor::advise($feed, 'Master', $this->season(), 193, 193, null, 0);

        self::assertNotNull($advice);
        self::assertSame('Alpha', $advice['pick']);
        self::assertSame('best', $advice['reason']);
    }

    public function testRowsListUnOutRowsFirstThenOutRowsByTotal(): void
    {
        $advice = TyreSupplierAdvisor::advise(self::FEED, 'Pro', $this->season(), 150, 193, null, 0);

        self::assertNotNull($advice);
        $seenOut = false;
        $previous = [false => INF, true => INF];
        foreach ($advice['rows'] as $row) {
            $out = $row['verdict'] !== null && str_starts_with($row['verdict'], 'out_');
            self::assertFalse($seenOut && !$out, 'an un-out row follows an out row');
            $seenOut = $seenOut || $out;
            self::assertLessThanOrEqual($previous[$out], $row['total']);
            $previous[$out] = $row['total'];
            self::assertSame($row['cost'] * 17, $row['season_cost']);
        }
    }

    public function testMalformedSuppliersAreSkipped(): void
    {
        $feed = self::FEED;
        $feed[] = [
            'name' => 'NoDry',
            'cost' => 1000000,
            'peakTemperature' => 25,
            'rainPerformance' => 5,
            'durability' => 5,
            'warmupDistance' => 4,
        ];
        $feed[] = [
            'name' => 'Text',
            'cost' => 'cheap',
            'peakTemperature' => 25,
            'dryPerformance' => 5,
            'rainPerformance' => 5,
            'durability' => 5,
            'warmupDistance' => 4,
        ];
        $feed[] = [
            'name' => 'Free',
            'cost' => 0,
            'peakTemperature' => 25,
            'dryPerformance' => 5,
            'rainPerformance' => 5,
            'durability' => 5,
            'warmupDistance' => 4,
        ];
        $feed[] = [
            'name' => '',
            'cost' => 1000000,
            'peakTemperature' => 25,
            'dryPerformance' => 5,
            'rainPerformance' => 5,
            'durability' => 5,
            'warmupDistance' => 4,
        ];

        $advice = TyreSupplierAdvisor::advise($feed, 'Pro', $this->season(), 150, 193, null, 0);
        $clean = TyreSupplierAdvisor::advise(self::FEED, 'Pro', $this->season(), 150, 193, null, 0);

        self::assertNotNull($advice);
        self::assertSame($clean, $advice);
        self::assertCount(9, $advice['rows']);
    }

    public function testAnAttributeEveryoneSharesScalesToHalf(): void
    {
        $feed = [
            [
                'name' => 'Alpha',
                'cost' => 2000000,
                'peakTemperature' => 25,
                'dryPerformance' => 6,
                'rainPerformance' => 6,
                'durability' => 5,
                'warmupDistance' => 4,
            ],
            [
                'name' => 'Beta',
                'cost' => 3000000,
                'peakTemperature' => 25,
                'dryPerformance' => 6,
                'rainPerformance' => 6,
                'durability' => 3,
                'warmupDistance' => 4,
            ],
        ];

        $advice = TyreSupplierAdvisor::advise($feed, 'Master', $this->season(), 193, 193, null, 0);

        self::assertNotNull($advice);
        $rows = array_column($advice['rows'], null, 'name');
        self::assertEqualsWithDelta(17 * 0.5, $rows['Alpha']['parts']['performance'], 1e-9);
        self::assertEqualsWithDelta(17 * 0.5, $rows['Beta']['parts']['warmup'], 1e-9);
        self::assertEqualsWithDelta(17.0, $rows['Alpha']['parts']['temperature'], 1e-9);
        self::assertEqualsWithDelta(17.0, $rows['Alpha']['parts']['durability'], 1e-9);
        self::assertEqualsWithDelta(0.0, $rows['Beta']['parts']['durability'], 1e-9);
        self::assertFalse(is_nan($rows['Alpha']['total']));
    }

    public function testNoRacesOrNoSuppliersGiveNoAdvice(): void
    {
        self::assertNull(TyreSupplierAdvisor::advise(self::FEED, 'Pro', [], 150, 193, null, 0));
        self::assertNull(TyreSupplierAdvisor::advise([], 'Pro', $this->season(), 150, 193, null, 0));
        self::assertNull(
            TyreSupplierAdvisor::advise([['name' => 'Broken']], 'Pro', $this->season(), 150, 193, null, 0),
        );
    }

    public function testAnUnknownTrackUsesTheFallbacks(): void
    {
        $corpus = ['tracks' => [], 'all' => ['races' => 40, 'wet' => 0.25, 'temp' => 18.0]];

        $races = TyreSupplierAdvisor::races(['Atlantis', null], $corpus, ['Monza' => 1.0]);

        self::assertSame([
            ['wet' => 0.25, 'temp' => 18.0, 'wear' => 1.0],
            ['wet' => 0.25, 'temp' => 18.0, 'wear' => 1.0],
        ], $races);
    }

    public function testRacesUseTheTrackOnlyWithEnoughCorpus(): void
    {
        $corpus = [
            'tracks' => [
                'Monza' => ['races' => 8, 'wet' => 0.1, 'temp_races' => 3, 'temp' => 30.0],
                'Brno' => ['races' => 7, 'wet' => 0.9, 'temp_races' => 2, 'temp' => 5.0],
            ],
            'all' => ['races' => 100, 'wet' => 0.4, 'temp' => 20.0],
        ];

        $races = TyreSupplierAdvisor::races(['Monza (Italy)', 'Brno (Czech Republic)'], $corpus, []);

        self::assertSame(0.1, $races[0]['wet']);
        self::assertSame(30.0, $races[0]['temp']);
        self::assertSame(0.4, $races[1]['wet']);
        self::assertSame(20.0, $races[1]['temp']);
    }

    public function testRacesFallBackToTheDefaultWetShareOnAnEmptyCorpus(): void
    {
        $corpus = ['tracks' => [], 'all' => ['races' => 0, 'wet' => null, 'temp' => null]];

        $races = TyreSupplierAdvisor::races(['Monza'], $corpus, []);

        self::assertSame([['wet' => 0.4, 'temp' => null, 'wear' => 1.0]], $races);
    }

    public function testNoTemperatureDataMakesFitHalfForEverySupplier(): void
    {
        $races = array_fill(0, 17, ['wet' => 0.4, 'temp' => null, 'wear' => 1.0]);

        $advice = TyreSupplierAdvisor::advise(self::FEED, 'Pro', $races, 150, 193, null, 0);

        self::assertNotNull($advice);
        foreach ($advice['rows'] as $row) {
            self::assertEqualsWithDelta(17 * 0.5, $row['parts']['temperature'], 1e-9);
        }
    }

    public function testWearIsRelativeToTheMedianAndClamped(): void
    {
        $corpus = ['tracks' => [], 'all' => ['races' => 0, 'wet' => null, 'temp' => null]];
        $wear = ['A' => 100.0, 'B' => 200.0, 'C' => 100.0, 'D' => 400.0, 'E' => 10.0];

        $races = TyreSupplierAdvisor::races(['A', 'B', 'C', 'D', 'E', 'Unknown'], $corpus, $wear);

        self::assertSame([1.0, 1.5, 1.0, 1.5, 0.5, 1.0], array_column($races, 'wear'));
    }

    public function testWearOfTwiceTheMedianIsOneAndAHalf(): void
    {
        $corpus = ['tracks' => [], 'all' => ['races' => 0, 'wet' => null, 'temp' => null]];

        $races = TyreSupplierAdvisor::races(['A', 'B', 'C'], $corpus, ['A' => 100.0, 'B' => 100.0, 'C' => 200.0]);

        self::assertSame([1.0, 1.0, 1.5], array_column($races, 'wear'));
    }

    public function testWearIsOneWhenNoTrackHasData(): void
    {
        $corpus = ['tracks' => [], 'all' => ['races' => 0, 'wet' => null, 'temp' => null]];

        self::assertSame([1.0], array_column(TyreSupplierAdvisor::races(['A'], $corpus, []), 'wear'));
    }

    public function testTheWetEdgeCapsTheWetChanceAtOne(): void
    {
        $wet = array_fill(0, 17, ['wet' => 0.8, 'temp' => 25.0, 'wear' => 1.0]);
        $edge = TyreSupplierAdvisor::advise(self::FEED, 'Master', $wet, 200, 193, null, 0);
        $none = TyreSupplierAdvisor::advise(self::FEED, 'Master', $wet, 100, 193, null, 0);

        self::assertNotNull($edge);
        self::assertNotNull($none);
        $avonnEdge = array_column($edge['rows'], null, 'name')['Avonn']['parts']['performance'];
        $avonnNone = array_column($none['rows'], null, 'name')['Avonn']['parts']['performance'];
        self::assertEqualsWithDelta(17 * 6 / 7, $avonnEdge, 1e-9);
        self::assertEqualsWithDelta(17 * 0.8 * 6 / 7, $avonnNone, 1e-9);
    }
}
