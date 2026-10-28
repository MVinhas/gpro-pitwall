<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\SessionBudget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SessionBudget::class)]
final class SessionBudgetTest extends TestCase
{
    private string $dir;

    /** @var list<string|false> */
    private array $prior = [];

    /** @var array<string, mixed> */
    private array $priorSession = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gpro_budget_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->priorSession = $_SESSION ?? [];
        $this->prior = [
            ini_set('session.save_path', $this->dir),
            ini_set('session.use_cookies', '0'),
            ini_set('session.cache_limiter', ''),
        ];
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_abort();
        }
        ini_set('session.save_path', (string) $this->prior[0]);
        ini_set('session.use_cookies', (string) $this->prior[1]);
        ini_set('session.cache_limiter', (string) $this->prior[2]);
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        $_SESSION = $this->priorSession;
    }

    private function openSession(string $id): void
    {
        session_id($id);
        $this->assertTrue(session_start());
        $_SESSION = ['user_id' => 7, 'api_limit' => 100, 'api_limit_updated_at' => 1];
    }

    public function testReleaseClosesAnActiveSession(): void
    {
        $this->openSession('budgetrelease');

        SessionBudget::release(7);

        $this->assertSame(PHP_SESSION_NONE, session_status());
    }

    public function testPersistSavesAFigureThatChangedWhileTheSessionWasReleased(): void
    {
        $this->openSession('budgetchanged');
        $budget = SessionBudget::release(7);

        $_SESSION['api_limit'] = 64;
        $_SESSION['api_limit_updated_at'] = 5;
        $budget->persist();

        $this->assertSame(PHP_SESSION_NONE, session_status(), 'the session is released again');
        session_id('budgetchanged');
        $this->assertTrue(session_start());
        $this->assertSame(64, $_SESSION['api_limit']);
        $this->assertSame(5, $_SESSION['api_limit_updated_at']);
        $this->assertSame(7, $_SESSION['user_id']);
    }

    public function testPersistLeavesTheSessionAloneWhenTheFigureDidNotChange(): void
    {
        $this->openSession('budgetsame');
        $budget = SessionBudget::release(7);
        $before = file_get_contents($this->dir . '/sess_budgetsame');
        file_put_contents($this->dir . '/sess_budgetsame', 'user_id|i:7;marker|i:1;');

        $budget->persist();

        $this->assertSame('user_id|i:7;marker|i:1;', file_get_contents($this->dir . '/sess_budgetsame'));
        $this->assertNotFalse($before);
        $this->assertSame(PHP_SESSION_NONE, session_status());
    }

    public function testPersistDropsTheFlagsTheWorkSettledEvenWhenTheFigureDidNotChange(): void
    {
        $this->openSession('budgetforget');
        $_SESSION['harvest_owed'] = true;
        $budget = SessionBudget::release(7);
        // Another request moved the figure meanwhile: an unchanged one here must not overwrite it.
        file_put_contents($this->dir . '/sess_budgetforget', 'user_id|i:7;harvest_owed|b:1;api_limit|i:40;api_limit_updated_at|i:9;');

        $budget->persist(['harvest_owed']);

        $this->assertSame(PHP_SESSION_NONE, session_status());
        $this->assertSame('user_id|i:7;api_limit|i:40;api_limit_updated_at|i:9;', file_get_contents($this->dir . '/sess_budgetforget'));
    }

    public function testWithoutAReleasedSessionTheSettledFlagsLeaveMemory(): void
    {
        $_SESSION = ['user_id' => 7, 'harvest_owed' => true];
        $budget = SessionBudget::release(7);

        $budget->persist(['harvest_owed']);

        $this->assertSame(['user_id' => 7], $_SESSION);
        $this->assertSame([], glob($this->dir . '/*'));
    }

    public function testPersistDoesNothingWhenNoSessionWasActive(): void
    {
        $_SESSION = ['user_id' => 7, 'api_limit_updated_at' => 1];
        $budget = SessionBudget::release(7);

        $_SESSION['api_limit_updated_at'] = 9;
        $budget->persist();

        $this->assertSame(PHP_SESSION_NONE, session_status());
        $this->assertSame([], glob($this->dir . '/*'));
    }

    /** @return array<string, array{string}> */
    public static function foreignSessionFiles(): array
    {
        return [
            'another user' => ['user_id|i:8;api_limit|i:100;api_limit_updated_at|i:1;'],
            'logged out' => ['api_limit|i:100;api_limit_updated_at|i:1;'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('foreignSessionFiles')]
    public function testPersistWritesNothingWhenTheSessionChangedHands(string $fileNow): void
    {
        $this->openSession('budgetforeign');
        $budget = SessionBudget::release(7);
        file_put_contents($this->dir . '/sess_budgetforeign', $fileNow);

        $_SESSION['api_limit'] = 64;
        $_SESSION['api_limit_updated_at'] = 5;
        $budget->persist();

        $this->assertSame(PHP_SESSION_NONE, session_status(), 'the reopened session is aborted, not left open');
        $this->assertSame($fileNow, file_get_contents($this->dir . '/sess_budgetforeign'));
    }
}
