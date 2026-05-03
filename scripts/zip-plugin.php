#!/usr/bin/env php
<?php

/**
 * zip-plugin.php — PocketMine Plugin Packager
 *
 * Zips a plugin directory into a .phar-ready .zip archive.
 * Automatically excludes .git, .DS_Store, and other dev-only files.
 *
 * Usage:
 *   php scripts/zip-plugin.php <path/to/plugin>
 *
 * Example:
 *   php scripts/zip-plugin.php generated/EconomyPlus
 *   → produces: generated/EconomyPlus.zip
 */

declare(strict_types=1);

if ($argc < 2) {
    echo "Usage: php zip-plugin.php <path/to/plugin>\n";
    exit(1);
}

$pluginPath = rtrim(realpath($argv[1]) ?: $argv[1], '/');

if (!is_dir($pluginPath)) {
    echo "Error: '$pluginPath' is not a directory.\n";
    exit(1);
}

if (!extension_loaded('zip')) {
    echo "Error: PHP 'zip' extension is not loaded. Install it with:\n";
    echo "  sudo apt install php-zip\n";
    exit(1);
}

$ymlPath = $pluginPath . '/plugin.yml';
if (!file_exists($ymlPath)) {
    echo "Error: No plugin.yml found in '$pluginPath'. Is this a plugin directory?\n";
    exit(1);
}

// Read plugin name from plugin.yml
$yml     = file_get_contents($ymlPath);
$name    = 'plugin';
$version = '1.0.0';

if (preg_match('/^name:\s*(.+)$/m', $yml, $m)) $name    = trim($m[1]);
if (preg_match('/^version:\s*(.+)$/m', $yml, $v)) $version = trim($v[1]);

$outputFile = dirname($pluginPath) . "/{$name}_v{$version}.zip";

// Patterns to exclude
$excludePatterns = [
    '/\.git/',
    '/\.gitignore/',
    '/\.DS_Store/',
    '/Thumbs\.db/',
    '/\.idea/',
    '/\.vscode/',
    '/node_modules/',
    '/vendor/',
    '/composer\.lock/',
    '/phpunit\.xml/',
    '/\.phpunit/',
];

function shouldExclude(string $path, array $patterns): bool {
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $path)) return true;
    }
    return false;
}

$zip = new ZipArchive();
if ($zip->open($outputFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    echo "Error: Could not create zip file at '$outputFile'.\n";
    exit(1);
}

$baseName = basename($pluginPath);
$files    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pluginPath));
$count    = 0;

foreach ($files as $file) {
    if ($file->isDir()) continue;

    $realPath = $file->getPathname();
    $relative = $baseName . '/' . ltrim(str_replace($pluginPath, '', $realPath), '/');

    if (shouldExclude($realPath, $excludePatterns)) {
        echo "  ⏭  Skipped: $relative\n";
        continue;
    }

    $zip->addFile($realPath, $relative);
    echo "  ➕ Added:   $relative\n";
    $count++;
}

$zip->close();

$size = round(filesize($outputFile) / 1024, 1);
echo "\n✅ Packaged $count files into: $outputFile ($size KB)\n";
echo "   Drop this zip into your server's plugins/ folder and restart.\n\n";
