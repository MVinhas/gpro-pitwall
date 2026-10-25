<?php

declare(strict_types=1);

namespace App\Debrief;

use App\Calibration\StoredRace;

/**
 * What changed between two consecutive stored weekend states. Part wear is
 * left out on purpose: it moves every race and would drown the real changes.
 *
 * @phpstan-type Change array{group: string, key: string, label: string, from: int|string|null, to: int|string|null}
 */
final readonly class WeekendDiff
{
    public const array DRIVER = [
        'concentration' => 'Concentration', 'talent' => 'Talent', 'aggressiveness' => 'Aggressiveness',
        'experience' => 'Experience', 'techInsight' => 'Technical insight', 'stamina' => 'Stamina',
        'charisma' => 'Charisma', 'motivation' => 'Motivation',
    ];

    public const array TD = [
        'leadership' => 'Leadership', 'mechanics' => 'Mechanics', 'electronics' => 'Electronics',
        'experience' => 'Experience', 'aerodynamics' => 'Aerodynamics', 'pitCoord' => 'Pit coordination',
        'motivation' => 'Motivation',
    ];

    public const array STAFF = [
        'concentration' => 'Concentration', 'experience' => 'Experience', 'technical' => 'Technical',
        'stressHandling' => 'Stress handling', 'motivation' => 'Motivation', 'efficiency' => 'Efficiency',
    ];

    public const array FACILITIES = [
        'windtunnel' => 'Wind tunnel', 'pitstop' => 'Pit stop', 'rdWorkshop' => 'R&D workshop',
        'rdDesign' => 'R&D design', 'engineering' => 'Engineering', 'lab' => 'Lab', 'commercial' => 'Commercial',
    ];

    public const array CAR = ['power' => 'Power', 'handling' => 'Handling', 'accel' => 'Acceleration'];

    public const array CAR_PARTS = [
        'chassis' => 'Chassis', 'engine' => 'Engine', 'fWing' => 'Front wing', 'rWing' => 'Rear wing',
        'underbody' => 'Underbody', 'sidepods' => 'Sidepods', 'cooling' => 'Cooling', 'gear' => 'Gearbox',
        'brakes' => 'Brakes', 'susp' => 'Suspension', 'electronics' => 'Electronics',
    ];

    /**
     * @param array<array-key, mixed>|null $previous a user_weekend_state row
     * @param array<array-key, mixed> $current a user_weekend_state row
     *
     * @return list<Change>
     */
    public static function changes(?array $previous, array $current): array
    {
        if ($previous === null) {
            return [];
        }

        $changes = [];
        $both = static function (string $column) use ($previous, $current): ?array {
            $from = StoredRace::decode($previous[$column] ?? null);
            $to = StoredRace::decode($current[$column] ?? null);

            return $from === null || $to === null ? null : [$from, $to];
        };

        if (($pair = $both('driver_json')) !== null) {
            self::compareWithId($changes, 'driver', 'Driver', self::DRIVER, $pair);
        }
        if (($pair = $both('td_json')) !== null) {
            self::compareWithId($changes, 'td', 'Technical director', self::TD, $pair);
        } elseif (($previous['source'] ?? null) === 'snapshot' && ($current['source'] ?? null) === 'snapshot') {
            // A snapshot stores no td_json when the manager has no director; history rows carry no TD data at all.
            $from = StoredRace::decode($previous['td_json'] ?? null)['id'] ?? null;
            $to = StoredRace::decode($current['td_json'] ?? null)['id'] ?? null;
            if (is_int($from) xor is_int($to)) {
                $changes[] = [
                    'group' => 'td', 'key' => 'id', 'label' => 'Technical director',
                    'from' => is_int($from) ? $from : null, 'to' => is_int($to) ? $to : null,
                ];
            }
        }
        if (($pair = $both('staff_json')) !== null) {
            self::compareMap($changes, 'staff', self::STAFF, $pair[0], $pair[1]);
        }
        if (($pair = $both('facilities_json')) !== null) {
            self::compareMap($changes, 'facilities', self::FACILITIES, $pair[0], $pair[1]);
        }
        if (($pair = $both('car_json')) !== null) {
            self::compareMap($changes, 'car', self::CAR, $pair[0], $pair[1]);
            $from = self::section($pair[0], 'parts');
            $to = self::section($pair[1], 'parts');
            foreach (self::CAR_PARTS as $key => $label) {
                self::compare(
                    $changes,
                    'car',
                    $key,
                    $label . ' level',
                    self::section($from, $key)['level'] ?? null,
                    self::section($to, $key)['level'] ?? null,
                );
            }
        }

        return $changes;
    }

    /**
     * A hire is reported by id; a manager reads names, so the id rows carry the
     * previous and current person's name, falling back to the id.
     *
     * @param list<Change> $changes
     * @param array<array-key, mixed>|null $previous
     * @param array<array-key, mixed> $current
     *
     * @return list<Change>
     */
    public static function withNames(array $changes, ?array $previous, array $current): array
    {
        foreach ($changes as $i => $change) {
            if ($change['key'] !== 'id' || !in_array($change['group'], ['driver', 'td'], true)) {
                continue;
            }
            $column = $change['group'] . '_json';
            $changes[$i]['from'] = self::nameIn($previous[$column] ?? null) ?? $change['from'];
            $changes[$i]['to'] = self::nameIn($current[$column] ?? null) ?? $change['to'];
        }

        return $changes;
    }

    private static function nameIn(mixed $json): ?string
    {
        $name = StoredRace::decode($json)['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * @param list<Change> $changes
     * @param array<string, string> $labels
     * @param array{array<array-key, mixed>, array<array-key, mixed>} $pair
     */
    private static function compareWithId(
        array &$changes,
        string $group,
        string $idLabel,
        array $labels,
        array $pair,
    ): void {
        $before = count($changes);
        self::compare($changes, $group, 'id', $idLabel, $pair[0]['id'] ?? null, $pair[1]['id'] ?? null);
        if (count($changes) > $before) {
            return;
        }
        self::compareMap(
            $changes,
            $group,
            $labels,
            self::section($pair[0], 'profile'),
            self::section($pair[1], 'profile'),
        );
    }

    /**
     * @param list<Change> $changes
     * @param array<string, string> $labels
     * @param array<array-key, mixed> $from
     * @param array<array-key, mixed> $to
     */
    private static function compareMap(array &$changes, string $group, array $labels, array $from, array $to): void
    {
        foreach ($labels as $key => $label) {
            self::compare($changes, $group, $key, $label, $from[$key] ?? null, $to[$key] ?? null);
        }
    }

    /**
     * @param list<Change> $changes
     */
    private static function compare(
        array &$changes,
        string $group,
        string $key,
        string $label,
        mixed $from,
        mixed $to,
    ): void {
        if (!(is_int($from) || is_string($from)) || !(is_int($to) || is_string($to)) || $from === $to) {
            return;
        }
        $changes[] = ['group' => $group, 'key' => $key, 'label' => $label, 'from' => $from, 'to' => $to];
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private static function section(array $data, string $key): array
    {
        $section = $data[$key] ?? null;

        return is_array($section) ? $section : [];
    }
}
