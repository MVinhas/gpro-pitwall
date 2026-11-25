<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Archive\ArchiveSanitizer;
use App\Controller\StandingsController;
use App\Database\DatabaseSeeder;
use App\Http\Request;
use App\Repository\ApiArchiveRepository;
use App\Repository\UserRepository;
use App\Security\ApiTokenCrypto;
use App\Security\Authorize;
use App\Service\StandingsService;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

#[CoversClass(StandingsController::class)]
final class StandingsControllerTest extends TestCase
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

    private function render(bool $admin): string
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findById')
            ->willReturn(['id' => 7, 'is_admin' => $admin ? 1 : 0, 'api_token' => 'secret-token']);

        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $crypto = new ApiTokenCrypto('standings-test');
        (new DatabaseSeeder($db, ['Concentration' => 'concentration'], ['Rookie'], [], $crypto))->migrate();

        $controller = new StandingsController(
            new StandingsService(new ApiArchiveRepository($db, new ArchiveSanitizer())),
            new Authorize($users),
            new Environment(new ArrayLoader([
                'standings/index.twig' => '{{ main_sections|join(",") }}'
                    . '|{{ user.api_token is defined ? "leak" : "ok" }}',
            ])),
            ['app' => ['main_sections' => ['Cockpit', 'Standings', 'Race Engineer', 'Debrief', 'Season']]],
        );

        $_SESSION = ['user_id' => 7];
        ob_start();
        $controller->index(new Request([], [], [], []));

        return (string) ob_get_clean();
    }

    /** @return iterable<string, array{bool, string}> */
    public static function navs(): iterable
    {
        yield 'regular user' => [false, 'Cockpit,Standings|ok'];
        yield 'admin' => [true, 'Cockpit,Standings,Race Engineer,Debrief,Season|ok'];
    }

    #[DataProvider('navs')]
    public function testTheNavOffersOnlyTheScreensTheUserCanOpen(bool $admin, string $expected): void
    {
        $this->assertSame($expected, $this->render($admin));
    }
}
