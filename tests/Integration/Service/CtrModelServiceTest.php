<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Cache\Adapter\FilesystemCache;
use App\Database\DatabaseSeeder;
use App\Engineer\EnergyModel;
use App\Engineer\PlaceGap;
use App\Repository\RaceHistoryRepository;
use App\Repository\RaceTelemetryRepository;
use App\Security\ApiTokenCrypto;
use App\Service\CtrModelService;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CtrModelService::class)]
final class CtrModelServiceTest extends TestCase
{
    private PDO $db;
    private FilesystemCache $cache;
    private CtrModelService $service;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        (new DatabaseSeeder(
            $this->db,
            ['Concentration' => 'concentration'],
            ['Rookie', 'Amateur', 'Pro', 'Master', 'Elite'],
            [],
            new ApiTokenCrypto('ctr-model-test-secret'),
        ))->migrate();

        $this->cacheDir = dirname(__DIR__, 3) . '/var/test/ctr-model-' . bin2hex(random_bytes(6));
        $this->cache = new FilesystemCache($this->cacheDir);
        $this->service = new CtrModelService(
            new RaceTelemetryRepository($this->db),
            new RaceHistoryRepository($this->db),
            $this->cache,
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->cacheDir);
    }

    private function seedCorpus(): void
    {
        $insert = $this->db->prepare(
            "INSERT INTO race_telemetry (
                season, race, level, group_label, track_id, driver_id, final_pos, q1_time_ms, q2_time_ms, q2_pos,
                clear_dry_risk, race_energy_from, race_energy_to, laps_completed, driver_sta
             ) VALUES (
                1, :race, 'Pro', 'Pro - 1', 1, :driver, 1, 1, :q2, :pos, :ctr, 100, :to, 50, :sta
             )"
        );
        for ($i = 0; $i < 40; $i++) {
            $ctr = 2 * ($i % 20);
            $sta = [20, 50, 80][$i % 3];
            $drain = (int) round(50 * (0.1 + 0.03 * $ctr - 0.002 * $sta));
            $insert->execute([
                'race' => 1, 'driver' => $i, 'q2' => 100000 + 100 * $i, 'pos' => $i + 1,
                'ctr' => $ctr, 'to' => 100 - $drain, 'sta' => $sta,
            ]);
        }
    }

    private function clearCorpus(): void
    {
        $this->db->exec('DELETE FROM race_telemetry');
    }

    public function test_empty_corpus_returns_fallback_models(): void
    {
        $this->assertSame(EnergyModel::fallback()->toArray(), $this->service->energy('Pro', 1)->toArray());
        $this->assertSame(PlaceGap::fallback()->toArray(), $this->service->placeGap(1)->toArray());
    }

    public function test_energy_is_fitted_from_the_corpus(): void
    {
        $this->seedCorpus();

        $model = $this->service->energy('Pro', 1);

        $this->assertNotNull($model->toArray()['drain']);
        $this->assertFalse($model->cap(80, true, 50, 50, 'Pro')['estimate']);
    }

    public function test_energy_second_call_is_served_from_the_cache(): void
    {
        $this->seedCorpus();
        $first = $this->service->energy('Pro', 1)->toArray();
        $this->clearCorpus();

        $this->assertSame($first, $this->service->energy('Pro', 1)->toArray());
        $this->assertNotNull($first['drain']);
    }

    public function test_place_gap_second_call_is_served_from_the_cache(): void
    {
        $this->seedCorpus();
        $first = $this->service->placeGap(1)->toArray();
        $this->clearCorpus();

        $this->assertSame($first, $this->service->placeGap(1)->toArray());
        $this->assertArrayHasKey('Pro', $first);
    }

    public function test_a_different_season_is_a_different_key(): void
    {
        $this->seedCorpus();
        $this->service->energy('Pro', 1);
        $this->service->placeGap(1);
        $this->clearCorpus();

        $this->assertNull($this->service->energy('Pro', 2)->toArray()['drain']);
        $this->assertSame([], $this->service->placeGap(2)->toArray());
    }

    public function test_a_different_division_is_a_different_key(): void
    {
        $this->seedCorpus();
        $this->service->energy('Pro', 1);

        $this->assertNull($this->service->energy('Rookie', 1)->toArray()['drain']);
        $this->assertNull($this->service->energy(null, 1)->toArray()['drain']);
    }

    public function test_a_failing_query_returns_the_fallback_and_is_not_cached(): void
    {
        $this->seedCorpus();
        $this->db->exec('ALTER TABLE race_telemetry RENAME TO race_telemetry_gone');

        $this->assertSame(EnergyModel::fallback()->toArray(), $this->service->energy('Pro', 1)->toArray());
        $this->assertSame(PlaceGap::fallback()->toArray(), $this->service->placeGap(1)->toArray());

        $this->db->exec('ALTER TABLE race_telemetry_gone RENAME TO race_telemetry');

        $this->assertNotNull($this->service->energy('Pro', 1)->toArray()['drain']);
        $this->assertNotSame([], $this->service->placeGap(1)->toArray());
    }
}
