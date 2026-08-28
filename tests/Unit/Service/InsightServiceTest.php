<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\InsightService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InsightService::class)]
final class InsightServiceTest extends TestCase
{
    /** @var list<string> */
    private const array DIVISIONS = ['Rookie', 'Amateur', 'Pro'];

    /**
     * @param array<string, int> $stats
     * @return array{stats: array<string, int>, count: int}
     */
    private function division(array $stats, int $count = 10): array
    {
        return ['stats' => $stats, 'count' => $count];
    }

    public function testOnePairingIsProducedPerAdjacentDivision(): void
    {
        $result = (new InsightService(self::DIVISIONS))->generateInsights([]);

        $this->assertSame(['Rookie-Amateur', 'Amateur-Pro'], array_keys($result));
    }

    public function testASingleDivisionYieldsNoPairings(): void
    {
        $this->assertSame([], (new InsightService(['Rookie']))->generateInsights([]));
    }

    public function testNoDivisionsYieldsNoPairings(): void
    {
        $this->assertSame([], (new InsightService([]))->generateInsights([]));
    }

    public function testAPairingWithNoDataIsFlaggedAndCarriesNoInsights(): void
    {
        $result = (new InsightService(self::DIVISIONS))->generateInsights([]);

        $this->assertFalse($result['Rookie-Amateur']['has_data']);
        $this->assertSame([], $result['Rookie-Amateur']['insights']);
        $this->assertSame(0, $result['Rookie-Amateur']['count_lower']);
    }

    public function testAPairingNeedsBothSidesPopulatedToHaveData(): void
    {
        $service = new InsightService(self::DIVISIONS);

        $result = $service->generateInsights([
            'Rookie' => $this->division(['Talent' => 50], 5),
            'Amateur' => $this->division(['Talent' => 60], 0),
        ]);

        $this->assertFalse($result['Rookie-Amateur']['has_data']);
    }

    public function testDiffIsHigherMinusLower(): void
    {
        $service = new InsightService(self::DIVISIONS);

        $result = $service->generateInsights([
            'Rookie' => $this->division(['Concentration' => 40]),
            'Amateur' => $this->division(['Concentration' => 55]),
        ]);

        $entry = $result['Rookie-Amateur']['insights']['Concentration'];
        $this->assertSame(40, $entry['lower']);
        $this->assertSame(55, $entry['higher']);
        $this->assertSame(15, $entry['diff']);
    }

    public function testADiffAboveTheThresholdIsSignificant(): void
    {
        $service = new InsightService(self::DIVISIONS);

        $result = $service->generateInsights([
            'Rookie' => $this->division(['Concentration' => 40]),
            'Amateur' => $this->division(['Concentration' => 46]),
        ]);

        $this->assertTrue($result['Rookie-Amateur']['insights']['Concentration']['is_significant']);
    }

    public function testADiffExactlyAtTheThresholdIsNotSignificant(): void
    {
        $service = new InsightService(self::DIVISIONS);

        $result = $service->generateInsights([
            'Rookie' => $this->division(['Concentration' => 40]),
            'Amateur' => $this->division(['Concentration' => 45]),
        ]);

        $this->assertFalse($result['Rookie-Amateur']['insights']['Concentration']['is_significant']);
    }

    public function testEveryComparisonKeyIsPresentEvenWhenAbsentFromTheStats(): void
    {
        $service = new InsightService(self::DIVISIONS);

        $result = $service->generateInsights([
            'Rookie' => $this->division(['Talent' => 50]),
            'Amateur' => $this->division(['Talent' => 60]),
        ]);

        $insights = $result['Rookie-Amateur']['insights'];
        $this->assertArrayHasKey('Charisma', $insights);
        $this->assertSame(0, $insights['Charisma']['diff']);
    }

    public function testTheHeadlineKeyIsTheLargestTrainableGap(): void
    {
        $service = new InsightService(self::DIVISIONS);

        $result = $service->generateInsights([
            'Rookie' => $this->division(['Concentration' => 40, 'Stamina' => 40]),
            'Amateur' => $this->division(['Concentration' => 50, 'Stamina' => 70]),
        ]);

        $this->assertSame('Stamina', $result['Rookie-Amateur']['max_diff_key']);
    }

    /**
     * Talent and Experience are excluded from the headline: neither can be
     * trained toward, so naming one would hand the manager an unactionable gap.
     */
    public function testTalentAndExperienceAreNeverTheHeadlineGap(): void
    {
        $service = new InsightService(self::DIVISIONS);

        $result = $service->generateInsights([
            'Rookie' => $this->division(['Talent' => 10, 'Experience' => 10, 'Stamina' => 40]),
            'Amateur' => $this->division(['Talent' => 90, 'Experience' => 90, 'Stamina' => 45]),
        ]);

        $this->assertSame('Stamina', $result['Rookie-Amateur']['max_diff_key']);
    }

    public function testThereIsNoHeadlineWhenNoStatIncreases(): void
    {
        $service = new InsightService(self::DIVISIONS);

        $result = $service->generateInsights([
            'Rookie' => $this->division(['Concentration' => 60]),
            'Amateur' => $this->division(['Concentration' => 50]),
        ]);

        $this->assertSame('', $result['Rookie-Amateur']['max_diff_key']);
    }

    public function testCountsAreCarriedThroughForBothSides(): void
    {
        $service = new InsightService(self::DIVISIONS);

        $result = $service->generateInsights([
            'Rookie' => $this->division(['Talent' => 50], 3),
            'Amateur' => $this->division(['Talent' => 60], 7),
        ]);

        $this->assertSame(3, $result['Rookie-Amateur']['count_lower']);
        $this->assertSame(7, $result['Rookie-Amateur']['count_higher']);
        $this->assertSame('Rookie', $result['Rookie-Amateur']['from']);
        $this->assertSame('Amateur', $result['Rookie-Amateur']['to']);
    }
}
