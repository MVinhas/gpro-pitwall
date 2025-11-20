<?php
// gpro-driver-analyzer/config/app_config.php

$q1_risk_options = [
    "Keep the car on the track",
    "Push the car a little",
    "Push the car a lot",
    "Push the car to the limit"
];

return [
    'divisions' => ['Rookie', 'Amateur', 'Pro', 'Master', 'Elite'],
    
    'main_sections' => [
        'Recruitment Analyzer',
        'Training Planner',
        'Division Baseline',
        'Division Differences',
        'Track Risks',
        'Car Wear'
    ],
    
    'q1_risk_options' => $q1_risk_options,
    'default_q1_risk' => $q1_risk_options[0], 
    
    'stats_schema' => [
        'Concentration' => 'concentration',
        'Talent' => 'talent',
        'Aggressiveness' => 'aggressiveness',
        'Experience' => 'experience',
        'Technical Insight' => 'technical_insight',
        'Stamina' => 'stamina',
        'Charisma' => 'charisma',
        'Motivation' => 'motivation',
        'Weight (Kg)' => 'weight',
        'Age' => 'age',
    ],
    
    // ID => Name Mapping
    'tracks' => [
        2 => 'Buenos Aires', 54 => 'Rafaela Oval', 19 => 'Adelaide', 34 => 'Melbourne',
        12 => 'A1-Ring', 24 => 'Oesterreichring', 58 => 'Baku City', 29 => 'Sakhir',
        10 => 'Spa', 26 => 'Zolder', 31 => 'Brasilia', 1 => 'Interlagos',
        6 => 'Montreal', 33 => 'Shanghai', 59 => 'Grobnik', 41 => 'Brno',
        60 => 'Jyllands-Ringen', 47 => 'Ahvenisto', 7 => 'Magny Cours', 25 => 'Paul Ricard',
        56 => 'Avus', 17 => 'Hockenheim', 21 => 'Nurburgring', 53 => 'Serres',
        9 => 'Hungaroring', 43 => 'Irungattukottai', 48 => 'New Delhi', 32 => 'Fiorano',
        11 => 'Monza', 28 => 'Mugello', 37 => 'Fuji', 13 => 'Suzuka',
        50 => 'Kaunas', 15 => 'Sepang', 22 => 'Mexico City', 4 => 'Monte Carlo',
        27 => 'Zandvoort', 42 => 'Poznan', 18 => 'Estoril', 49 => 'Portimao',
        63 => 'Losail', 45 => 'Bucharest Ring', 55 => 'Sochi', 3 => 'Imola',
        61 => 'Jeddah', 38 => 'Singapore', 52 => 'Slovakiaring', 20 => 'Kyalami',
        44 => 'Yeongam', 5 => 'Barcelona', 14 => 'Jerez', 39 => 'Valencia',
        30 => 'Anderstorp', 57 => 'Bremgarten', 36 => 'Istanbul', 40 => 'Yas Marina',
        23 => 'Brands Hatch', 8 => 'Silverstone', 51 => 'Austin', 16 => 'Indianapolis',
        46 => 'Indianapolis Oval', 35 => 'Laguna Seca', 64 => 'Las Vegas', 62 => 'Miami'
    ],
];