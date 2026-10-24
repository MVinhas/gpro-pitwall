<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\GproFetcher;
use RuntimeException;

/**
 * A GproFetcher with a scripted office/analysis and per-endpoint failures,
 * recording the endpoint name of every call (e.g. "office", "DriProfile").
 */
final class ScriptedGproFetcher implements GproFetcher
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<string> every request path with its query, e.g. "RaceAnalysis?SR=107,5" */
    public array $requests = [];

    /** @var array<string, array<string, mixed>> payloads by request path with query, ahead of the defaults */
    public array $responses = [];

    /** @var list<string> endpoint names that throw */
    public array $failing = [];

    public int $remaining = 90;

    /** @var array<string, mixed> */
    public array $office = [
        'name' => 'Alice',
        'driId' => '77',
        'tdId' => '55',
        'seasonNb' => 107,
        'raceNb' => 14,
        'endOfSeason' => false,
        'sponsorsAlert' => '0',
        'doneRaceSetup' => false,
        'doneQ2' => false,
        'doneTesting' => false,
        'doneCarRepair' => false,
        'doneDriTrain' => false,
        'doneStaffTrain' => false,
        'doneFacilitiesUpdate' => false,
    ];

    /** @var array<string, mixed> */
    public array $driverProfile = ['age' => 27];

    /** @var array<string, mixed> */
    public array $td = ['tdName' => 'Ada Byron', 'overall' => 50];

    /** @var array<string, mixed> */
    public array $analysis = [
        'selSeasonNb' => '107',
        'selRaceNb' => '13',
        'group' => 'Amateur - 4',
        'trackName' => 'Hockenheim (Germany)',
        'driver' => ['name' => 'Test Driver', 'id' => 77, 'OA' => '140'],
        'laps' => [
            ['idx' => 0, 'pos' => 8, 'tyres' => 'Hard', 'weather' => 'Sunny', 'temp' => 44, 'hum' => 35],
            ['idx' => 1, 'pos' => 5, 'tyres' => 'Hard', 'weather' => 'Sunny', 'temp' => 44, 'hum' => 35, 'lapTime' => '1:14.100'],
            ['idx' => 2, 'pos' => 5, 'tyres' => 'Hard', 'weather' => 'Sunny', 'temp' => 44, 'hum' => 35, 'lapTime' => '1:14.200'],
            ['idx' => 3, 'pos' => 5, 'tyres' => 'Hard', 'weather' => 'Sunny', 'temp' => 44, 'hum' => 35, 'lapTime' => '1:14.300'],
            ['idx' => 4, 'pos' => 5, 'tyres' => 'Hard', 'weather' => 'Sunny', 'temp' => 44, 'hum' => 35, 'lapTime' => '1:14.400'],
            ['idx' => 5, 'pos' => 5, 'tyres' => 'Hard', 'weather' => 'Sunny', 'temp' => 44, 'hum' => 35, 'lapTime' => '1:14.500'],
        ],
    ];

    public function setToken(string $token): void
    {
    }

    public function fetchJson(string $endpoint): array
    {
        $name = self::nameOf($endpoint);
        $this->calls[] = $name;
        $this->requests[] = self::pathOf($endpoint);
        if (in_array($name, $this->failing, true)) {
            throw new RuntimeException('scripted failure');
        }

        $payload = $this->responses[self::pathOf($endpoint)] ?? match ($name) {
            'office' => $this->office,
            'RaceAnalysis' => $this->analysis,
            'DriProfile' => $this->driverProfile,
            'TDProfile' => $this->td,
            default => ['endpoint' => $name],
        };
        $payload['apiRequestsRemaining'] = $this->remaining;

        return $payload;
    }

    public function fetchMarketFile(string $market): array
    {
        return [$market => []];
    }

    private static function nameOf(string $endpoint): string
    {
        return explode('?', self::pathOf($endpoint), 2)[0];
    }

    private static function pathOf(string $endpoint): string
    {
        $versioned = strpos($endpoint, '/v2/');

        return $versioned === false ? ltrim($endpoint, '/') : substr($endpoint, $versioned + 4);
    }
}
