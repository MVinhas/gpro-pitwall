<?php

declare(strict_types=1);

namespace App\Service;

final readonly class InsightService
{
    private const array COMPARISON_KEYS = [
        'Concentration',
        'Talent',
        'Aggressiveness',
        'Experience',
        'Technical Insight',
        'Stamina',
        'Charisma',
        'Motivation',
    ];

    private const int SIGNIFICANCE_THRESHOLD = 5;

    /**
     * @param list<string> $divisions
     */
    public function __construct(
        private array $divisions
    ) {
    }

    /**
     * @param array<string, array<string, mixed>> $allIdealPilots
     * @return array<string, mixed>
     */
    public function generateInsights(array $allIdealPilots): array
    {
        $result = [];

        $count = count($this->divisions);
        for ($i = 0; $i < $count - 1; $i++) {
            $from = $this->divisions[$i];
            $to = $this->divisions[$i + 1];

            $lower = $allIdealPilots[$from] ?? [];
            $higher = $allIdealPilots[$to] ?? [];

            $lowerStats = $lower['stats'] ?? [];
            $higherStats = $higher['stats'] ?? [];

            $lowerCount = (int) ($lower['count'] ?? 0);
            $higherCount = (int) ($higher['count'] ?? 0);

            $insights = [];
            $maxDiff = 0;
            $maxKey = '';

            if ($lowerCount > 0 && $higherCount > 0) {
                foreach (self::COMPARISON_KEYS as $key) {
                    $diff = (int) ($higherStats[$key] ?? 0) - (int) ($lowerStats[$key] ?? 0);

                    $insights[$key] = [
                        'lower' => (int) ($lowerStats[$key] ?? 0),
                        'higher' => (int) ($higherStats[$key] ?? 0),
                        'diff' => $diff,
                        'is_significant' => $diff > self::SIGNIFICANCE_THRESHOLD,
                    ];

                    if ($diff > $maxDiff && !in_array($key, ['Talent', 'Experience'], true)) {
                        $maxDiff = $diff;
                        $maxKey = $key;
                    }
                }
            }

            $result["{$from}-{$to}"] = [
                'from' => $from,
                'to' => $to,
                'has_data' => $lowerCount > 0 && $higherCount > 0,
                'insights' => $insights,
                'max_diff_key' => $maxDiff > 0 ? $maxKey : '',
                'count_lower' => $lowerCount,
                'count_higher' => $higherCount,
            ];
        }

        return $result;
    }
}
