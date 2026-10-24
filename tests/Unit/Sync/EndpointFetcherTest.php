<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sync;

use App\Service\GproApiClient;
use App\Sync\Endpoint;
use App\Sync\EndpointFetcher;
use App\Tests\Support\ArrayCache;
use App\Tests\Support\ScriptedGproFetcher;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(EndpointFetcher::class)]
final class EndpointFetcherTest extends TestCase
{
    private ScriptedGproFetcher $gpro;
    private EndpointFetcher $fetcher;

    protected function setUp(): void
    {
        $this->gpro = new ScriptedGproFetcher();
        $this->fetcher = new EndpointFetcher(new GproApiClient($this->gpro, new ArrayCache()));
    }

    /** @return array<string, array{string, string, string}> */
    public static function requests(): array
    {
        return [
            'money' => [Endpoint::MONEY, '', 'MoneyLevels'],
            'tyres' => [Endpoint::TYRES, '', 'TyreSuppliers'],
            'latest analysis' => [Endpoint::ANALYSIS, '', 'RaceAnalysis'],
            'named analysis' => [Endpoint::ANALYSIS, 'SR=107,5', 'RaceAnalysis?SR=107,5'],
            'standings' => [Endpoint::STANDINGS, '', 'Standings'],
            'economy' => [Endpoint::ECONOMY, '', 'EconomyHistory?Page=1&show=table'],
            'staff and facilities history' => [Endpoint::SF_HISTORY, 'season=106', 'SFHistory?season=106'],
            'car character points history' => [Endpoint::CCP_HISTORY, 'season=106', 'CCPHistory?season=106'],
        ];
    }

    #[DataProvider('requests')]
    public function testFetchesTheMatchingEndpointFresh(string $endpoint, string $params, string $expected): void
    {
        $this->fetcher->fetch($endpoint, $params);
        $this->fetcher->fetch($endpoint, $params);

        $this->assertSame([$expected, $expected], $this->gpro->requests);
    }

    /** @return array<string, array{string, string}> */
    public static function unparsable(): array
    {
        return [
            'analysis with junk' => [Endpoint::ANALYSIS, 'SR=abc'],
            'analysis with a bare season' => [Endpoint::ANALYSIS, 'season=1'],
            'sf history without a season' => [Endpoint::SF_HISTORY, ''],
            'ccp history with junk' => [Endpoint::CCP_HISTORY, 'season=x'],
            'office is not fetchable here' => [Endpoint::OFFICE, ''],
        ];
    }

    #[DataProvider('unparsable')]
    public function testUnparsableParamsAreALogicError(string $endpoint, string $params): void
    {
        $this->expectException(LogicException::class);

        try {
            $this->fetcher->fetch($endpoint, $params);
        } finally {
            $this->assertSame([], $this->gpro->requests);
        }
    }
}
