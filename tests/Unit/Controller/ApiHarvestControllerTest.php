<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\ApiHarvestController;
use App\Http\Request;
use App\Repository\UserRepository;
use App\Security\Authorize;
use App\Service\HarvestService;
use App\Sync\HarvestResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiHarvestController::class)]
final class ApiHarvestControllerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $priorSession = [];

    protected function setUp(): void
    {
        $this->priorSession = $_SESSION ?? [];
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_abort();
        }
        $_SESSION = $this->priorSession;
    }

    private function call(ApiHarvestController $controller): string
    {
        ob_start();
        try {
            @$controller->run(new Request([], [], [], []));
        } finally {
            $body = (string) ob_get_clean();
        }

        return $body;
    }

    private function controller(HarvestService $harvest): ApiHarvestController
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 7, 'api_token' => 'secret-token']);

        return new ApiHarvestController($harvest, new Authorize($users));
    }

    public function testRunAnswersStatusCallsAndProjectedOnly(): void
    {
        $harvest = $this->createMock(HarvestService::class);
        $harvest->expects($this->once())->method('runSlice')
            ->with($this->callback(static fn (array $u): bool => $u['id'] === 7))
            ->willReturn(new HarvestResult('more', 3, 2, 41));
        $_SESSION = ['user_id' => 7];

        $body = $this->call($this->controller($harvest));

        $this->assertSame('{"status":"more","calls":3,"projected":2}', $body);
        $this->assertStringNotContainsString('secret-token', $body);
    }

    public function testTheSessionIsReleasedBeforeTheSliceRuns(): void
    {
        $dir = sys_get_temp_dir() . '/gpro_harvest_sess_' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $prior = [
            ini_set('session.save_path', $dir),
            ini_set('session.use_cookies', '0'),
            ini_set('session.cache_limiter', ''),
        ];
        try {
            session_id('harvestrelease');
            $this->assertTrue(session_start());
            $_SESSION = ['user_id' => 7];
            $statusInside = null;
            $harvest = $this->createStub(HarvestService::class);
            $harvest->method('runSlice')->willReturnCallback(
                static function () use (&$statusInside): HarvestResult {
                    $statusInside = session_status();

                    return new HarvestResult('done');
                },
            );

            $this->call($this->controller($harvest));

            $this->assertSame(PHP_SESSION_NONE, $statusInside, 'slice ran with the session lock released');
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_abort();
            }
            ini_set('session.save_path', (string) $prior[0]);
            ini_set('session.use_cookies', (string) $prior[1]);
            ini_set('session.cache_limiter', (string) $prior[2]);
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    public function testTheBudgetFigureTheSliceProducedIsSavedAfterwards(): void
    {
        $dir = sys_get_temp_dir() . '/gpro_harvest_sess_' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $prior = [
            ini_set('session.save_path', $dir),
            ini_set('session.use_cookies', '0'),
            ini_set('session.cache_limiter', ''),
        ];
        try {
            session_id('harvestbudget');
            $this->assertTrue(session_start());
            $_SESSION = ['user_id' => 7, 'api_limit' => 100, 'api_limit_updated_at' => 1];
            $harvest = $this->createStub(HarvestService::class);
            $harvest->method('runSlice')->willReturnCallback(
                static function (): HarvestResult {
                    $_SESSION['api_limit'] = 61;
                    $_SESSION['api_limit_updated_at'] = 99;

                    return new HarvestResult('more', 3);
                },
            );

            $this->call($this->controller($harvest));

            $this->assertSame(PHP_SESSION_NONE, session_status());
            session_id('harvestbudget');
            $this->assertTrue(session_start());
            $this->assertSame(61, $_SESSION['api_limit']);
            $this->assertSame(99, $_SESSION['api_limit_updated_at']);
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_abort();
            }
            ini_set('session.save_path', (string) $prior[0]);
            ini_set('session.use_cookies', (string) $prior[1]);
            ini_set('session.cache_limiter', (string) $prior[2]);
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    public function testAnAnonymousCallerGets401AndNoSliceRuns(): void
    {
        // Authorize exits on denial, so the request runs in its own process.
        $root = dirname(__DIR__, 3);
        $code = <<<'PHP'
            require $argv[1] . '/vendor/autoload.php';
            $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
            $_SESSION = [];
            $users = new App\Repository\UserRepository(
                new PDO('sqlite::memory:'),
                new App\Security\EmailCrypto('x'),
                new App\Security\ApiTokenCrypto('x'),
            );
            $harvest = new class extends App\Service\HarvestService {
                public function __construct()
                {
                }

                public function runSlice(array $user): App\Sync\HarvestResult
                {
                    echo 'SLICE RAN';

                    return new App\Sync\HarvestResult('done');
                }
            };
            register_shutdown_function(static function (): void {
                echo '|' . http_response_code();
            });
            (new App\Controller\ApiHarvestController($harvest, new App\Security\Authorize($users)))
                ->run(new App\Http\Request([], [], [], []));
            PHP;

        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-r', $code, '--', $root],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $this->assertStringContainsString('"status":"unauthenticated"', $out);
        $this->assertStringNotContainsString('SLICE RAN', $out);
        $this->assertStringEndsWith('|401', $out);
    }
}
