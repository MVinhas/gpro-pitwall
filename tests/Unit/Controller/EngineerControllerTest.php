<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\EngineerController;
use App\Http\HttpException;
use App\Http\Request;
use App\Repository\UserRepository;
use App\Security\Authorize;
use App\Service\RaceEngineerService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EngineerController::class)]
final class EngineerControllerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $priorSession = [];

    protected function setUp(): void
    {
        $this->priorSession = $_SESSION ?? [];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->priorSession;
    }

    public function testNonAdminGets403AndNothingIsWritten(): void
    {
        $service = $this->createMock(RaceEngineerService::class);
        $service->expects($this->never())->method('pin');

        $users = $this->createStub(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 7, 'is_admin' => 0]);
        $_SESSION = ['user_id' => 7];

        $controller = new EngineerController($service, new Authorize($users));

        try {
            $controller->setObjective(new Request([], ['season' => '107', 'objective' => 'hold'], [], []));
            $this->fail('expected a 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }
}
