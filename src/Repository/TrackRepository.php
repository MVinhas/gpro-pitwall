<?php
namespace App\Repository;

use PDO;

class TrackRepository
{
    public function __construct(private PDO $db) {}

    public function getTrackRisks(string $trackName, array $divisions): array
    {
        $columns = ['overtaking_risk', 'defense_risk'];
        foreach ($divisions as $div) { 
            $columns[] = 'q1_' . strtolower($div); 
        }
        
        $colsStr = implode(', ', $columns);
        $stmt = $this->db->prepare("SELECT {$colsStr} FROM track_risks WHERE track_name = :track_name");
        $stmt->execute([':track_name' => $trackName]);
        
        return $stmt->fetch() ?: [];
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
            $col = 'q1_' . strtolower($div);
            $setClause .= ", {$col} = :{$col}";
            $params[":{$col}"] = $riskVal;
        }

        $sql = "UPDATE track_risks SET {$setClause} WHERE track_name = :track_name";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
}