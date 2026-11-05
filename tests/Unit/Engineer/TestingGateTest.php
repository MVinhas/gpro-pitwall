<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engineer;

use App\Engineer\SeasonObjective;
use App\Engineer\TestingGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TestingGate::class)]
final class TestingGateTest extends TestCase
{
    /**
     * @param array<string, float|int> $startWear
     * @param list<string> $replaced
     */
    private static function decide(
        bool $sessionOpen = true,
        ?string $division = 'Pro',
        string $objective = SeasonObjective::STAY,
        bool $carUpdated = false,
        array $startWear = [],
        array $replaced = [],
    ): TestingGate {
        return TestingGate::decide($sessionOpen, $division, $objective, $carUpdated, $startWear, $replaced);
    }

    public function testClosedWhenNoSession(): void
    {
        $gate = self::decide(sessionOpen: false, startWear: ['Brakes' => 10]);

        self::assertSame(TestingGate::CLOSED, $gate->state);
        self::assertFalse($gate->runs());
    }

    public function testRookieNeverTests(): void
    {
        $gate = self::decide(division: 'Rookie');

        self::assertSame(TestingGate::ROOKIE, $gate->state);
        self::assertFalse($gate->runs());
    }

    public function testNinetyDoesNotBlockNinetyPointOneDoes(): void
    {
        $open = self::decide(startWear: ['Brakes' => 90.0]);
        self::assertSame(TestingGate::OPEN, $open->state);
        self::assertTrue($open->runs());

        $blocked = self::decide(startWear: ['Brakes' => 90.1]);
        self::assertSame(TestingGate::BLOCKED, $blocked->state);
        self::assertSame([['part' => 'Brakes', 'wear' => 91]], $blocked->blocking);
        self::assertFalse($blocked->runs());
    }

    public function testAReplacedPartDoesNotBlock(): void
    {
        $gate = self::decide(startWear: ['Engine' => 95], replaced: ['Engine']);

        self::assertSame(TestingGate::OPEN, $gate->state);
        self::assertSame([], $gate->blocking);
    }

    public function testPromoteBeforeTheUpdateUnlocks(): void
    {
        $gate = self::decide(objective: SeasonObjective::PROMOTE, startWear: ['Brakes' => 92]);

        self::assertSame(TestingGate::UNLOCK, $gate->state);
        self::assertTrue($gate->runs());
        self::assertSame([['part' => 'Brakes', 'wear' => 92]], $gate->blocking);
    }

    public function testPromoteAfterTheUpdateIsBlocked(): void
    {
        $gate = self::decide(
            objective: SeasonObjective::PROMOTE,
            carUpdated: true,
            startWear: ['Brakes' => 92],
        );

        self::assertSame(TestingGate::BLOCKED, $gate->state);
        self::assertFalse($gate->runs());
    }

    public function testSeveralBlockingPartsKeepTheirOrder(): void
    {
        $gate = self::decide(startWear: ['Engine' => 95, 'Brakes' => 91.5, 'Gearbox' => 40, 'Tyres' => 99]);

        self::assertSame(TestingGate::BLOCKED, $gate->state);
        self::assertSame(
            [
                ['part' => 'Engine', 'wear' => 95],
                ['part' => 'Brakes', 'wear' => 92],
                ['part' => 'Tyres', 'wear' => 99],
            ],
            $gate->blocking,
        );
    }

    public function testBlockedTurnsAnUnlockIntoABlock(): void
    {
        $unlock = self::decide(objective: SeasonObjective::PROMOTE, startWear: ['Brakes' => 92]);
        $blocked = $unlock->blocked();

        self::assertSame(TestingGate::BLOCKED, $blocked->state);
        self::assertSame($unlock->blocking, $blocked->blocking);
        self::assertFalse($blocked->runs());
    }
}
