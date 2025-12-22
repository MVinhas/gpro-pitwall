<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

class TrackRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function getTrackRisks(string $trackName, array $divisions): array
    {
        $columns = ['overtaking_risk', 'defense_risk'];
        foreach ($divisions as $div) {
            $columns[] = 'q1_' . strtolower((string) $div);
        }

        $colsStr = implode(', ', $columns);
        $stmt = $this->db->prepare("SELECT {$colsStr} FROM track_risks WHERE track_name = :track_name");
        $stmt->execute([':track_name' => $trackName]);

        /** @var array<string, mixed>|false $result */
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: [];
    }

    public function updateRisks(string $trackName, int $overtaking, int $defense, array $q1Risks): bool
    {
        $setClause = 'overtaking_risk = :overtaking, defense_risk = :defense';
        $params = [
            ':track_name' => $trackName,
            ':overtaking' => $overtaking,
            ':defense' => $defense
        ];

        foreach ($q1Risks as $div => $riskVal) {
            $col = 'q1_' . strtolower((string) $div);
            $setClause .= ", {$col} = :{$col}";
            $params[":{$col}"] = $riskVal;
        }

        $sql = "UPDATE track_risks SET {$setClause} WHERE track_name = :track_name";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
}
