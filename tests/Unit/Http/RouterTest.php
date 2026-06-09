<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Router::class)]
#[CoversClass(HttpException::class)]
final class RouterTest extends TestCase
{
    public function testUnknownRouteThrows404(): void
    {
        $router = new Router();
        $request = new Request([], [], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/does-not-exist',
        ]);

        try {
            $router->dispatch($request, []);
            $this->fail('Expected HttpException for an unknown route');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function testKnownRouteDispatchesToController(): void
    {
        $controller = new class {
            public bool $called = false;
            public function handle(Request $request): string
            {
                $this->called = true;
                return 'ok';
            }
        };

        $router = new Router();
        $router->add('GET', '/ping', 'ctrl', 'handle');
        $request = new Request([], [], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/ping',
        ]);

        $result = $router->dispatch($request, ['ctrl' => $controller]);

        $this->assertTrue($controller->called);
        $this->assertSame('ok', $result);
    }
}
