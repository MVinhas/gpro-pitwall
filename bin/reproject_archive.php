<?php

declare(strict_types=1);

/**
 * Rebuilds the typed projections from the raw API archive — local dev tool.
 *
 *     php bin/reproject_archive.php [--user=N]
 *
 * Production never runs this: idle harvest slices re-project lazily, so this
 * script is stripped from the release bundle. Exits 1 when a race stays
 * pending, 2 on bad usage.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    file_put_contents('php://stderr', "reproject_archive.php may only run from the command line.\n");
    exit(1);
}

use App\Archive\Reprojector;

require __DIR__ . '/../vendor/autoload.php';

try {
    $only = Reprojector::parseUser(array_slice($argv, 1));
} catch (InvalidArgumentException) {
    fwrite(STDERR, "Usage: php bin/reproject_archive.php [--user=N]  (N a positive integer)\n");
    exit(2);
}

$container = require __DIR__ . '/../bootstrap.php';

/** @var Reprojector $reprojector */
$reprojector = new Reprojector($container['archive.projector'], $container['repo.api_archive']);

$total = 0;
$stuckUsers = 0;
foreach ($reprojector->userIds($only) as $userId) {
    $result = $reprojector->projectAllPending($userId);
    $total += $result['projected'];
    $line = "user {$userId}: {$result['projected']} races projected";
    if ($result['stuck'] > 0) {
        $line .= ", {$result['stuck']} still pending";
        $stuckUsers++;
    }
    echo $line . "\n";
}

echo "total: {$total} races projected\n";
exit($stuckUsers > 0 ? 1 : 0);
