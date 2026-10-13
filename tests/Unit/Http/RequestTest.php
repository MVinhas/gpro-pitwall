<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Request::class)]
final class RequestTest extends TestCase
{
    public function testQueryReturnsTheWholeQueryString(): void
    {
        $request = new Request(['track' => 'Monza', 'page' => '2'], ['csrf_token' => 'x'], [], []);

        $this->assertSame(['track' => 'Monza', 'page' => '2'], $request->query());
    }

    public function testQueryIsEmptyWithoutParameters(): void
    {
        $this->assertSame([], (new Request([], [], [], []))->query());
    }
}
