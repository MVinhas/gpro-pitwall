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
     * @return iterable<string, array{0: bool, 1: bool, 2: bool, 3: bool, 4: bool}>
     */
    public static function cases(): iterable
    {
        // wait, syncPending, readsMarket, isWarm => defer
        // A page never fetches, so the follow-up would have nothing more to
        // show: a screen that does not read the market renders what is stored.
        yield 'warm screen renders in one go'           => [false, false, false, true, false];
        yield 'cold screen renders what is stored'      => [false, false, false, false, false];
        // The driver market is still downloaded on a miss (no manager budget).
        yield 'cold market shows the skeleton first'    => [false, false, true, false, true];
        yield 'warm market renders in one go'           => [false, false, true, true, false];
        yield 'a pending sync on a warm screen renders now' => [false, true, false, true, false];
        yield 'a pending sync on a cold screen defers'  => [false, true, false, false, true];
        yield 'a pending sync on a cold market screen defers' => [false, true, true, false, true];
        yield 'a pending sync on a warm market screen renders now' => [false, true, true, true, false];
        yield 'the follow-up request always renders'    => [true, true, false, false, false];
        yield 'the follow-up on a cold market renders'  => [true, false, true, false, false];
        yield 'the follow-up on a cold screen renders'  => [true, false, false, false, false];
    }

    #[DataProvider('cases')]
    public function testShouldDefer(bool $wait, bool $syncPending, bool $readsMarket, bool $isWarm, bool $expected): void
    {
        $this->assertSame(
            $expected,
            DeferredRender::shouldDefer($wait, $syncPending, $readsMarket, static fn (): bool => $isWarm),
        );
    }

    public function testTheCacheIsNotProbedWhenTheAnswerIsAlreadyKnown(): void
    {
        $probe = function (): bool {
            $this->fail('isWarm must not be called');
        };

        $this->assertFalse(DeferredRender::shouldDefer(true, false, false, $probe));
        $this->assertFalse(DeferredRender::shouldDefer(true, true, true, $probe));
        $this->assertFalse(DeferredRender::shouldDefer(false, false, false, $probe));
    }

    public function testWarmPendingRendersNow(): void
    {
        $this->assertFalse(DeferredRender::shouldDefer(false, true, false, static fn (): bool => true));
    }

    public function testColdPendingDefers(): void
    {
        $this->assertTrue(DeferredRender::shouldDefer(false, true, false, static fn (): bool => false));
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
