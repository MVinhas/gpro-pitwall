<?php

declare(strict_types=1);

namespace App\Archive;

use App\Repository\ApiArchiveRepository;
use App\Telemetry\PayloadReader;

/**
 * Reads, from the archive only, the snapshot rows stamped with one race and
 * applies the rules for a missing driver or Technical Director: TDs exist from
 * Pro up and are optional, GPRO answers TDProfile/DriProfile with a placeholder
 * for a manager who has none, and a placeholder must never be projected.
 */
final class RaceSnapshotReader
{
    use PayloadReader;

    public function __construct(private readonly ApiArchiveRepository $archive)
    {
    }

    public function read(int $userId, int $season, int $race): RaceSnapshot
    {
        $office = $this->usable($userId, 'office', $season, $race, '')
            ?? $this->usable($userId, 'office', $season, $race, 'sync')
            ?? [];
        $hasOffice = $office !== [];

        $driverRaw = $this->driverPayload($userId, $season, $race, $office);
        $tdRaw = $this->usable($userId, 'TDProfile', $season, $race, '') ?? [];
        $staff = $this->usable($userId, 'StaffAndFacilities', $season, $race, '') ?? [];
        $car = $this->usable($userId, 'UpdateCar', $season, $race, '') ?? [];
        $menu = $this->usable($userId, 'Menu', $season, $race, '') ?? [];
        $standings = $this->usable($userId, 'Standings', $season, $race, '') ?? [];

        $hasDriver = $this->hasDriver($office, $driverRaw);
        $driver = $hasDriver ? $driverRaw : [];
        $td = $this->hasTd($office, $tdRaw) ? $tdRaw : [];

        return new RaceSnapshot(
            $office,
            $driver,
            $td,
            $this->hasFacilities($staff) ? $staff : [],
            $car,
            $menu,
            $standings,
            $hasOffice || $driverRaw !== [] || $tdRaw !== [] || $staff !== [] || $car !== [],
            $hasDriver,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function usable(int $userId, string $endpoint, int $season, int $race, ?string $params): ?array
    {
        $payload = $this->archive->latestStamped($userId, $endpoint, $season, $race, $params);

        return $payload === null || SupporterGate::isUnavailable($endpoint, $payload) ? null : $payload;
    }

    /**
     * @param array<string, mixed> $office
     *
     * @return array<string, mixed>
     */
    private function driverPayload(int $userId, int $season, int $race, array $office): array
    {
        $driId = $this->idOf($office['driId'] ?? null);
        if ($office !== [] && $driId === null) {
            return [];
        }

        return $this->usable($userId, 'DriProfile', $season, $race, $driId === null ? null : 'id=' . $driId) ?? [];
    }

    /**
     * @param array<string, mixed> $office
     * @param array<string, mixed> $profile
     */
    private function hasDriver(array $office, array $profile): bool
    {
        if ($office !== [] && $this->idOf($office['driId'] ?? null) === null) {
            return false;
        }

        return $office !== [] || $this->int($profile['overall'] ?? null) !== null;
    }

    /**
     * @param array<string, mixed> $office
     * @param array<string, mixed> $profile
     */
    private function hasTd(array $office, array $profile): bool
    {
        if ($profile === [] || ($office !== [] && $this->idOf($office['tdId'] ?? null) === null)) {
            return false;
        }
        $name = $this->str($profile['tdName'] ?? null);

        return $this->int($profile['ownTD'] ?? null) !== 0
            && $name !== null
            && strcasecmp($name, 'Unknown technical director') !== 0
            && $this->int($profile['overall'] ?? null) !== null;
    }

    /**
     * @param array<string, mixed> $staff
     */
    private function hasFacilities(array $staff): bool
    {
        $levels = [
            'lvlWindtunnel', 'lvlPitstop', 'lvlRDWorkshop', 'lvlRDDesign', 'lvlEngineering', 'lvlLab', 'lvlCommercial',
        ];
        foreach ($levels as $key) {
            if ($this->int($staff[$key] ?? null) !== null) {
                return true;
            }
        }

        return $this->int($staff['overall'] ?? null) !== null;
    }

    private function idOf(mixed $id): ?string
    {
        $id = trim(is_scalar($id) ? (string) $id : '');

        return $id === '' || $id === '0' ? null : $id;
    }
}
