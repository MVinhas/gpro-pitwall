<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

class PilotRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function getPilotsByDivision(string $division): array
    {
        $stmt = $this->db->prepare("SELECT * FROM pilots WHERE division = :division");
        $stmt->execute([':division' => $division]);
        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string, mixed> $data */
    public function addPilot(array $data): bool
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));

        $sql = "INSERT INTO pilots ({$columns}) VALUES ({$placeholders})";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($data);
    }

    public function deleteLastPilot(string $division): bool
    {
        $stmt = $this->db->prepare("SELECT id FROM pilots WHERE division = :division ORDER BY id DESC LIMIT 1");
        $stmt->execute([':division' => $division]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            return false;
        }

        $delStmt = $this->db->prepare("DELETE FROM pilots WHERE id = :id");
        return $delStmt->execute([':id' => $result['id']]);
    }

    public function clearDivision(string $division): bool
    {
        $stmt = $this->db->prepare("DELETE FROM pilots WHERE division = :division");
        return $stmt->execute([':division' => $division]);
    }
}
