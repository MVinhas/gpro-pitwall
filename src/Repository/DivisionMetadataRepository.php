<?php
namespace App\Repository;

use PDO;

class DivisionMetadataRepository
{
    public function __construct(private PDO $db) {}

    public function getMetadata(string $division): array
    {
        $stmt = $this->db->prepare("SELECT last_retrieved_season, last_retrieved_race FROM division_metadata WHERE division = :division");
        $stmt->execute([':division' => $division]);
        $result = $stmt->fetch();

        return [
            'season' => (int)($result['last_retrieved_season'] ?? 0),
            'race' => (int)($result['last_retrieved_race'] ?? 1)
        ];
    }

    public function updateSeason(string $division, int $season): bool
    {
        $sql = "INSERT OR REPLACE INTO division_metadata (division, last_retrieved_season, last_retrieved_race) VALUES (:division, :season, 1)";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':division' => $division, ':season' => $season]);
    }
}