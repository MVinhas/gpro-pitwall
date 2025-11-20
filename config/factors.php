<?php
// gpro-driver-analyzer/config/factors.php

return [
    // Pilot Attribute Factors
    // We cast to (float) to ensure math works correctly
    'pilot_factors' => [
        'concentration'     => (float)($_ENV['FACTOR_CONCENTRATION'] ?? 0.1665),
        'talent'            => (float)($_ENV['FACTOR_TALENT'] ?? 0.2490),
        'aggressiveness'    => (float)($_ENV['FACTOR_AGGRESSIVENESS'] ?? 0.1455),
        'experience'        => (float)($_ENV['FACTOR_EXPERIENCE'] ?? 0.0847),
        'technical_insight' => (float)($_ENV['FACTOR_TECH_INSIGHT'] ?? 0.1245),
        'stamina'           => (float)($_ENV['FACTOR_STAMINA'] ?? 0.1440),
        'charisma'          => (float)($_ENV['FACTOR_CHARISMA'] ?? 0.0829),
        'motivation'        => (float)($_ENV['FACTOR_MOTIVATION'] ?? 0.0835),
        'weight'            => (float)($_ENV['FACTOR_WEIGHT'] ?? -0.0827),
        'age'               => (float)($_ENV['FACTOR_AGE'] ?? 0.0),
    ],

    //These are based on the training costs. Some attributes are not trainable, so instead there is some "guessing"
    'pilot_recruitment_factors' => [
        'concentration' => 0.166,
        'talent' => 0.233, //guessing
        'aggressiveness' => 0.0684,
        'experience' => 0.10, //guessing
        'technical_insight' => 0.0686,
        'stamina' => 0.20,
        'charisma' => 0.0476,
        'motivation' => 0.0136,
        'weight' => (float)($_ENV['FACTOR_WEIGHT'] ?? -0.0827) //We'll use same as pilot_factors, despite the fact it's trainable, the dimension is not the same
    ],

    // Division Overall Ability Caps
    'division_caps' => [
        'Rookie'  => (int)($_ENV['CAP_ROOKIE'] ?? 85),
        'Amateur' => (int)($_ENV['CAP_AMATEUR'] ?? 110),
        'Pro'     => (int)($_ENV['CAP_PRO'] ?? 135),
        'Master'  => (int)($_ENV['CAP_MASTER'] ?? 160),
        'Elite'   => (int)($_ENV['CAP_ELITE'] ?? 999),
    ],
];