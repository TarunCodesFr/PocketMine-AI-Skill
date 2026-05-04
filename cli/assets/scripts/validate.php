#!/usr/bin/env php
<?php

/**
 * validate.php — PocketMine Plugin Validator
 *
 * Scans a plugin directory for common structural mistakes and code quality issues.
 *
 * Usage:
 *   php scripts/validate.php <path/to/plugin>
 *
 * Example:
 *   php scripts/validate.php generated/EconomyPlus
 *   php scripts/validate.php /home/user/plugins/MyPlugin
 */

declare(strict_types=1);

if ($argc < 2) {
    echo "Usage: php validate.php <path/to/plugin>\n";
    exit(1);
}

$pluginPath = rtrim($argv[1], '/');

if (!is_dir($pluginPath)) {
    echo "Error: '$pluginPath' is not a directory.\n";
    exit(1);
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

$errors   = [];
$warnings = [];
$ok       = [];

function pass(string $msg): void  { global $ok;       $ok[]       = "  ✅  $msg"; }
function warn(string $msg): void  { global $warnings;  $warnings[] = "  ⚠️  $msg"; }
function fail(string $msg): void  { global $errors;    $errors[]   = "  ❌  $msg"; }

function findPhpFiles(string $dir): array {
    $result = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
        if ($file->getExtension() === 'php') {
            $result[] = $file->getPathname();
        }
    }
    return $result;
}

function fileContains(string $path, string $needle): bool {
    return str_contains(file_get_contents($path), $needle);
}

// ─── plugin.yml checks ───────────────────────────────────────────────────────

echo "\n🔍 Validating: $pluginPath\n\n";
echo "── plugin.yml ──────────────────────────────────────────────────────\n";

$yml = $pluginPath . '/plugin.yml';
if (!file_exists($yml)) {
    fail("plugin.yml is missing");
} else {
    $raw = file_get_contents($yml);
    pass("plugin.yml exists");

    if (preg_match('/^api:\s*\[/m', $raw)) {
        pass("api is declared as an array");
    } elseif (preg_match('/^api:\s*"5/m', $raw)) {
        fail('api must be ["5.0.0"] (array), not a plain string');
    } else {
        warn("Could not confirm api declaration format");
    }

    if (!preg_match('/^main:/m', $raw))        fail("plugin.yml missing 'main' field");
    else                                        pass("main class declared");

    if (!preg_match('/^version:/m', $raw))     warn("plugin.yml missing 'version' field");
    else                                        pass("version declared");

    if (!preg_match('/^description:/m', $raw)) warn("plugin.yml missing 'description' field");
    else                                        pass("description declared");

    if (preg_match('/^permissions:/m', $raw))  pass("permissions block declared");
    else                                        warn("No permissions block in plugin.yml");
}

// ─── resources/config.yml ────────────────────────────────────────────────────

echo "\n── resources/config.yml ────────────────────────────────────────────\n";

$configPath = $pluginPath . '/resources/config.yml';
if (!file_exists($configPath)) {
    fail("resources/config.yml is missing — every plugin must ship a default config");
} else {
    $configContent = file_get_contents($configPath);
    pass("resources/config.yml exists");

    $lines    = explode("\n", $configContent);
    $hasComments = false;
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) { $hasComments = true; break; }
    }
    if ($hasComments) pass("config.yml has comments (non-developer friendly)");
    else              warn("config.yml has no comments — server owners need explanatory comments");

    if (str_contains($configContent, 'prefix')) pass("config.yml has a prefix key");
    else                                         warn("config.yml missing 'prefix' key (recommended)");

    if (str_contains($configContent, 'messages')) pass("config.yml has a messages section");
    else                                           warn("config.yml missing 'messages' section (recommended)");
}

// ─── PHP file checks ─────────────────────────────────────────────────────────

echo "\n── PHP Files ───────────────────────────────────────────────────────\n";

$srcDir = $pluginPath . '/src';
if (!is_dir($srcDir)) {
    fail("src/ directory is missing");
    echo "\n";
    goto summary;
}

$phpFiles = findPhpFiles($srcDir);
if (count($phpFiles) === 0) {
    fail("No PHP files found in src/");
    echo "\n";
    goto summary;
}

pass(count($phpFiles) . " PHP files found");

$missingStrict = [];
$missingNamespace = [];
$hasPlayerInArray = [];
$hasGetConfigDirect = [];
$hasVarDump = [];
$hasAsyncServerAccess = [];
$hasWildcardImport = [];
$hasPracticeRef = [];

