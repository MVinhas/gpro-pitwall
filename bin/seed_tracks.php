<?php
// bin/seed_tracks.php

$container = require __DIR__ . '/../bootstrap.php';
/** @var \PDO $db */
$db = $container['db'];

$csvFile = __DIR__ . '/../data/tracks.csv';

if (!file_exists($csvFile)) {
    die("Error: File not found at $csvFile\n");
}

echo "Seeding Tracks (Corrected Delimiters)...\n";

$handle = fopen($csvFile, 'r');

// 1. Skip Row 0 ("Track List...")
fgetcsv($handle, 0, ';'); 
// 2. Skip Row 1 (Headers "Name", "Downforce"...)
fgetcsv($handle, 0, ';'); 

$stmt = $db->prepare("
    INSERT OR REPLACE INTO tracks (
        id, name, lap_length, laps, distance, avg_speed, corners, pit_time,
        base_wings, base_engine, base_brakes, base_gear, base_suspension,
        fuel_consumption, tyre_wear, wing_split, fuel_per_lap, fuel_per_lap_wet, tyre_wear_factor,
        wear_chassis, wear_engine, wear_fwing, wear_rwing, wear_underbody, wear_sidepod, wear_cooling, wear_gearbox, wear_brakes, wear_suspension, wear_electronics
    ) VALUES (
        :id, :name, :lap_length, :laps, :distance, :avg_speed, :corners, :pit_time,
        :base_wings, :base_engine, :base_brakes, :base_gear, :base_suspension,
        :fuel_consumption, :tyre_wear, :wing_split, :fuel_per_lap, :fuel_per_lap_wet, :tyre_wear_factor,
        :wear_chassis, :wear_engine, :wear_fwing, :wear_rwing, :wear_underbody, :wear_sidepod, :wear_cooling, :wear_gearbox, :wear_brakes, :wear_suspension, :wear_electronics
    )
");

$count = 0;
while (($row = fgetcsv($handle, 0, ';')) !== false) {
    // Row Index Mapping based on GPRO V6 Tracks.csv
    // 0: Name, 4: Fuel, 5: TyreWear, 7: Laps, 8: Distance, 12: AvgSpeed, 14: Corners, 15: Pit
    // 18: Wings, 19: Eng, 20: Bra, 21: Gea, 22: Sus
    // 24: WS, 26: L/km(Dry), 27: L/km(Wet), 29: WearFactor
    // 31: Cha, 32: Eng, 33: FW, 34: RW, 35: Und, 36: Sid, 37: Coo, 38: Gea, 39: Bra, 40: Sus, 41: Ele

    if (empty($row[0])) continue;

    // Helper to clean numbers (replace , with .)
    $n = fn($val) => (float)str_replace(',', '.', $val ?? '0');

    $data = [
        ':id' => $count + 1,
        ':name' => trim($row[0]),
        ':lap_length'=> $n($row[6]),
        ':laps' => (int)$n($row[7]),
        ':distance' => $n($row[8]),
        ':avg_speed' => $n($row[12]),
        ':corners' => (int)$n($row[14]),
        ':pit_time' => $n($row[15]),
        
        ':base_wings' => $n($row[18]),
        ':base_engine' => $n($row[19]),
        ':base_brakes' => $n($row[20]),
        ':base_gear' => $n($row[21]),
        ':base_suspension' => $n($row[22]),
        
        ':fuel_consumption' => trim($row[4]),
        ':tyre_wear' => trim($row[5]),
        ':wing_split' => $n($row[24]),
        ':fuel_per_lap' => $n($row[26]), // This was the culprit!
        ':fuel_per_lap_wet' => $n($row[27]),
        ':tyre_wear_factor' => $n($row[29]),
        
        ':wear_chassis' => $n($row[31]),
        ':wear_engine' => $n($row[32]),
        ':wear_fwing' => $n($row[33]),
        ':wear_rwing' => $n($row[34]),
        ':wear_underbody' => $n($row[35]),
        ':wear_sidepod' => $n($row[36]),
        ':wear_cooling' => $n($row[37]),
        ':wear_gearbox' => $n($row[38]),
        ':wear_brakes' => $n($row[39]),
        ':wear_suspension' => $n($row[40]),
        ':wear_electronics' => $n($row[41]),
    ];

    try {
        $stmt->execute($data);
        $count++;
    } catch (Exception $e) {
        echo "Skipping {$row[0]}: " . $e->getMessage() . "\n";
    }
}

echo "✅ Successfully seeded $count tracks with CLEAN data!\n";