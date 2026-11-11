<?php

declare(strict_types=1);

namespace App\Service;

final class GproDataMapper
{
    /**
     * @param array<string, mixed> $apiData
     * @return array<string, mixed>
     */
    public function mapDriver(array $apiData): array
    {
        return [
            'concentration'     => $apiData['concentration'] ?? 0,
            'talent'            => $apiData['talent'] ?? 0,
            'aggressiveness'    => $apiData['aggressiveness'] ?? 0,
            'experience'        => $apiData['experience'] ?? 0,
            'technical_insight' => $apiData['techInsight']
                ?? $apiData['technicalInsight']
                ?? 0,
            'stamina'           => $apiData['stamina'] ?? 0,
            'charisma'          => $apiData['charisma'] ?? 0,
            'motivation'        => $apiData['motivation'] ?? 0,
            'weight'            => $apiData['weight'] ?? 0,
            'age'               => $apiData['age'] ?? 0,

            'name'              => $apiData['driName'] ?? 'Unknown',
            'overall'           => $apiData['overall'] ?? 0,
            'salary'            => $apiData['salary'] ?? 0,
        ];
    }

    /**
     * Is this one of the driver's three favourite tracks?
     * DriProfile exposes favTrack1/2/3 as {name, id} objects.
     *
     * @param array<string, mixed> $pilot
     */
    public function isFavouriteTrack(array $pilot, int $trackId): bool
    {
        if ($trackId <= 0) {
            return false;
        }

        foreach (['favTrack1', 'favTrack2', 'favTrack3'] as $key) {
            $fav = $pilot[$key] ?? null;
            if (is_array($fav) && (int) ($fav['id'] ?? 0) === $trackId) {
                return true;
            }
        }

        return false;
    }
}
