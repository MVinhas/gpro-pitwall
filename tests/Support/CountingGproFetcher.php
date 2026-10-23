<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\GproFetcher;

final class CountingGproFetcher implements GproFetcher
{
    /** @var list<string> */
    public array $calls = [];
    public string $name = 'Alice';

    public function setToken(string $token): void
    {
    }

    public function fetchJson(string $endpoint): array
    {
        $this->calls[] = $endpoint;

        return match (true) {
            str_ends_with($endpoint, '/office') => [
                'name' => $this->name,
                'driId' => '77',
                'seasonNb' => 99,
                'raceNb' => 4,
                'email' => 'a@example.com',
                'apiRequestsRemaining' => 88,
            ],
            str_contains($endpoint, 'UpdateCar') => ['kind' => 'car', 'apiRequestsRemaining' => 87],
            default => ['endpoint' => $endpoint, 'apiRequestsRemaining' => 86],
        };
    }

    public function fetchMarketFile(string $market): array
    {
        return [$market => []];
    }
}
