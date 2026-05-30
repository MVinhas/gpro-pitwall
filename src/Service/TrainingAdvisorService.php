<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Ranks pre-qualifying training options by how much shortfall they close
 * against the division's ideal-pilot profile, weighted by each attribute's
 * recruitment factor (Talent ≫ Charisma, etc.).
 *
 *   shortfall_i = max(0, ideal_i − current_i)   for normal attributes
 *   shortfall_i = max(0, current_i − ideal_i)   for weight (heavier = worse)
 *   improvement_i = shortfall_i(before) − shortfall_i(after)
 *   score = Σ |factor_i| × improvement_i
 *
 * Surplus over the ideal is free — exceeding the baseline never penalises.
 * Bringing the weakest high-factor attribute up gives the biggest score.
 *
 * If no ideal is available (no baseline pilots seeded for the division),
 * `rank()` returns an empty list. The caller surfaces an empty state.
 */
final class TrainingAdvisorService
{
    /** Driver attributes the training planner actually moves. */
    private const array TRAINABLE_ATTRS = [
        'concentration',
        'talent',
        'aggressiveness',
        'experience',
        'technical_insight',
        'stamina',
        'charisma',
        'motivation',
        'weight',
    ];

    /** Attributes where being higher than ideal is bad (only weight). */
    private const array INVERTED_ATTRS = ['weight'];

    /**
     * Maps the IdealPilotService stats keys (display labels) to the
     * training-row gain_* / driver-stat snake_case keys.
     */
    private const array IDEAL_LABEL_MAP = [
        'Concentration'     => 'concentration',
        'Talent'            => 'talent',
        'Aggressiveness'    => 'aggressiveness',
        'Experience'        => 'experience',
        'Technical Insight' => 'technical_insight',
        'Stamina'           => 'stamina',
        'Charisma'          => 'charisma',
        'Motivation'        => 'motivation',
        'Weight (Kg)'       => 'weight',
    ];

    /**
     * @param array<string, float> $factors per-attribute recruitment factors
     */
    public function __construct(private readonly array $factors)
    {
    }

    /**
     * @param list<array<string, mixed>> $trainings rows from `trainings` table
     * @param array<string, int|float> $current  driver's current stats (snake_case keys)
     * @param array<string, mixed>|null $ideal   IdealPilotService output, or null
     * @return list<array{name: string, score: float, cost: int, gains: array<string, float>}>
     */
    public function rank(array $trainings, array $current, ?array $ideal): array
    {
        $idealStats = $this->normaliseIdeal($ideal);
        if ($idealStats === []) {
            return [];
        }

        $ranked = [];
        foreach ($trainings as $t) {
            $name = (string) ($t['name'] ?? '');
            if ($name === '') {
                continue;
            }
            [$score, $gains] = $this->scoreOne($t, $current, $idealStats);
            $ranked[] = [
                'name'  => $name,
                'score' => round($score, 2),
                'cost'  => (int) ($t['cost'] ?? 0),
                'gains' => $gains,
            ];
        }

        usort($ranked, fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        return $ranked;
    }

    /**
     * @param array<string, mixed> $training
     * @param array<string, int|float> $current
     * @param array<string, float> $ideal
     * @return array{0: float, 1: array<string, float>}
     */
    private function scoreOne(array $training, array $current, array $ideal): array
    {
        $score = 0.0;
        $gains = [];
        foreach (self::TRAINABLE_ATTRS as $attr) {
            $gain = (float) ($training['gain_' . $attr] ?? 0);
            if ($gain !== 0.0) {
                $gains[$attr] = $gain;
            }
            if (!isset($ideal[$attr])) {
                continue;
            }
            $weight = abs($this->factors[$attr] ?? 0.0);
            if ($weight === 0.0) {
                continue;
            }
            $now     = (float) ($current[$attr] ?? 0);
            $target  = $ideal[$attr];
            $oldGap  = $this->shortfall($attr, $now, $target);
            $newGap  = $this->shortfall($attr, $now + $gain, $target);
            $score  += $weight * ($oldGap - $newGap);
        }
        return [$score, $gains];
    }

    private function shortfall(string $attr, float $value, float $target): float
    {
        return in_array($attr, self::INVERTED_ATTRS, true)
            ? max(0.0, $value - $target)
            : max(0.0, $target - $value);
    }

    /**
     * @param array<string, mixed>|null $ideal
     * @return array<string, float>
     */
    private function normaliseIdeal(?array $ideal): array
    {
        if ($ideal === null || (int) ($ideal['count'] ?? 0) === 0) {
            return [];
        }
        $stats = $ideal['stats'] ?? [];
        if (!is_array($stats) || $stats === []) {
            return [];
        }

        $out = [];
        foreach (self::IDEAL_LABEL_MAP as $label => $key) {
            if (isset($stats[$label])) {
                $out[$key] = (float) $stats[$label];
            }
        }
        return $out;
    }
}
