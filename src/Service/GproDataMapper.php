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

            'name'              => $this->resolveName($apiData),
            'id'                => $apiData['id'] ?? 0,
            'overall'           => $apiData['overall'] ?? 0,
            'salary'            => $apiData['salary'] ?? 0,
            'fee'               => $apiData['fee'] ?? 0,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveName(array $data): string
    {
        if (isset($data['fName'], $data['lName'])) {
            return trim($data['fName'] . ' ' . $data['lName']);
        }

        return $data['name'] ?? 'Unknown';
    }
}
