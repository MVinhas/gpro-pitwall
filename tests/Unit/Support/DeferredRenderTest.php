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
        yield 'recruitment reads the cache only'        => [false, false, 'Recruitment Analyzer', false, false];
        yield 'a pending sync defers even when warm'    => [false, true, 'Cockpit', true, true];
        yield 'a pending sync defers any screen'        => [false, true, 'Division Baseline', true, true];
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
        $this->assertTrue(DeferredRender::shouldDefer(false, true, 'Cockpit', $probe));
        $this->assertFalse(DeferredRender::shouldDefer(false, false, 'Division Baseline', $probe));
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
