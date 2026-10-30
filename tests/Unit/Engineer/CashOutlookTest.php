<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\CashOutlook;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CashOutlook::class)]
final class CashOutlookTest extends TestCase
{
    private const array UNKNOWN = ['known' => false, 'committed' => null, 'income' => null, 'free_cash' => null, 'end_committed' => null];

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function state(array $overrides = []): array
    {
        return $overrides + [
            'source' => 'snapshot',
            'driver_json' => '{"salary":300000}',
            'td_json' => '{"salary":120000}',
            'staff_json' => '{"salary":200000}',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ledger(): array
    {
        return [
            ['race' => 8, 'description' => 'Race 8 finish position (3)', 'amount' => 200000],
            ['race' => 8, 'description' => 'Facility costs', 'amount' => -999999],
            ['race' => 9, 'description' => 'Facility costs', 'amount' => -150000],
            ['race' => 9, 'description' => 'Tyre supplier costs (Pipirelli)', 'amount' => -90000],
            ['race' => 9, 'description' => 'Sponsor money', 'amount' => 900000],
            ['race' => 9, 'description' => 'Race 9 finish position (2)', 'amount' => 400000],
            ['race' => 9, 'description' => 'Salary for your driver', 'amount' => -300000],
        ];
    }

    public function testCommittedIncomeAndFreeCash(): void
    {
        $out = CashOutlook::from($this->state(), $this->ledger(), 2000000, 9);

        $this->assertSame([
            'known' => true,
            'committed' => 860000,
            'income' => 1200000,
            'free_cash' => 5060000,
            'end_committed' => 5060000,
        ], $out);
    }

    public function testNoTdCountsAsZero(): void
    {
        $out = CashOutlook::from($this->state(['td_json' => null]), $this->ledger(), 2000000, 9);

        $this->assertTrue($out['known']);
        $this->assertSame(740000, $out['committed']);
    }

    public function testOnlyTheLatestRaceCountsForCommittedLines(): void
    {
        $out = CashOutlook::from($this->state(), $this->ledger(), 0, 1);

        $this->assertSame(860000, $out['committed']);
    }

    public function testRaceWithoutFacilityOrTyreLinesCommitsOnlySalaries(): void
    {
        $ledger = [['race' => 3, 'description' => 'Sponsor money', 'amount' => 500000]];

        $out = CashOutlook::from($this->state(), $ledger, 100, 2);

        $this->assertSame(620000, $out['committed']);
        $this->assertSame(500000, $out['income']);
        $this->assertSame(100 + (500000 - 620000) * 2, $out['free_cash']);
    }

    public function testNoRacesLeftIsJustTheCash(): void
    {
        $out = CashOutlook::from($this->state(), $this->ledger(), 2000000, 0);

        $this->assertSame(2000000, $out['free_cash']);
    }

    public function testHistorySourcedStateIsUnknown(): void
    {
        $out = CashOutlook::from($this->state(['source' => 'history']), $this->ledger(), 2000000, 9);

        $this->assertSame(self::UNKNOWN, $out);
    }

    public function testMissingWeekendStateIsUnknown(): void
    {
        $this->assertSame(self::UNKNOWN, CashOutlook::from(null, $this->ledger(), 2000000, 9));
    }

    public function testMissingDriverSalaryIsUnknown(): void
    {
        foreach (['driver_json' => null, 'staff_json' => null] as $column => $value) {
            $out = CashOutlook::from($this->state([$column => $value]), $this->ledger(), 2000000, 9);
            $this->assertSame(self::UNKNOWN, $out, $column);
        }

        $out = CashOutlook::from($this->state(['staff_json' => '{"wage":1}']), $this->ledger(), 2000000, 9);
        $this->assertSame(self::UNKNOWN, $out);
    }

    public function testTdPresentWithoutSalaryIsUnknown(): void
    {
        $out = CashOutlook::from($this->state(['td_json' => '{}']), $this->ledger(), 2000000, 9);

        $this->assertSame(self::UNKNOWN, $out);
    }

    public function testEmptyLedgerIsUnknown(): void
    {
        $this->assertSame(self::UNKNOWN, CashOutlook::from($this->state(), [], 2000000, 9));
    }

    public function testMalformedJsonIsUnknown(): void
    {
        foreach (['driver_json', 'td_json', 'staff_json'] as $column) {
            $out = CashOutlook::from($this->state([$column => '{']), $this->ledger(), 2000000, 9);
            $this->assertSame(self::UNKNOWN, $out, $column);
        }

        $out = CashOutlook::from($this->state(['driver_json' => '5']), $this->ledger(), 2000000, 9);
        $this->assertSame(self::UNKNOWN, $out);
    }

    public function testLatestTyreCostIsTheHighestRacesTyreTotal(): void
    {
        $this->assertSame(90000, CashOutlook::latestTyreCost($this->ledger()));
    }

    public function testLatestTyreCostSumsEveryTyreLineOfTheRace(): void
    {
        $ledger = [
            ['race' => 3, 'description' => 'Tyre supplier costs (Pipirelli)', 'amount' => -2_510_000],
            ['race' => 3, 'description' => 'Tyre supplier costs (Pipirelli)', 'amount' => -10_000],
            ['race' => 3, 'description' => 'Sponsor money', 'amount' => 900000],
            ['race' => 2, 'description' => 'Tyre supplier costs (Pipirelli)', 'amount' => -1],
        ];

        $this->assertSame(2_520_000, CashOutlook::latestTyreCost($ledger));
    }

    public function testLatestTyreCostIsZeroWithoutRowsOrTyreLines(): void
    {
        $this->assertSame(0, CashOutlook::latestTyreCost([]));
        $this->assertSame(0, CashOutlook::latestTyreCost([
            ['race' => 1, 'description' => 'Sponsor money', 'amount' => 900000],
        ]));
    }
}
