<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\TrackName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TrackName::class)]
final class TrackNameTest extends TestCase
{
    public function testTheTrailingCountryIsStripped(): void
    {
        $this->assertSame('Brno', TrackName::base('Brno (Czech Republic)'));
        $this->assertSame('Valencia', TrackName::base('  Valencia (Spain) '));
    }

    public function testABareNameIsUnchanged(): void
    {
        $this->assertSame('Monza', TrackName::base('Monza'));
    }

    public function testBracketsInTheMiddleOfANameStay(): void
    {
        $this->assertSame('A1-Ring (old) layout', TrackName::base('A1-Ring (old) layout'));
    }
}
