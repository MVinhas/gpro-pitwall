<?php

declare(strict_types=1);

namespace App\Sync;

/**
 * The single translation between the endpoint names the archive stores (the
 * GPRO path segment) and the planner's Endpoint constants.
 */
final class ArchiveEndpoints
{
    public const array TO_PLANNER = [
        'office' => Endpoint::OFFICE,
        'DriProfile' => Endpoint::DRIVER,
        'UpdateCar' => Endpoint::CAR,
        'TrackProfile' => Endpoint::TRACK,
        'RaceSetup' => Endpoint::SETUP,
        'Testing' => Endpoint::TESTING,
        'StaffAndFacilities' => Endpoint::STAFF,
        'TDProfile' => Endpoint::TD,
        'NegOverview' => Endpoint::SPONSORS,
        'MoneyLevels' => Endpoint::MONEY,
        'Calendar' => Endpoint::CALENDAR,
        'TyreSuppliers' => Endpoint::TYRES,
        'Menu' => Endpoint::MENU,
        'RaceAnalysis' => Endpoint::ANALYSIS,
        'Standings' => Endpoint::STANDINGS,
        'EconomyHistory' => Endpoint::ECONOMY,
        'ViewStaff' => Endpoint::GROUP_STAFF,
        'SFHistory' => Endpoint::SF_HISTORY,
        'CCPHistory' => Endpoint::CCP_HISTORY,
    ];

    /**
     * @param list<string> $archiveNames
     *
     * @return list<string> planner constants, unmapped names dropped
     */
    public static function toPlanner(array $archiveNames): array
    {
        $out = [];
        foreach ($archiveNames as $name) {
            if (isset(self::TO_PLANNER[$name])) {
                $out[] = self::TO_PLANNER[$name];
            }
        }

        return array_values(array_unique($out));
    }
}
