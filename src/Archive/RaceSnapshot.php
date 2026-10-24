<?php

declare(strict_types=1);

namespace App\Archive;

/**
 * The payloads archived for one race weekend ("entering race R"). Each is an
 * empty array when nothing usable was archived. `driver` and `td` are empty
 * when the manager had none, never a placeholder; the *Source fields tell
 * "had none" (None) apart from "nothing archived says" (Absent).
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
        public BlockSource $tdSource,
        public BlockSource $staffSource,
        public BlockSource $driverSource,
    ) {
    }

    /**
     * The TD who raced: the profile, [] when the manager had none, null when
     * the archive does not know.
     *
     * @return array<string, mixed>|null
     */
    public function tdThatRaced(): ?array
    {
        return match ($this->tdSource) {
            BlockSource::Present => $this->td,
            BlockSource::None => [],
            BlockSource::Absent => null,
        };
    }

    /**
     * Staff and facilities for the race, null when none were archived for it.
     *
     * @return array<string, mixed>|null
     */
    public function staffThatRaced(): ?array
    {
        return $this->staffSource === BlockSource::Present ? $this->staff : null;
    }

    /**
     * The profile of the driver who ran the analysed race: [] when the manager
     * had none, null when the archive does not know (no profile, or one that
     * cannot be shown to be that driver's).
     *
     * @param array<string, mixed> $analysis RaceAnalysis payload
     *
     * @return array<string, mixed>|null
     */
    public function driverThatRaced(array $analysis): ?array
    {
        return match ($this->driverSource) {
            BlockSource::Present => self::raceWasRunByCurrentDriver($analysis, $this->office) ? $this->driver : null,
            BlockSource::None => [],
            BlockSource::Absent => null,
        };
    }

    /**
     * @param array<string, mixed> $analysis RaceAnalysis payload
     * @param array<string, mixed> $office   Office payload
     */
    public static function raceWasRunByCurrentDriver(array $analysis, array $office): bool
    {
        $driver = $analysis['driver'] ?? null;
        $raced = is_array($driver) && is_scalar($driver['id'] ?? null) ? (string) $driver['id'] : '';
        $current = trim(is_scalar($office['driId'] ?? null) ? (string) $office['driId'] : '');

        return $current !== '' && $current !== '0' && $raced === $current;
    }
}
