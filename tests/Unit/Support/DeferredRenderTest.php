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
        yield 'cold cockpit shows the skeleton first'   => [false, false, 'Cockpit', false, true];
        yield 'cold strategy shows the skeleton first'  => [false, false, 'Race Strategy', false, true];
        yield 'cold car wear shows the skeleton first'  => [false, false, 'Car Wear Planner', false, true];
        yield 'cold testing shows the skeleton first'   => [false, false, 'Testing', false, true];
        yield 'cold training shows the skeleton first'  => [false, false, 'Training Planner', false, true];
        yield 'a screen with no GPRO data never waits'  => [false, false, 'Division Baseline', false, false];
        yield 'cold recruitment shows the skeleton first' => [false, false, 'Recruitment Analyzer', false, true];
        yield 'warm recruitment renders in one go'      => [false, false, 'Recruitment Analyzer', true, false];
        yield 'a pending sync on a warm screen renders now' => [false, true, 'Cockpit', true, false];
        yield 'a pending sync on a cold screen defers'  => [false, true, 'Cockpit', false, true];
        yield 'a pending sync defers any cold screen'   => [false, true, 'Division Baseline', false, true];
        yield 'a pending sync on a warm baseline renders now' => [false, true, 'Division Baseline', true, false];
        yield 'the follow-up request always renders'    => [true, true, 'Cockpit', false, false];
        yield 'the follow-up request on a cold screen'  => [true, false, 'Testing', false, false];
    }

    public function testTheEngineerTabDefersWhenCold(): void
    {
        $this->assertTrue(DeferredRender::shouldDefer(false, false, 'Race Engineer', fn () => false));
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
     * @return iterable<string, array{0: bool, 1: bool, 2: bool, 3: bool, 4: bool, 5: bool}>
     */
    public static function idleCases(): iterable
    {
        // wait, syncPending, deferred, offered, isWarm => idle sync
        yield 'warm pending page revalidates when idle' => [false, true, false, false, true, true];
        yield 'nothing owed, nothing to revalidate'     => [false, false, false, false, true, false];
        yield 'a cold page syncs inline instead'        => [false, true, false, false, false, false];
        yield 'a skeleton does no GPRO work'            => [false, true, true, false, true, false];
        yield 'the follow-up request syncs inline'      => [true, true, false, false, true, false];
        yield 'an offer nobody took syncs inline'       => [false, true, false, true, true, false];
    }

    #[DataProvider('idleCases')]
    public function testIdleSync(
        bool $wait,
        bool $syncPending,
        bool $deferred,
        bool $offered,
        bool $isWarm,
        bool $expected,
    ): void {
        $this->assertSame(
            $expected,
            DeferredRender::idleSync($wait, $syncPending, $deferred, $offered, static fn (): bool => $isWarm),
        );
    }

    public function testIdleSyncProbesNothingWhenNoSyncIsOwed(): void
    {
        $probe = function (): bool {
            $this->fail('isWarm must not be called');
        };

        $this->assertFalse(DeferredRender::idleSync(false, false, false, false, $probe));
        $this->assertFalse(DeferredRender::idleSync(true, true, false, false, $probe));
        $this->assertFalse(DeferredRender::idleSync(false, true, true, false, $probe));
        $this->assertFalse(DeferredRender::idleSync(false, true, false, true, $probe));
    }

    /**
     * @return iterable<string, array{0: bool, 1: bool, 2: bool, 3: bool}>
     */
    public static function inlineCases(): iterable
    {
        // syncPending, deferred, idleSync => the page syncs before rendering
        yield 'owed on a cold page'                 => [true, false, false, true];
        yield 'owed but handed to the idle time'    => [true, false, true, false];
        yield 'owed but the skeleton goes first'    => [true, true, false, false];
        yield 'nothing owed'                        => [false, false, false, false];
    }

    #[DataProvider('inlineCases')]
    public function testSyncsInline(bool $syncPending, bool $deferred, bool $idleSync, bool $expected): void
    {
        $this->assertSame($expected, DeferredRender::syncsInline($syncPending, $deferred, $idleSync));
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
