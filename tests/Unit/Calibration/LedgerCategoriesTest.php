<?php

declare(strict_types=1);

namespace App\Tests\Unit\Calibration;

use App\Calibration\LedgerCategories;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LedgerCategories::class)]
final class LedgerCategoriesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function descriptions(): iterable
    {
        yield 'sponsors' => ['Sponsor money', 'Sponsors'];
        yield 'race finish' => ['Race 8 finish position (11)', 'Prize money'];
        yield 'qualifying position' => ['Qualifying position (11)', 'Prize money'];
        yield 'driver salary' => ['Salary for your driver', 'Salaries'];
        yield 'staff salary' => ['Salary for your staff', 'Salaries'];
        yield 'facilities' => ['Facility costs', 'Facilities'];
        yield 'tyres' => ['Tyre supplier costs (Pipirelli)', 'Tyres'];
        yield 'practice' => ['Practice lap costs x 3', 'Track time'];
        yield 'qualify 1' => ['Qualify 1 lap costs', 'Track time'];
        yield 'qualify 2' => ['Qualify 2 lap costs', 'Track time'];
        yield 'testing' => ['Testing session costs', 'Track time'];
        yield 'case insensitive' => ['SPONSOR MONEY', 'Sponsors'];
        yield 'unknown' => ['Something new', 'Other'];
    }

    #[DataProvider('descriptions')]
    public function testCategorise(string $description, string $expected): void
    {
        $this->assertSame($expected, LedgerCategories::categorise($description));
    }

    public function testOrderListsEveryCategoryWithOtherLast(): void
    {
        $this->assertSame(
            ['Sponsors', 'Prize money', 'Salaries', 'Facilities', 'Tyres', 'Track time', 'Other'],
            LedgerCategories::ORDER,
        );
    }
}
