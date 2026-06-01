<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\IdealPilotService;
use App\Service\RecruitmentService;
use PHPUnit\Framework\Attributes\CoversClass;
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
}
