<?php

// Dev convenience wrapper — prod has no shell, so the same import runs
// automatically inside DatabaseSeeder::migrate() on the first request.
$container = require __DIR__ . '/../bootstrap.php';

/** @var \App\Database\DatabaseSeeder $seeder */
$seeder = $container['db.seeder'];

$count = $seeder->seedTracksFromCsv();

if ($count === 0) {
    die("Error: no tracks seeded — is data/tracks.csv present?\n");
}

echo "✅ Successfully seeded {$count} tracks!\n";
