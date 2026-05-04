#!/usr/bin/env bash

# scripts/README.md - Script Directory Overview
# This file explains each script and how to use them.
# --------------------------------------------------------
# These scripts are run from your terminal (not in-game).
# You need PHP 8.2+ installed on your machine.
# --------------------------------------------------------

: '
╔══════════════════════════════════════════════════════════════╗
║         PocketMine Plugin Dev Skill - Scripts                ║
╚══════════════════════════════════════════════════════════════╝

scaffold.php - Generate a new plugin skeleton
─────────────────────────────────────────────
Creates a complete plugin folder with all boilerplate based on
the plugin name, author, and complexity tier you choose.

Usage:
  php scripts/scaffold.php

What it generates:
  - plugin.yml
  - resources/config.yml  (with comments for non-developers)
  - PluginConfig.php      (type-safe config wrapper)
  - Main.php              (bootstrap only)
  - EventListener.php
  - Command class
  - Utils.php
  - Session + SessionFactory (Tier 3 only)

Output location: ./generated/<PluginName>/


validate.php - Check a plugin for common issues
────────────────────────────────────────────────
Scans a plugin directory for structural mistakes and code
quality problems before shipping it to a server.

Usage:
  php scripts/validate.php <path/to/plugin>

Examples:
  php scripts/validate.php generated/EconomyPlus
  php scripts/validate.php /home/me/plugins/MyPlugin

Checks performed:
  ✅ plugin.yml has api: ["5.0.0"] (array format)
  ✅ resources/config.yml exists and has comments
  ✅ PluginConfig.php exists with getMessage() + getPrefix()
  ✅ All PHP files have declare(strict_types=1)
  ✅ No wildcard imports (use pocketmine\*)
  ✅ No var_dump/print_r debug code left in
  ✅ No Server::getInstance() inside AsyncTask::onRun()
  ✅ No raw getConfig() calls outside PluginConfig.php
  ⚠️ Warns about missing comments, permissions, listener

Exit codes:
  0 = all checks passed
  1 = one or more errors found


zip-plugin.php - Package a plugin for distribution
───────────────────────────────────────────────────
Zips a plugin folder into a distributable archive that can
be dropped directly into a server'\''s plugins/ folder.

Usage:
  php scripts/zip-plugin.php <path/to/plugin>

Example:
  php scripts/zip-plugin.php generated/EconomyPlus
  → generates: generated/EconomyPlus_v1.0.0.zip

Automatically excludes:
  .git, .gitignore, .DS_Store, .idea, .vscode,
  vendor/, node_modules/, phpunit.xml


Typical Workflow:
─────────────────
  1. php scripts/scaffold.php          ← Start a new plugin
  2. <edit the generated code with AI> ← Add your feature
  3. php scripts/validate.php generated/MyPlugin   ← Check it
  4. php scripts/zip-plugin.php generated/MyPlugin ← Package it
  5. Upload the .zip to your server'\''s plugins/ folder

'
