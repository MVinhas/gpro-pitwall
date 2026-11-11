<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\TrackName;
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
     * The full tracks row for a name (see findLapLength: never by GPRO id).
     *
     * @return array<string, mixed>|null
     */
    public function findByName(string $name): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM tracks WHERE name = :name LIMIT 1');
        $stmt->execute([':name' => TrackName::base($name)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        /** @var array<string, mixed>|false $row */
        return $row === false ? null : $row;
    }
}
