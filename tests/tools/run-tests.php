<?php
/**
 * Version-aware test runner for PG4WP.
 *
 * Vendors multiple PHPUnit phars and loads the correct one
 * for the current PHP version. This avoids being pinned to
 * a single PHPUnit version that may not support all PHP
 * versions in our test matrix (PHP 8.1–8.4).
 *
 * Usage: php tests/tools/run-tests.php [phpunit-args...]
 *   All arguments are forwarded to the phpunit binary.
 *
 * Example: php tests/tools/run-tests.php tests/ --verbose
 */

$toolsDir = __DIR__;
$phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

$phpunitMap = [
    '8.0' => 'phpunit-9.phar',
    '8.1' => 'phpunit-10.phar',
    '8.2' => 'phpunit-10.phar',
    '8.3' => 'phpunit-11.phar',
    '8.4' => 'phpunit-11.phar',
];

$pharFile = $phpunitMap[$phpVersion] ?? null;

if ($pharFile === null) {
    fwrite(STDERR, "Unsupported PHP version: $phpVersion" . PHP_EOL);
    exit(1);
}

$pharPath = $toolsDir . '/' . $pharFile;

if (!file_exists($pharPath)) {
    fwrite(STDERR, "PHPUnit phar not found: $pharPath" . PHP_EOL);
    fwrite(STDERR, "Run 'make vendor-tools' or download manually from https://phar.phpunit.de/" . PHP_EOL);
    exit(1);
}

// Forward all CLI arguments to phpunit
$args = array_slice($argv, 1);
$cmd = PHP_BINARY . ' ' . escapeshellarg($pharPath);
foreach ($args as $arg) {
    $cmd .= ' ' . escapeshellarg($arg);
}

fwrite(STDERR, "[run-tests] PHP $phpVersion → $pharFile" . PHP_EOL);

passthru($cmd, $exitCode);
exit($exitCode);
