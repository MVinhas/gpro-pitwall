<?php

declare(strict_types=1);

namespace App\Archive;

/**
 * The payloads archived for one race weekend ("entering race R"). Each is an
 * empty array when nothing usable was archived. `driver` and `td` are empty
 * when the manager had none, never a placeholder.
 */
final readonly class RaceSnapshot
{
    /**
     * @param array<string, mixed> $office
     * @param array<string, mixed> $driver
     * @param array<string, mixed> $td
     * @param array<string, mixed> $staff
     * @param array<string, mixed> $car
     * @param array<string, mixed> $menu
     * @param array<string, mixed> $standings
     * @param bool $present whether any snapshot payload was archived for the race
     * @param bool $hasDriver whether a driver was under contract (the profile may still be missing)
     */
    public function __construct(
        public array $office,
        public array $driver,
        public array $td,
        public array $staff,
        public array $car,
        public array $menu,
        public array $standings,
        public bool $present,
        public bool $hasDriver,
    ) {
    }
}
