#!/usr/bin/env php
<?php

/**
 * Native Twig syntax linter (dev-only). Replaces the abandoned
 * sserbin/twig-linter, whose symfony/console ^5.4||^6.1 pin blocked
 * unrelated dev-dependency upgrades. Tokenizes and parses every template —
 * the same check the old package performed — and reports all errors instead
 * of stopping at the first.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Twig\Environment;
use Twig\Error\Error;
use Twig\Loader\FilesystemLoader;
use Twig\Source;
use Twig\TwigFilter;
use Twig\TwigFunction;

$templatesDir = realpath(__DIR__ . '/../templates');
if ($templatesDir === false) {
    fwrite(STDERR, "templates/ directory not found\n");
    exit(1);
}

$twig = new Environment(new FilesystemLoader($templatesDir));

// Syntax check only: tolerate functions/filters registered at runtime
// (e.g. dump() from the dev DebugExtension) the way the old linter's
// stub environment did.
$twig->registerUndefinedFunctionCallback(
    static fn (string $name): TwigFunction => new TwigFunction($name, static fn (): null => null)
);
$twig->registerUndefinedFilterCallback(
    static fn (string $name): TwigFilter => new TwigFilter($name, static fn (mixed $v): mixed => $v)
);

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($templatesDir, FilesystemIterator::SKIP_DOTS)
);

$linted = 0;
$errors = 0;
foreach ($files as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'twig') {
        continue;
    }

    $linted++;
    $relative = substr($file->getPathname(), strlen($templatesDir) + 1);

    try {
        $source = new Source((string) file_get_contents($file->getPathname()), $relative, $file->getPathname());
        $twig->parse($twig->tokenize($source));
    } catch (Error $e) {
        $errors++;
        fwrite(STDERR, sprintf("ERR %s:%d %s\n", $relative, $e->getTemplateLine(), $e->getRawMessage()));
    }
}

printf("%d templates linted, %d error(s)\n", $linted, $errors);
exit($errors === 0 ? 0 : 1);
