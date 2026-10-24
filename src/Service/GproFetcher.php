<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The raw GPRO transport GproApiClient depends on. A seam so the client's
 * cache and archive behaviour can be tested without HTTP.
 */
interface GproFetcher
{
    public function setToken(string $token): void;

    /** @return array<string, mixed> */
    public function fetchJson(string $endpoint): array;

    /** @return array<string, mixed> */
    public function fetchMarketFile(string $market): array;
}
