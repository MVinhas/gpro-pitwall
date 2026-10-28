<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\DeferredRender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeferredRender::class)]
final class DeferredRenderTest extends TestCase
{
    /**
     * @return iterable<string, array{0: bool, 1: bool, 2: string, 3: bool, 4: bool}>
     */
    public static function cases(): iterable
    {
        // wait, syncPending, section, isWarm => defer
        yield 'warm cockpit renders in one go'          => [false, false, 'Cockpit', true, false];
        // A page never fetches, so the follow-up would have nothing more to
        // show: a cold screen renders what is stored (or its empty state).
        yield 'cold cockpit renders what is stored'     => [false, false, 'Cockpit', false, false];
        yield 'cold strategy renders what is stored'    => [false, false, 'Race Strategy', false, false];
        yield 'cold car wear renders what is stored'    => [false, false, 'Car Wear Planner', false, false];
        yield 'cold testing renders what is stored'     => [false, false, 'Testing', false, false];
        yield 'cold training renders what is stored'    => [false, false, 'Training Planner', false, false];
        yield 'cold engineer renders what is stored'    => [false, false, 'Race Engineer', false, false];
        yield 'a screen with no GPRO data never waits'  => [false, false, 'Division Baseline', false, false];
        // The driver market is still downloaded on a miss (no manager budget).
        yield 'cold recruitment shows the skeleton first' => [false, false, 'Recruitment Analyzer', false, true];
        yield 'warm recruitment renders in one go'      => [false, false, 'Recruitment Analyzer', true, false];
        yield 'a pending sync on a warm screen renders now' => [false, true, 'Cockpit', true, false];
        yield 'a pending sync on a cold screen defers'  => [false, true, 'Cockpit', false, true];
        yield 'a pending sync defers any cold screen'   => [false, true, 'Division Baseline', false, true];
        yield 'a pending sync on a warm baseline renders now' => [false, true, 'Division Baseline', true, false];
        yield 'the follow-up request always renders'    => [true, true, 'Cockpit', false, false];
        yield 'the follow-up request on a cold screen'  => [true, false, 'Testing', false, false];
    }

    #[DataProvider('cases')]
    public function testShouldDefer(bool $wait, bool $syncPending, string $section, bool $isWarm, bool $expected): void
    {
        $this->assertSame(
            $expected,
            DeferredRender::shouldDefer($wait, $syncPending, $section, static fn (): bool => $isWarm),
        );
    }

    public function testTheCacheIsNotProbedWhenTheAnswerIsAlreadyKnown(): void
    {
        $probe = function (): bool {
            $this->fail('isWarm must not be called');
        };

        $this->assertFalse(DeferredRender::shouldDefer(true, false, 'Cockpit', $probe));
        $this->assertFalse(DeferredRender::shouldDefer(false, false, 'Division Baseline', $probe));
        $this->assertFalse(DeferredRender::shouldDefer(false, false, 'Cockpit', $probe));
    }

    public function testWarmPendingRendersNow(): void
    {
        $this->assertFalse(DeferredRender::shouldDefer(false, true, 'Cockpit', static fn (): bool => true));
    }

    public function testColdPendingDefers(): void
    {
        $this->assertTrue(DeferredRender::shouldDefer(false, true, 'Cockpit', static fn (): bool => false));
    }

    /**
     * @return iterable<string, array{0: bool, 1: bool, 2: bool}>
     */
    public static function inlineCases(): iterable
    {
        // syncPending, deferred => the page syncs before rendering
        yield 'owed on a page that renders'  => [true, false, true];
        yield 'owed but the skeleton goes first' => [true, true, false];
        yield 'nothing owed'                 => [false, false, false];
        yield 'nothing owed, skeleton'       => [false, true, false];
    }

    #[DataProvider('inlineCases')]
    public function testSyncsInline(bool $syncPending, bool $deferred, bool $expected): void
    {
        $this->assertSame($expected, DeferredRender::syncsInline($syncPending, $deferred));
    }

    public function testTheFollowUpUrlKeepsTheQueryAndAddsTheWaitFlag(): void
    {
        $this->assertSame('/strategy?wait=1', DeferredRender::followUpUrl('/strategy', []));
        $this->assertSame(
            '/car-wear?track=Monza&wait=1',
            DeferredRender::followUpUrl('/car-wear', ['track' => 'Monza']),
        );
        // An inbound wait flag is replaced, not duplicated.
        $this->assertSame('/?wait=1', DeferredRender::followUpUrl('/', ['wait' => '1']));
    }
}
