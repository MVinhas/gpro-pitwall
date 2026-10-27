<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\SeasonStart;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SeasonStart::class)]
final class SeasonStartTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function line(int $race, int $id, int $amount, int $after, string $at = '2026-01-01 10:00:00'): array
    {
        return [
            'race' => $race,
            'occurred_at' => $at,
            'id' => $id,
            'amount' => $amount,
            'balance_after' => $after,
            'description' => 'x',
            'source' => 'api',
        ];
    }

    public function testBalanceBeforeTheFirstRaceOneLine(): void
    {
        $ledger = [
            $this->line(2, 9, -100_000, 21_000_000),
            $this->line(1, 2, 400_000, 23_100_000, '2026-01-01 11:00:00'),
            $this->line(1, 1, -300_000, 22_700_000, '2026-01-01 10:00:00'),
        ];

        $this->assertSame(23_000_000, SeasonStart::cash($ledger, []));
    }

    public function testTheLineOrderDoesNotMatter(): void
    {
        $ledger = [
            $this->line(1, 5, 400_000, 23_100_000),
            $this->line(1, 4, -300_000, 22_700_000),
        ];

        $this->assertSame(23_000_000, SeasonStart::cash($ledger, []));
        $this->assertSame(23_000_000, SeasonStart::cash(array_reverse($ledger), []));
    }

    public function testRaceSourceRowsWithoutABalanceAreIgnored(): void
    {
        $ledger = [
            [
                'race' => 1, 'occurred_at' => null, 'id' => 1, 'amount' => 1_200_000,
                'balance_after' => null, 'description' => 'Race prize', 'source' => 'race',
            ],
            $this->line(1, 2, -300_000, 22_700_000),
        ];

        $this->assertSame(23_000_000, SeasonStart::cash($ledger, []));
    }

    public function testSameSecondRowsListedNewestFirstGiveTheBalanceBeforeTheFirstLine(): void
    {
        // The EconomyHistory example: one race's lines share a second and arrive newest first.
        $at = 'Jun 11th 2024, 23:06:43';
        $ledger = [
            $this->line(1, 1, -250_000, 27_472_820, $at),
            $this->line(1, 2, 0, 27_722_820, $at),
            $this->line(1, 3, -298_000, 27_722_820, $at),
            $this->line(1, 4, -888_060, 28_020_820, $at),
            $this->line(1, 5, 1_000_000, 28_908_880, $at),
        ];

        $this->assertSame(27_908_880, SeasonStart::cash($ledger, []));
    }

    public function testUnsortableDatesDoNotPickTheStart(): void
    {
        // As strings "Oct 2nd" sorts before "Sep 30th", although it is the later line.
        $ledger = [
            $this->line(1, 2, -100_000, 22_900_000, 'Oct 2nd 2026, 21:00:01'),
            $this->line(1, 1, 500_000, 23_000_000, 'Sep 30th 2026, 21:00:01'),
        ];

        $this->assertSame(22_500_000, SeasonStart::cash($ledger, []));
    }

    public function testAnUnchainedRaceOneFallsBackToTheWeekendState(): void
    {
        $ledger = [
            $this->line(1, 1, -100_000, 22_900_000),
            $this->line(1, 2, -100_000, 15_000_000),
        ];
        $weekends = [['race' => 1, 'cash' => 21_000_000, 'source' => 'api']];

        $this->assertSame(21_000_000, SeasonStart::cash($ledger, $weekends));
    }

    public function testEarliestRowAfterRaceOneIsNotTheSeasonStart(): void
    {
        $ledger = [$this->line(5, 1, -300_000, 22_700_000)];

        $this->assertNull(SeasonStart::cash($ledger, []));
    }

    public function testFallsBackToWeekendStateAtRaceOne(): void
    {
        $ledger = [$this->line(3, 1, -300_000, 22_700_000)];
        $weekends = [
            ['race' => 2, 'cash' => 20_000_000, 'source' => 'api'],
            ['race' => 1, 'cash' => 21_000_000, 'source' => 'api'],
        ];

        $this->assertSame(21_000_000, SeasonStart::cash($ledger, $weekends));
    }

    public function testNullWhenNothingKnown(): void
    {
        $this->assertNull(SeasonStart::cash([], []));
        $this->assertNull(SeasonStart::cash([], [['race' => 2, 'cash' => 20_000_000, 'source' => 'api']]));
        $this->assertNull(SeasonStart::cash([], [['race' => 1, 'cash' => null, 'source' => 'api']]));
    }
}
