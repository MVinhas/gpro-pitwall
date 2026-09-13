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
}
