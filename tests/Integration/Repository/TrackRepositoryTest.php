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

    public function testFindByNameReturnsTheFullRow(): void
    {
        $this->seedTrack('Monza', 5.793, 0.2, 0.16);
        $this->seedTrack('Spa', 7.004, 0.23, 0.19);

        $row = $this->repo->findByName('Spa');

        $this->assertNotNull($row);
        $this->assertSame('Spa', $row['name']);
        $this->assertSame(7.004, (float) $row['lap_length']);
        $this->assertArrayHasKey('id', $row);
    }

    public function testFindByNameIsNullForAnUnknownNameAndNotAPrefixMatch(): void
    {
        $this->seedTrack('Monza', 5.793, 0.2, 0.16);

        $this->assertNull($this->repo->findByName('Unknown'));
        $this->assertNull($this->repo->findByName('Mon'));
    }

    public function testFindByNameResolvesACountrySuffixedRaceName(): void
    {
        $this->seedTrack('Valencia', 4.005, 0.2, 0.16);

        $row = $this->repo->findByName('Valencia (Spain)');

        $this->assertNotNull($row);
        $this->assertSame('Valencia', $row['name']);
        $this->assertSame('Valencia', $this->repo->findByName('Valencia')['name'] ?? null);
    }
}
