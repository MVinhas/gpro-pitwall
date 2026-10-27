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

    public function testSameTimestampFallsBackToId(): void
    {
        $ledger = [
            $this->line(1, 5, 400_000, 23_100_000),
            $this->line(1, 4, -300_000, 22_700_000),
        ];

        $this->assertSame(23_000_000, SeasonStart::cash($ledger, []));
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
