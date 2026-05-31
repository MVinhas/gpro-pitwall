<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\SponsorAdvisorService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SponsorAdvisorService::class)]
final class SponsorAdvisorServiceTest extends TestCase
{
    private SponsorAdvisorService $svc;

    protected function setUp(): void
    {
        $this->svc = new SponsorAdvisorService();
    }

    /**
     * @param array<string, int> $overrides API-side 0..6 values
     * @return array<string, int>
     */
    private function profile(array $overrides = []): array
    {
        return array_merge([
            'finances'    => 3, 'expectations' => 3, 'patience' => 3,
            'reputation'  => 3, 'image'        => 3, 'negotiation' => 3,
        ], $overrides);
    }

    public function testCharacteristicsAreShiftedFromZeroSixToOneSeven(): void
    {
        $out = $this->svc->adviseFor($this->profile(['image' => 0]));
        $this->assertSame(1, $out['characteristics']['image']);

        $out = $this->svc->adviseFor($this->profile(['image' => 6]));
        $this->assertSame(7, $out['characteristics']['image']);
    }

    public function testCarSpotMappingCoversAllImageBands(): void
    {
        // image API 0..6 → in-game 1..7
        $expected = [
            0 => 'Front wing',   // 1
            1 => 'Rear wing',    // 2
            2 => 'Nose',         // 3
            3 => 'Sidepods',     // 4
            4 => 'Sidepods',     // 5
            5 => 'Engine cover', // 6
            6 => 'Engine cover', // 7
        ];
        foreach ($expected as $apiImage => $label) {
            $out = $this->svc->adviseFor($this->profile(['image' => $apiImage]));
            $this->assertSame($label, $out['answers']['car_spot'], "image API={$apiImage}");
        }
    }

    public function testExpectationMappingCoversAllBands(): void
    {
        $expected = [
            0 => 'Relegate with cash',
            1 => 'Relegate with cash',
            2 => 'Low table position',
            3 => 'Low table position',
            4 => 'Mid table position',
            5 => 'Promotion / top 4 / championship win',
            6 => 'Promotion / top 4 / championship win',
        ];
        foreach ($expected as $api => $label) {
            $out = $this->svc->adviseFor($this->profile(['expectations' => $api]));
            $this->assertSame($label, $out['answers']['expectation'], "expectations API={$api}");
        }
    }

    public function testDriverPopularityMappingCoversAllImageBands(): void
    {
        $expected = [
            0 => 'My driver is hated by the fans',                  // 1
            1 => 'My driver is hated by the fans',                  // 2
            2 => 'My driver is not very popular with the fans',     // 3
            3 => 'My driver is not very popular with the fans',     // 4
            4 => 'My driver is liked by the fans',                  // 5
            5 => 'My driver is quite popular with the fans',        // 6
            6 => 'My driver is a favourite of the fans',            // 7
        ];
        foreach ($expected as $api => $label) {
            $out = $this->svc->adviseFor($this->profile(['image' => $api]));
            $this->assertSame($label, $out['answers']['driver_popularity'], "image API={$api}");
        }
    }

    public function testAmountAndDurationMappingsCoverAllPatienceBands(): void
    {
        $expectedAmount = [
            0 => 'OK', 1 => 'OK',
            2 => 'A bit too low', 3 => 'A bit too low',
            4 => 'Far too low', 5 => 'Far too low',
            6 => 'Unacceptable',
        ];
        $expectedDuration = [
            0 => 'OK', 1 => 'OK',
            2 => 'OK', 3 => 'OK',
            4 => 'A bit too low', 5 => 'A bit too low',
            6 => 'Far too low',
        ];
        foreach ($expectedAmount as $api => $label) {
            $out = $this->svc->adviseFor($this->profile(['patience' => $api]));
            $this->assertSame($label, $out['answers']['amount'], "amount: patience API={$api}");
            $this->assertSame($expectedDuration[$api], $out['answers']['duration'], "duration: patience API={$api}");
        }
    }

    public function testMissingFieldsDefaultToScaleOne(): void
    {
        // Empty profile → all characteristics on 0 in API, 1 in-game.
        $out = $this->svc->adviseFor([]);
        $this->assertSame(1, $out['characteristics']['image']);
        $this->assertSame('Front wing', $out['answers']['car_spot']);
    }
}
