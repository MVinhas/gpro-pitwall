<?php
// gpro-driver-analyzer/config/recruitment.php

return [
    // CSV mappings remain static as they relate to the file format, not game values
    'csv_to_schema_map' => [
        'CON' => 'Concentration',
        'TAL' => 'Talent',
        'EXP' => 'Experience',
        'AGG' => 'Aggressiveness',
        'TEI' => 'Technical Insight',
        'STA' => 'Stamina',
        'CHA' => 'Charisma',
        'MOT' => 'Motivation',
        'WEI' => 'Weight',
        'AGE' => 'Age',
        'FAV' => 'Favourite Tracks',
        'OFF' => 'Offers',
        'RET' => 'Retiring'
    ],
    
    'scoring_keys_csv' => [
        'CON', 'TAL', 'EXP', 'AGG', 'TEI', 'STA', 'CHA', 'MOT', 'WEI', 'AGE', 'OFF', 'RET'
    ],
];