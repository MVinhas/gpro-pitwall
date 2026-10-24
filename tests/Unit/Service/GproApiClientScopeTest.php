<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\GproApiClient;
use App\Service\GproApiFetcher;
use App\Tests\Support\ArrayCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GproApiClient::class)]
final class GproApiClientScopeTest extends TestCase
{
    public function testAScopedCopyReadsThatTokensCache(): void
    {
        $cache = new ArrayCache();
        $cache->set('u' . GproApiClient::scopeFor('token-a') . ':manager_menu', ['cash' => 42]);

        $client = new GproApiClient(new GproApiFetcher(['base_url' => 'http://127.0.0.1:9']), $cache);

        $this->assertSame(['cash' => 42], $client->withScopeFor('token-a')->getCachedMenu());
    }

    public function testAScopedCopyLeavesTheOriginalClientUntouched(): void
    {
        // The shared client is scoped by whichever controller runs next. A
        // layout-level read must not re-scope it behind that controller's back.
        $cache = new ArrayCache();
        $cache->set('u' . GproApiClient::scopeFor('token-a') . ':manager_menu', ['cash' => 1]);
        $cache->set('u' . GproApiClient::scopeFor('token-b') . ':manager_menu', ['cash' => 2]);

        $client = new GproApiClient(new GproApiFetcher(['base_url' => 'http://127.0.0.1:9']), $cache);
        $client->setToken('token-b');

        $client->withScopeFor('token-a');

        $this->assertSame(['cash' => 2], $client->getCachedMenu());
    }

    public function testBackfillCallsAreCountedPerUserAndPerRaceWindow(): void
    {
        $cache = new ArrayCache();
        $client = new GproApiClient(
            new GproApiFetcher(['base_url' => 'http://127.0.0.1:9']),
            $cache,
            null,
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-10-23 12:00:00', new \DateTimeZone('Europe/Paris')),
        );
        $client->setToken('token-a');
        $window = $client->archiveWindow();

        $this->assertSame(0, $client->backfillCount());
        $client->addBackfill();
        $client->addBackfill();
        $this->assertSame(2, $client->backfillCount());
        $this->assertSame(
            2,
            $cache->get('u' . GproApiClient::scopeFor('token-a') . ':backfill_count:w' . $window),
        );

        $client->setToken('token-b');
        $this->assertSame(0, $client->backfillCount());
    }
}