foreach ($phpFiles as $file) {
    $content  = file_get_contents($file);
    $rel      = str_replace($pluginPath . '/', '', $file);

    if (!str_contains($content, 'declare(strict_types=1)')) {
        $missingStrict[] = $rel;
    }
    if (!preg_match('/^namespace /m', $content)) {
        $missingNamespace[] = $rel;
    }
    if (preg_match('/\$[a-zA-Z_]+\s*=\s*\$[a-zA-Z_]+.*Player.*;\s*\/\/.*(?!xuid|uuid|name)/i', $content)) {
        $hasPlayerInArray[] = $rel;
    }
    // Direct getConfig() call outside of PluginConfig.php
    if (!str_contains($rel, 'PluginConfig') && preg_match('/->getConfig\(\)/', $content)) {
        $hasGetConfigDirect[] = $rel;
    }
    if (preg_match('/\bvar_dump\b|\bprint_r\b/', $content)) {
        $hasVarDump[] = $rel;
    }
    if (preg_match('/extends\s+AsyncTask/', $content) && preg_match('/Server::getInstance\(\)/', $content)) {
        $hasAsyncServerAccess[] = $rel;
    }
    if (preg_match('/^use pocketmine\\\\\*;/m', $content)) {
        $hasWildcardImport[] = $rel;
    }
    if (preg_match('/\bPractice\b/', $content) && !str_contains($rel, 'vendor')) {
        $hasPracticeRef[] = $rel;
    }
}

if (count($missingStrict) === 0) {
    pass("All files have declare(strict_types=1)");
} else {
    foreach ($missingStrict as $f) fail("Missing declare(strict_types=1): $f");
}

if (count($missingNamespace) === 0) {
    pass("All files have namespace declarations");
} else {
    foreach ($missingNamespace as $f) fail("Missing namespace: $f");
}

if (count($hasGetConfigDirect) === 0) {
    pass("No raw getConfig() calls outside PluginConfig");
} else {
    foreach ($hasGetConfigDirect as $f) warn("Direct getConfig() call (use PluginConfig instead): $f");
}

if (count($hasVarDump) === 0) {
    pass("No debug var_dump/print_r found");
} else {
    foreach ($hasVarDump as $f) fail("Debug output left in code: $f");
}

if (count($hasAsyncServerAccess) === 0) {
    pass("No Server::getInstance() in AsyncTask::onRun() detected");
} else {
    foreach ($hasAsyncServerAccess as $f) fail("AsyncTask may access Server in onRun() — UNSAFE: $f");
}

if (count($hasWildcardImport) === 0) {
    pass("No wildcard imports found");
} else {
    foreach ($hasWildcardImport as $f) fail("Wildcard import (use pocketmine\\\\*) in: $f");
}

if (count($hasPracticeRef) === 0) {
    pass("No hardcoded 'Practice' brand references found");
} else {
    foreach ($hasPracticeRef as $f) warn("File references 'Practice' — check if this is a naming issue: $f");
}

// ─── PluginConfig.php check ──────────────────────────────────────────────────

echo "\n── PluginConfig.php ────────────────────────────────────────────────\n";

$configPhp = null;
foreach ($phpFiles as $f) {
    if (str_ends_with($f, 'PluginConfig.php')) { $configPhp = $f; break; }
}

if ($configPhp !== null) {
    pass("PluginConfig.php exists");
    $content = file_get_contents($configPhp);
    if (str_contains($content, 'getMessage')) pass("PluginConfig has getMessage() method");
    else                                       warn("PluginConfig missing getMessage() — messages won't be translatable");
    if (str_contains($content, 'getPrefix'))  pass("PluginConfig has getPrefix() method");
    else                                       warn("PluginConfig missing getPrefix()");
} else {
    fail("PluginConfig.php not found — every plugin must have a type-safe config wrapper");
}

// ─── Directory structure checks ──────────────────────────────────────────────

echo "\n── Structure ───────────────────────────────────────────────────────\n";

if (is_dir($pluginPath . '/src'))              pass("src/ directory present");
if (is_dir($pluginPath . '/resources'))        pass("resources/ directory present");
if (!is_dir($pluginPath . '/resources'))       fail("resources/ directory missing");

$hasCommand = false;
foreach ($phpFiles as $f) {
    if (str_contains($f, 'Command') && str_ends_with($f, '.php')) { $hasCommand = true; break; }
}
if ($hasCommand) pass("Command class(es) found");
else             warn("No command classes found");

$hasListener = false;
foreach ($phpFiles as $f) {
    if (str_contains($f, 'Listener') || str_contains($f, 'EventHandler')) { $hasListener = true; break; }
}
if ($hasListener) pass("Listener/EventHandler found");
else              warn("No listener class found");

// ─── Summary ─────────────────────────────────────────────────────────────────

summary:
echo "\n────────────────────────────────────────────────────────────────────\n";
echo "SUMMARY\n";

foreach ($ok as $msg)       echo $msg . "\n";
foreach ($warnings as $msg) echo $msg . "\n";
foreach ($errors as $msg)   echo $msg . "\n";

echo "\n";
echo "Passed:   " . count($ok) . "\n";
echo "Warnings: " . count($warnings) . "\n";
echo "Errors:   " . count($errors) . "\n\n";

if (count($errors) === 0) {
    echo "✅ Plugin passed all checks!\n\n";
    exit(0);
} else {
    echo "❌ " . count($errors) . " error(s) found. Please fix before shipping.\n\n";
    exit(1);
}
