<?php
namespace App\Service;

class GproDataMapper
{
    /**
     * Maps API Driver Data to our Database Schema.
     *
     * @param array $apiData The raw JSON array from GPRO API (DriProfile or Market)
     * @return array The normalized array matching 'pilots' table columns
     */
    public function mapDriver(array $apiData): array
    {
        // GPRO API usually returns camelCase keys (e.g. 'technicalInsight')
        // Our DB uses snake_case (e.g. 'technical_insight')
        
        return [
            // Core Stats
            'concentration'     => $apiData['concentration'] ?? 0,
            'talent'            => $apiData['talent'] ?? 0,
            'aggressiveness'    => $apiData['aggressiveness'] ?? 0,
            'experience'        => $apiData['experience'] ?? 0,
            'technical_insight' => $apiData['technicalInsight'] ?? 0,
            'stamina'           => $apiData['stamina'] ?? 0,
            'charisma'          => $apiData['charisma'] ?? 0,
            'motivation'        => $apiData['motivation'] ?? 0,
            'weight'            => $apiData['weight'] ?? 0,
            'age'               => $apiData['age'] ?? 0,
            
            // Extra Metadata (useful for Recruitment/Display)
            'name'              => $this->cleanName($apiData),
            'id'                => $apiData['id'] ?? 0,
            'overall'           => $apiData['overall'] ?? 0,
            'salary'            => $apiData['salary'] ?? 0,
            'fee'               => $apiData['fee'] ?? 0, // Usually only in Market
        ];
    }

    private function cleanName(array $data): string
    {
        // API might provide 'fName' and 'lName', or just 'name'
        if (isset($data['fName'], $data['lName'])) {
            return trim($data['fName'] . ' ' . $data['lName']);
        }
        return $data['name'] ?? 'Unknown';
    }
}