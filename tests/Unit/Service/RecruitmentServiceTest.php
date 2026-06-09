<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\IdealPilotService;
use App\Service\RecruitmentService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecruitmentService::class)]
final class RecruitmentServiceTest extends TestCase
{
    /** @var array<string, string> */
    private const array CSV_MAP = [
        'CON' => 'Concentration',
        'TAL' => 'Talent',
        'EXP' => 'Experience',
        'AGG' => 'Aggressiveness',
        'TEI' => 'Technical Insight',
        'STA' => 'Stamina',
        'CHA' => 'Charisma',
        'MOT' => 'Motivation',
        'WEI' => 'Weight',
        'AGE' => 'Age',
        'FAV' => 'Favourite Tracks',
        'OFF' => 'Offers',
    ];

    /** @var array<string, int> */
    private const array CAPS = [
        'Rookie' => 85, 'Amateur' => 110, 'Pro' => 135, 'Master' => 160, 'Elite' => 999,
    ];

    /**
     * @return array{stats: array<string, int>, count: int}
     */
    private function ideal(): array
    {
        // Realistic-ish Rookie ideal.
        return [
            'count' => 5,
            'stats' => [
                'Concentration' => 100, 'Talent' => 100, 'Aggressiveness' => 50,
                'Experience' => 50, 'Technical Insight' => 50, 'Stamina' => 50,
                'Charisma' => 50, 'Motivation' => 50,
                'Weight (Kg)' => 90, 'Age' => 28,
            ],
        ];
    }

