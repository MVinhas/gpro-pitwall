<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Made-up game constants with every key the race calculators read, so a test
 * can wire whole screens without config/secrets.php (gitignored, absent in CI).
 * The values are neutral, not the game's; no test may assert on their output.
 */
final class FakeSecrets
{
    public const array ALL = [
        'fuel_factors' => [
            'conc' => 0.0, 'agg' => 0.0, 'exp' => 0.0, 'te' => 0.0,
            'eng_lvl' => 0.0, 'ele_lvl' => 0.0, 'constant' => 0.0,
        ],
        'tyre_suppliers_durabilities' => ['Pipirelli' => 1],
        'tyre_calc' => [
            'factors' => [
                'track_wear'      => 1.0,
                'avg_temp'        => 1.0,
                'tyre_durability' => 1.0,
                'suspension'      => 1.0,
                'aggressiveness'  => 1.0,
                'experience'      => 1.0,
                'weight'          => 1.0,
                'tyre_type_base'  => 1.0,
            ],
            'track_wear_values' => ['Medium' => 2.0, 'Low' => 1.0, 'High' => 3.0],
            'tyre_type_values'  => [
                'Extra Soft' => 1.0, 'Soft' => 1.0, 'Medium' => 1.0, 'Hard' => 1.0, 'Rain' => 1.0,
            ],
            'tyre_risk_factors' => [
                'Extra Soft' => 1.0, 'Soft' => 1.0, 'Medium' => 1.0, 'Hard' => 1.0, 'Rain' => 1.0,
            ],
            'base_wear_constant' => 1.0,
            'tyre_compound_difference' => ['Pipirelli' => 0.0],
            'clear_track_risk_gain_lap_fraction' => 0.001,
        ],
        'pit_stop' => [
            'factor_fuel_td'            => 0.0,
            'factor_fuel_no_td'         => 0.0,
            'factor_staff_conc_td'      => 0.0,
            'factor_staff_conc_no_td'   => 0.0,
            'factor_staff_stress_td'    => 0.0,
            'factor_staff_stress_no_td' => 0.0,
            'factor_td_exp'             => 0.0,
            'factor_td_pit'             => 0.0,
            'base_time'                 => 30.0,
        ],
        'setup' => [
            'weather_coeffs' => [
                'Wings'      => ['dry' => 1.0, 'wet' => 1.0, 'wet_offset' => 0.0],
                'Engine'     => ['dry' => 1.0, 'wet' => 1.0, 'wet_offset' => 0.0],
                'Brakes'     => ['dry' => 1.0, 'wet' => 1.0, 'wet_offset' => 0.0],
                'Gear'       => ['dry' => 1.0, 'wet' => 1.0, 'wet_offset' => 0.0],
                'Suspension' => ['dry' => 1.0, 'wet' => 1.0, 'wet_offset' => 0.0],
            ],
            'driver' => [
                'wings_talent'       => 0.0,
                'eng_agg'            => 0.0,
                'eng_exp_pow'        => 0.0,
                'eng_exp_add'        => 0.0,
                'bra_talent'         => 0.0,
                'gear_conc'          => 0.0,
                'susp_exp'           => 0.0,
                'susp_wgt'           => 0.0,
                'susp_tech'          => 0.0,
                'special_track_mult' => 0.39,
            ],
            'car' => [
                'Wings'      => ['lvl' => ['FWing' => 0.0]],
                'Engine'     => ['lvl' => ['Engine' => 0.0]],
                'Brakes'     => ['lvl' => ['Brakes' => 0.0]],
                'Gear'       => ['lvl' => ['Gear' => 0.0]],
                'Suspension' => ['lvl' => ['Susp' => 0.0]],
            ],
            'final' => [
                'a_talent'   => 0.0,
                'b_wing_lvl' => 0.0,
                'c_step5'    => 0.0,
                'd_temp'     => 0.0,
                'wet_const'  => 0.0,
            ],
        ],
        'driver_wear_factors' => [
            'concentration' => 1.0,
            'talent'        => 1.0,
            'experience'    => 1.0,
        ],
        'part_level_factors' => [1 => 1.0, 9 => 1.0],
    ];
}
