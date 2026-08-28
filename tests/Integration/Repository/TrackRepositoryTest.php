<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Repository\TrackRepository;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TrackRepository::class)]
final class TrackRepositoryTest extends TestCase
{
    private PDO $db;
    private TrackRepository $repo;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("
            CREATE TABLE tracks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT UNIQUE NOT NULL,
                lap_length REAL,
                boost_dry REAL,
                boost_wet REAL
            )
        ");

        $this->repo = new TrackRepository($this->db);
    }

    private function seedTrack(string $name, float $lapLength, float $dry, float $wet): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO tracks (name, lap_length, boost_dry, boost_wet) VALUES (:n, :l, :d, :w)'
        );
        $stmt->execute([':n' => $name, ':l' => $lapLength, ':d' => $dry, ':w' => $wet]);
    }

    public function testFindBoostProfileReturnsTheTypedProfile(): void
    {
        $this->seedTrack('Interlagos', 4.309, 0.21, 0.17);

        $profile = $this->repo->findBoostProfile('Interlagos');

        $this->assertIsArray($profile);
        $this->assertSame(4.309, $profile['lap_length']);
        $this->assertSame(0.21, $profile['boost_dry']);
        $this->assertSame(0.17, $profile['boost_wet']);
    }

    public function testFindBoostProfileReturnsNullForAnUnknownTrack(): void
    {
        $this->seedTrack('Interlagos', 4.309, 0.21, 0.17);

        $this->assertNull($this->repo->findBoostProfile('Not A Real Track'));
    }

    public function testLookupIsByExactNameNotPrefix(): void
    {
        $this->seedTrack('Monza', 5.793, 0.2, 0.16);

        $this->assertNull($this->repo->findBoostProfile('Mon'));
        $this->assertIsArray($this->repo->findBoostProfile('Monza'));
    }

    public function testNullColumnsCoerceToZeroRatherThanFailing(): void
    {
        $this->db->exec("INSERT INTO tracks (name) VALUES ('Sparse')");

        $profile = $this->repo->findBoostProfile('Sparse');

        $this->assertIsArray($profile);
        $this->assertSame(0.0, $profile['lap_length']);
        $this->assertSame(0.0, $profile['boost_dry']);
        $this->assertSame(0.0, $profile['boost_wet']);
    }

    public function testTheRightTrackIsReturnedWhenManyAreStored(): void
    {
        $this->seedTrack('Monza', 5.793, 0.2, 0.16);
        $this->seedTrack('Spa', 7.004, 0.23, 0.19);
        $this->seedTrack('Suzuka', 5.807, 0.22, 0.18);

        $profile = $this->repo->findBoostProfile('Spa');

        $this->assertIsArray($profile);
        $this->assertSame(7.004, $profile['lap_length']);
    }
}
