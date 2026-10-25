<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\SeasonController;
use App\Http\HttpException;
use App\Http\Request;
use App\Repository\UserRepository;
use App\Security\Authorize;
use App\Service\SeasonService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

#[CoversClass(SeasonController::class)]
final class SeasonControllerTest extends TestCase
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

    private function controller(SeasonService $service, bool $admin): SeasonController
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 7, 'is_admin' => $admin ? 1 : 0, 'api_token' => 'secret-token']);

        return new SeasonController(
            $service,
            new Authorize($users),
            new Environment(new ArrayLoader(['season/index.twig' => '{{ season }}|{{ active_main_tab }}|{{ user.api_token is defined ? "leak" : "ok" }}'])),
            ['app' => ['main_sections' => ['Cockpit', 'Season', 'Login']]],
        );
    }

    public function testNonAdminGets403AndNothingIsBuilt(): void
    {
        $service = $this->createMock(SeasonService::class);
        $service->expects($this->never())->method('build');
        $_SESSION = ['user_id' => 7];

        try {
            $this->controller($service, false)->index(new Request([], [], [], []));
            $this->fail('expected a 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function testAnAdminSeesTheRequestedSeasonAndNeverTheApiToken(): void
    {
        $service = $this->createMock(SeasonService::class);
        $service->expects($this->once())->method('build')->with(7, 106)->willReturn(['season' => 106]);
        $_SESSION = ['user_id' => 7];

        ob_start();
        $this->controller($service, true)->index(new Request(['season' => '106'], [], [], []));

        $this->assertSame('106|Season|ok', ob_get_clean());
    }

    public function testASeasonThatIsNotDigitsIsIgnored(): void
    {
        $service = $this->createMock(SeasonService::class);
        $service->expects($this->once())->method('build')->with(7, null)->willReturn(['season' => 107]);
        $_SESSION = ['user_id' => 7];

        ob_start();
        $this->controller($service, true)->index(new Request(['season' => '106; DROP'], [], [], []));
        ob_end_clean();
    }
}
