<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\BillboardService;
use App\Service\GproApiClient;
use App\Service\GproApiFetcher;
use App\Tests\Support\ArrayCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BillboardService::class)]
final class BillboardServiceTest extends TestCase
{
    private const array DIVISIONS = ['Rookie', 'Amateur', 'Pro', 'Master', 'Elite'];

    private ArrayCache $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayCache();
    }

    /**
     * A client whose fetcher points at a closed port: any attempt to reach the
     * network fails loudly, so these tests prove the billboard is cache-only.
     */
    private function service(): BillboardService
    {
        $client = new GproApiClient(
            new GproApiFetcher(['base_url' => 'http://127.0.0.1:9']),
            $this->cache,
        );

        return new BillboardService($client, self::DIVISIONS);
    }

    /** Warm the three per-user keys the way the sync would for $token. */
    private function warm(string $token, array $menu, array $office, array $money = []): void
    {
        $scope = 'u' . GproApiClient::scopeFor($token) . ':';
        $this->cache->set($scope . 'manager_menu', $menu);
        $this->cache->set($scope . 'office_data', $office);
        if ($money !== []) {
            $this->cache->set($scope . 'money_levels', $money);
        }
    }

    public function testBuildsTheStripFromTheWarmCache(): void
    {
        $this->warm(
            'token-a',
            ['cash' => 13_549_646, 'group' => 'Pro - 8', 'IDM' => 7],
            ['trackName' => 'Valencia', 'seasonNb' => '112', 'raceNb' => '9'],
        );

        $strip = $this->service()->forToken('token-a');

        $this->assertNotNull($strip);
        $this->assertSame(13_549_646, $strip['cash']);
        $this->assertSame('Pro - 8', $strip['division']);
        $this->assertSame('Valencia', $strip['next_track']);
        $this->assertSame(112, $strip['season']);
        $this->assertSame(9, $strip['race']);
    }

    public function testReturnsNullBeforeTheFirstSync(): void
    {
        // Nothing cached yet: the strip must stay empty rather than show zeros,
        // and must not reach for the API to fill itself.
        $this->assertNull($this->service()->forToken('never-synced'));
    }

    public function testNeverShowsAnotherManagersFigures(): void
    {
        $this->warm('token-a', ['cash' => 1_000, 'group' => 'Rookie - 1'], ['trackName' => 'Monza']);
        $this->warm('token-b', ['cash' => 9_999, 'group' => 'Elite'], ['trackName' => 'Suzuka']);

        $service = $this->service();

        $this->assertSame(1_000, $service->forToken('token-a')['cash'] ?? null);
        $this->assertSame(9_999, $service->forToken('token-b')['cash'] ?? null);
    }

    public function testRanksCashAgainstTheGroupWhenMoneyLevelsAreCached(): void
    {
        $this->warm(
            'token-a',
            ['cash' => 60_000_000, 'group' => 'Pro - 8', 'IDM' => 7],
            ['trackName' => 'Valencia'],
            ['managers' => [
                ['IDM' => 1, 'cash' => 90_000_000],
                ['IDM' => 2, 'cash' => 50_000_000],
                ['IDM' => 7, 'cash' => 60_000_000],
            ]],
        );

        $strip = $this->service()->forToken('token-a');

        $this->assertSame(2, $strip['cash_rank'] ?? null);
        $this->assertSame(3, $strip['cash_total'] ?? null);
    }

    public function testRejectsAGroupWhosePrefixIsNotADivision(): void
    {
        $this->warm('token-a', ['cash' => 5, 'group' => 'Nonsense - 3'], ['trackName' => 'Monza']);

        $strip = $this->service()->forToken('token-a');

        $this->assertNotNull($strip);
        $this->assertNull($strip['division']);
    }

    public function testAMissingNextRaceLeavesThoseFieldsNull(): void
    {
        $this->warm('token-a', ['cash' => 5, 'group' => 'Pro - 1'], []);

        $strip = $this->service()->forToken('token-a');

        $this->assertNotNull($strip);
        $this->assertNull($strip['next_track']);
        $this->assertNull($strip['season']);
        $this->assertNull($strip['race']);
    }

    public function testRanksCashByValueNotResponseOrder(): void
    {
        $managers = [
            ['IDM' => 1, 'cash' => 90_000_000],
            ['IDM' => 2, 'cash' => 50_000_000],
            ['IDM' => 7, 'cash' => 60_000_000],
        ];

        $this->assertSame(
            ['rank' => 2, 'total' => 3],
            BillboardService::rankCashAgainstGroup(7, 60_000_000, $managers),
        );
    }

    public function testTopCashRanksFirst(): void
    {
        $this->assertSame(
            ['rank' => 1, 'total' => 2],
            BillboardService::rankCashAgainstGroup(7, 90_000_000, [
                ['IDM' => 7, 'cash' => 90_000_000],
                ['IDM' => 1, 'cash' => 50_000_000],
            ]),
        );
    }

    public function testRankIsNullWhenTheManagerIsNotInTheGroup(): void
    {
        $this->assertSame(
            ['rank' => null, 'total' => null],
            BillboardService::rankCashAgainstGroup(99, 10_000_000, [
                ['IDM' => 1, 'cash' => 90_000_000],
            ]),
        );
        $this->assertSame(
            ['rank' => null, 'total' => null],
            BillboardService::rankCashAgainstGroup(1, 10_000_000, []),
        );
    }

    public function testTierFromGroupReadsOnlyARealDivisionPrefix(): void
    {
        $this->assertSame('Pro', BillboardService::tierFromGroup('Pro - 8', self::DIVISIONS));
        $this->assertSame('Elite', BillboardService::tierFromGroup('Elite', self::DIVISIONS));
        $this->assertNull(BillboardService::tierFromGroup('Nonsense - 3', self::DIVISIONS));
        $this->assertNull(BillboardService::tierFromGroup('', self::DIVISIONS));
    }
}
