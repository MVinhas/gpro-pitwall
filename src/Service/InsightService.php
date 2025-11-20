<?php
namespace App\Service;

class InsightService
{
    public function __construct(private array $divisions) {}

    public function generateInsights(array $allIdealPilots): array
    {
        $insightsData = [];
        $comparisonKeys = ['Concentration', 'Talent', 'Aggressiveness', 'Experience', 'Technical Insight', 'Stamina', 'Charisma', 'Motivation'];
        $threshold = 5;

        for ($i = 0; $i < count($this->divisions) - 1; $i++) {
            $lowerDiv = $this->divisions[$i];
            $higherDiv = $this->divisions[$i + 1];
            $transitionKey = "{$lowerDiv}-{$higherDiv}";

            $lowerData = $allIdealPilots[$lowerDiv]['stats'] ?? null;
            $higherData = $allIdealPilots[$higherDiv]['stats'] ?? null;
            $lowerCount = $allIdealPilots[$lowerDiv]['count'] ?? 0;
            $higherCount = $allIdealPilots[$higherDiv]['count'] ?? 0;

            $insights = [];
            $maxDiff = 0;
            $maxDiffKey = '';

            if ($lowerCount > 0 && $higherCount > 0) {
                foreach ($comparisonKeys as $key) {
                    $lowerVal = (int)($lowerData[$key] ?? 0);
                    $higherVal = (int)($higherData[$key] ?? 0);
                    $difference = $higherVal - $lowerVal;

                    $insights[$key] = [
                        'lower' => $lowerVal,
                        'higher' => $higherVal,
                        'diff' => $difference,
                        'is_significant' => $difference > $threshold
                    ];

                    if ($difference > $maxDiff && ($key !== 'Talent' && $key !== 'Experience')) {
                        $maxDiff = $difference;
                        $maxDiffKey = $key;
                    }
                }
            }

            $insightsData[$transitionKey] = [
                'from' => $lowerDiv,
                'to' => $higherDiv,
                'has_data' => ($lowerCount > 0 && $higherCount > 0),
                'insights' => $insights,
                'max_diff_key' => ($maxDiff > 0) ? $maxDiffKey : '',
                'count_lower' => $lowerCount,
                'count_higher' => $higherCount
            ];
        }
        return $insightsData;
    }
}