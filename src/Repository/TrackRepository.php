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
     * Lap length in km for one track, by name.
     *
     * Resolved by name and never by a GPRO track id: `tracks.id` is a local
     * autoincrement assigned while seeding the CSV and does not correspond to
     * GPRO's own ids, so an id lookup quietly returns a different circuit.
     */
    public function findLapLength(string $trackName): ?float
    {
        $stmt = $this->db->prepare(
            'SELECT lap_length FROM tracks WHERE name = :name LIMIT 1'
        );
        $stmt->execute([':name' => $trackName]);
        $value = $stmt->fetchColumn();

        return is_numeric($value) ? (float) $value : null;
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
