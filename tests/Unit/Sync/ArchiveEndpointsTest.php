<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sync;

use App\Sync\ArchiveEndpoints;
use App\Sync\Endpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArchiveEndpoints::class)]
final class ArchiveEndpointsTest extends TestCase
{
    public function testHarvestEndpointsMapToThePlannerNames(): void
    {
        $this->assertSame(
            [Endpoint::STANDINGS, Endpoint::ECONOMY, Endpoint::SF_HISTORY, Endpoint::CCP_HISTORY],
            ArchiveEndpoints::toPlanner(['Standings', 'EconomyHistory', 'SFHistory', 'CCPHistory']),
        );
    }

    public function testUnmappedNamesAreDroppedAndDuplicatesCollapse(): void
    {
        $this->assertSame(
            [Endpoint::OFFICE],
            ArchiveEndpoints::toPlanner(['office', 'ViewStaff', 'office']),
        );
    }
}