    private function service(): RecruitmentService
    {
        $ideal = $this->ideal();
        $stub = new class ($ideal) extends IdealPilotService {
            /** @param array{stats: array<string, mixed>, count: int} $ideal */
            public function __construct(private readonly array $ideal)
            {
                // Skip parent constructor — no DB.
            }
            public function getIdealPilot(string $division): array
            {
                return $this->ideal;
            }
        };
        return new RecruitmentService(self::CSV_MAP, self::CAPS, $stub);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return list<array<string, mixed>>
     */
    private function market(array $overrides = []): array
    {
        // Perfect-score baseline driver: matches ideal exactly.
        $perfect = array_merge([
            'NAME' => 'Test', 'ID' => 1, 'OA' => 60, 'RET' => 0, 'OFF' => 0,
            'CON' => 100, 'TAL' => 100, 'AGG' => 50, 'EXP' => 50,
            'TEI' => 50, 'STA' => 50, 'CHA' => 50, 'MOT' => 50,
            'WEI' => 90, 'AGE' => 28,
        ], $overrides);
        return [$perfect];
    }

    public function testPerfectMatchScoresOneHundred(): void
    {
        $out = $this->service()->analyze($this->market(), 'Rookie', false);
        $this->assertCount(1, $out);
        $this->assertSame(100.0, $out[0]['rating']);
    }

    public function testTenBelowConcentrationCostsOnePoint(): void
    {
        $out = $this->service()->analyze($this->market(['CON' => 90]), 'Rookie', false);
        // 100 - (10 * 0.1) = 99.
        $this->assertSame(99.0, $out[0]['rating']);
    }

    public function testExceedingIdealCostsNothing(): void
    {
        $out = $this->service()->analyze($this->market(['CON' => 200, 'TAL' => 250]), 'Rookie', false);
        $this->assertSame(100.0, $out[0]['rating']);
    }

    public function testOlderAgePenaltyIsTwoPerYear(): void
    {
        $out = $this->service()->analyze($this->market(['AGE' => 31]), 'Rookie', false);
        // 3 years older × -2 = -6.
        $this->assertSame(94.0, $out[0]['rating']);
    }

    public function testYoungerAgeBonusIsHalfPerYear(): void
    {
        $out = $this->service()->analyze($this->market(['AGE' => 24]), 'Rookie', false);
        // Score starts capped at 100, +0.5 × 4 = +2 → still 100 (ceiling).
        $this->assertSame(100.0, $out[0]['rating']);
    }

    public function testYoungerAgeBonusOffsetsAttributeGap(): void
    {
        $out = $this->service()->analyze(
            $this->market(['CON' => 50, 'AGE' => 24]),
            'Rookie',
            false,
        );
        // -50 con * 0.1 = -5; +4 years younger * 0.5 = +2 → 100 - 5 + 2 = 97.
        $this->assertSame(97.0, $out[0]['rating']);
    }

    public function testHeavierWeightCostsHalfPerKg(): void
    {
        $out = $this->service()->analyze($this->market(['WEI' => 100]), 'Rookie', false);
        // 10 kg heavier × -0.5 = -5.
        $this->assertSame(95.0, $out[0]['rating']);
    }

    public function testLighterWeightBonusIsEighthPerKg(): void
    {
        $out = $this->service()->analyze(
            $this->market(['CON' => 50, 'WEI' => 82]),
            'Rookie',
            false,
        );
        // -50 con * 0.1 = -5; 8 kg lighter * 0.125 = +1 → 96.
        $this->assertSame(96.0, $out[0]['rating']);
    }

    public function testScoreFloorsAtZeroAndIsFilteredOut(): void
    {
        // Wildly under-spec driver: deduction passes 100, score floors at 0,
        // and gets culled by the MIN_RATING filter (so the result is empty).
        $out = $this->service()->analyze(
            $this->market([
                'CON' => 0, 'TAL' => 0, 'AGG' => 0, 'EXP' => 0,
                'TEI' => 0, 'STA' => 0, 'CHA' => 0, 'MOT' => 0,
                'AGE' => 50, 'WEI' => 140,
            ]),
            'Rookie',
            false,
        );
        $this->assertSame([], $out);
    }

    public function testDriversBelowMinRatingAreHidden(): void
    {
        // Score < 50 — must be culled so the session result set stays bounded
        // on a full ~4-5k-driver market.
        $out = $this->service()->analyze(
            $this->market(['CON' => 0, 'TAL' => 0, 'AGG' => 0, 'EXP' => 0, 'TEI' => 0,
                           'STA' => 0, 'CHA' => 0, 'MOT' => 0, 'WEI' => 110, 'AGE' => 35]),
            'Rookie',
            false,
        );
        $this->assertSame([], $out);
    }

    public function testFilterOffersStripsDriversWithExistingOffers(): void
    {
        $out = $this->service()->analyze($this->market(['OFF' => 1]), 'Rookie', true);
        $this->assertSame([], $out);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rangeFilterFieldProvider(): iterable
    {
        foreach (array_keys(RecruitmentService::RANGE_FILTER_FIELDS) as $field) {
            yield $field => [$field];
        }
    }

    #[DataProvider('rangeFilterFieldProvider')]
    public function testRangeFiltersAreInclusiveForEverySupportedField(string $field): void
    {
        $drivers = [
            ['NAME' => 'Below minimum', $field => 99],
            ['NAME' => 'At minimum', $field => 100],
            ['NAME' => 'At maximum', $field => 200],
            ['NAME' => 'Above maximum', $field => 201],
        ];

        $out = $this->service()->filterByRanges(
            $drivers,
            [$field => ['min' => 100, 'max' => 200]],
        );

        $this->assertSame([
            ['NAME' => 'At minimum', $field => 100],
            ['NAME' => 'At maximum', $field => 200],
        ], $out);
    }

    public function testMinimumOnlyRangeFiltersValuesBelowTheMinimum(): void
    {
        $drivers = [
            ['NAME' => 'Below', 'OA' => 79],
            ['NAME' => 'At minimum', 'OA' => 80],
            ['NAME' => 'Above', 'OA' => 81],
        ];

        $out = $this->service()->filterByRanges($drivers, ['OA' => ['min' => 80]]);

        $this->assertSame([
            ['NAME' => 'At minimum', 'OA' => 80],
            ['NAME' => 'Above', 'OA' => 81],
        ], $out);
    }

    public function testMaximumOnlyRangeFiltersValuesAboveTheMaximum(): void
    {
        $drivers = [
            ['NAME' => 'Below', 'OA' => 79],
            ['NAME' => 'At maximum', 'OA' => 80],
            ['NAME' => 'Above', 'OA' => 81],
        ];

        $out = $this->service()->filterByRanges($drivers, ['OA' => ['max' => 80]]);

        $this->assertSame([
            ['NAME' => 'Below', 'OA' => 79],
            ['NAME' => 'At maximum', 'OA' => 80],
        ], $out);
    }

    public function testRangeFiltersUseAndSemanticsAcrossAttributes(): void
    {
        $drivers = [
            ['NAME' => 'Matches', 'OA' => 80, 'AGE' => 25],
            ['NAME' => 'OA too low', 'OA' => 79, 'AGE' => 25],
            ['NAME' => 'OA too high', 'OA' => 91, 'AGE' => 25],
            ['NAME' => 'Age too low', 'OA' => 80, 'AGE' => 19],
            ['NAME' => 'Age too high', 'OA' => 80, 'AGE' => 31],
        ];

        $out = $this->service()->filterByRanges($drivers, [
            'OA' => ['min' => 80, 'max' => 90],
            'AGE' => ['min' => 20, 'max' => 30],
        ]);

        $this->assertSame([['NAME' => 'Matches', 'OA' => 80, 'AGE' => 25]], $out);
    }

    public function testRangeFilterNormalizationSupportsOneSidedAndCombinedRanges(): void
    {
        $out = $this->service()->normalizeRangeFilters([
            'min_OA' => '60',
            'max_OA' => '85',
            'min_AGE' => '20',
            'max_AGE' => '',
            'min_SAL' => '',
            'max_SAL' => '1000.5',
        ]);

        $this->assertSame([
            'OA' => ['min' => 60, 'max' => 85],
            'AGE' => ['min' => 20],
            'SAL' => ['max' => 1000.5],
        ], $out);
    }

    public function testRangeFilterNormalizationIgnoresInvalidBounds(): void
    {
        $out = $this->service()->normalizeRangeFilters([
            'min_OA' => 'not-a-number',
            'max_OA' => '85',
            'min_CON' => '-1',
            'max_CON' => '100',
            'min_TAL' => '1e309',
            'max_TAL' => true,
            'min_AGE' => NAN,
            'max_AGE' => INF,
            'min_SAL' => '1000.5',
            'max_FEE' => ['invalid'],
            'min_UNKNOWN' => '10',
        ]);

        $this->assertSame([
            'OA' => ['max' => 85],
            'CON' => ['max' => 100],
            'SAL' => ['min' => 1000.5],
        ], $out);
    }

    public function testRangeFilterNormalizationIgnoresAttributeWhenMinimumExceedsMaximum(): void
    {
        $out = $this->service()->normalizeRangeFilters([
            'min_OA' => '86',
            'max_OA' => '85',
            'min_AGE' => '20',
            'max_AGE' => '30',
        ]);

        $this->assertSame(['AGE' => ['min' => 20, 'max' => 30]], $out);
    }

    public function testRangeFiltersRunBeforeSortingAndPagination(): void
    {
        $drivers = [
            ['NAME' => 'Below range', 'OA' => 60, 'rating' => 100],
            ['NAME' => 'Second', 'OA' => 80, 'rating' => 80],
            ['NAME' => 'First', 'OA' => 70, 'rating' => 90],
            ['NAME' => 'Above range', 'OA' => 90, 'rating' => 95],
        ];

        $filtered = $this->service()->filterByRanges(
            $drivers,
            ['OA' => ['min' => 70, 'max' => 80]],
        );
        $page = $this->service()->sortAndPaginate($filtered, 'rating', 'desc', 1, 1);

        $this->assertSame('First', $page['data'][0]['NAME']);
        $this->assertSame(2, $page['pagination']['total_items']);
        $this->assertSame(2, $page['pagination']['total_pages']);
    }

    public function testRangeFiltersCanProduceAnEmptyResultPage(): void
    {
        $filtered = $this->service()->filterByRanges(
            [['NAME' => 'Candidate', 'OA' => 60]],
            ['OA' => ['min' => 70, 'max' => 80]],
        );
        $page = $this->service()->sortAndPaginate($filtered, 'rating', 'desc', 1, 20);

        $this->assertSame([], $page['data']);
        $this->assertSame(0, $page['pagination']['total_items']);
        $this->assertSame(0, $page['pagination']['total_pages']);
        $this->assertSame(1, $page['pagination']['current']);
    }

    public function testSeasonRaceTrackIdsKeepsOnlyRacesAndDedupes(): void
    {
        $calendar = [
            'events' => [
                ['eventType' => 'SD', 'trackId' => '1'],   // staff day — ignored
                ['eventType' => 'R',  'trackId' => '25'],
                ['eventType' => 'R',  'trackId' => '10'],
                ['eventType' => 'R',  'trackId' => '25'],  // dup — collapsed
            ],
            'nextSeasonEvents' => [
                ['eventType' => 'R', 'trackId' => '34'],
                ['eventType' => 'SD', 'trackId' => '1'],
            ],
        ];

        $out = $this->service()->seasonRaceTrackIds($calendar);

        $this->assertSame([25, 10], $out['current']);
        $this->assertSame([34], $out['next']);
    }

    public function testSeasonRaceTrackIdsHandlesMissingCalendar(): void
    {
        $out = $this->service()->seasonRaceTrackIds([]);
        $this->assertSame(['current' => [], 'next' => []], $out);
    }

    public function testTagFavouriteTracksCountsMatchesAndResolvesNames(): void
    {
        $drivers = [
            ['NAME' => 'A', 'FAV' => [25, 10, 99]],
            ['NAME' => 'B', 'FAV' => []],
            ['NAME' => 'C'],  // no FAV key at all
        ];
        $seasonTracks = ['current' => [25, 10], 'next' => [34]];
        $trackNames = [25 => 'Paul Ricard', 10 => 'Spa', 34 => 'Melbourne'];

        $out = $this->service()->tagFavouriteTracks($drivers, $seasonTracks, $trackNames);

        $this->assertSame(2, $out[0]['FAV_current']);
        $this->assertSame(['Paul Ricard', 'Spa'], $out[0]['FAV_current_names']);
        $this->assertSame(0, $out[0]['FAV_next']);

        $this->assertSame(0, $out[1]['FAV_current']);
        $this->assertSame(0, $out[2]['FAV_current']);
        $this->assertSame([], $out[2]['FAV_next_names']);
    }
}
