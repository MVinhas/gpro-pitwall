#!/usr/bin/env php
<?php

/**
 * Enforce a minimum PHPUnit statement-coverage floor from a Clover XML
 * report (CI-only — no local coverage driver is installed). Reads the
 * project-level <metrics> element (the aggregate across every file, not
 * a per-file breakdown) and fails the build if coverage drops below the
 * given percentage.
 *
 * Usage: php bin/check_coverage.php <clover.xml> <minimum-percent>
 */

declare(strict_types=1);

$cloverPath = $argv[1] ?? null;
$minimumArg = $argv[2] ?? null;

if ($cloverPath === null || $minimumArg === null) {
    fwrite(STDERR, "Usage: php bin/check_coverage.php <clover.xml> <minimum-percent>\n");
    exit(1);
}

if (!is_readable($cloverPath)) {
    fwrite(STDERR, "Coverage file not found or unreadable: {$cloverPath}\n");
    exit(1);
}

if (!is_numeric($minimumArg)) {
    fwrite(STDERR, "Minimum percent must be numeric, got: {$minimumArg}\n");
    exit(1);
}

$minimum = (float) $minimumArg;

$xml = file_get_contents($cloverPath);
if ($xml === false) {
    fwrite(STDERR, "Failed to read coverage file: {$cloverPath}\n");
    exit(1);
}

libxml_use_internal_errors(true);
$clover = simplexml_load_string($xml);
if ($clover === false) {
    fwrite(STDERR, "Failed to parse coverage XML: {$cloverPath}\n");
    foreach (libxml_get_errors() as $error) {
        fwrite(STDERR, '  ' . trim($error->message) . "\n");
    }
    exit(1);
}

$metrics = $clover->project->metrics ?? null;
if ($metrics === null) {
    fwrite(STDERR, "No project-level <project><metrics> element found in {$cloverPath}\n");
    exit(1);
}

$total = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];

if ($total === 0) {
    fwrite(STDERR, "Coverage report has zero statements — nothing was measured.\n");
    exit(1);
}

$percentage = ($covered / $total) * 100;

printf("Coverage: %.1f%% of statements (minimum: %s%%)\n", $percentage, $minimumArg);

if ($percentage < $minimum) {
    fwrite(STDERR, sprintf("Coverage %.1f%% is below the required minimum of %s%%.\n", $percentage, $minimumArg));
    exit(1);
}

exit(0);
