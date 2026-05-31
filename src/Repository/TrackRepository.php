<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

class TrackRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Lap length + boost coefficients for boost-fuel calculations.
     *
     * @return array{lap_length: float, boost_dry: float, boost_wet: float}|null
     */
    public function findBoostProfile(string $trackName): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT lap_length, boost_dry, boost_wet FROM tracks WHERE name = :name LIMIT 1'
        );
        $stmt->execute([':name' => $trackName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return [
            'lap_length' => (float) $row['lap_length'],
            'boost_dry'  => (float) $row['boost_dry'],
            'boost_wet'  => (float) $row['boost_wet'],
        ];
    }
}
