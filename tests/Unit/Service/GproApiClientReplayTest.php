<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\GproApiClient;
use App\Tests\Support\ArrayCache;
use App\Tests\Support\ScriptedGproFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GproApiClient::class)]
final class GproApiClientReplayTest extends TestCase
{
    private ScriptedGproFetcher $gpro;
    private GproApiClient $client;

    protected function setUp(): void
    {
        $this->gpro = new ScriptedGproFetcher();
        $this->client = new GproApiClient($this->gpro, new ArrayCache());
    }

    public function testAsksForTheNamedRaceInTheGroupItWasRunIn(): void
    {
        $this->client->withFetching(fn () => $this->client->getRaceReplayFor(107, 13, 'Amateur - 4'));

        $this->assertSame(['RaceReplay?season=107&race=13&group=Amateur%20-%204'], $this->gpro->requests);
    }

    public function testLeavesTheGroupOutWhenNoneIsKnown(): void
    {
        $this->client->withFetching(fn () => $this->client->getRaceReplayFor(107, 13));

        $this->assertSame(['RaceReplay?season=107&race=13'], $this->gpro->requests);
    }

    public function testNeverReadsFromCacheSoEveryCallIsARealOne(): void
    {
        $this->client->withFetching(function (): void {
            $this->client->getRaceReplayFor(107, 13, 'Amateur - 4');
            $this->client->getRaceReplayFor(107, 13, 'Amateur - 4');
        });

        $this->assertCount(2, $this->gpro->requests);
    }

    public function testMakesNoCallWhenTheGateIsClosed(): void
    {
        $this->assertSame([], $this->client->getRaceReplayFor(107, 13, 'Amateur - 4'));
        $this->assertSame([], $this->gpro->requests);
    }

    public function testRecordsTheRemainingBudgetTheAnswerCarries(): void
    {
        $this->gpro->remaining = 41;

        $this->client->withFetching(fn () => $this->client->getRaceReplayFor(107, 13));

        $this->assertSame(41, $this->client->lastKnownRemaining());
    }
}
