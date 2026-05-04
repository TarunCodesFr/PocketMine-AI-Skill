# CLAUDE.md — PocketMine Plugin Dev Skill

This file tells the AI model how to use this skill. Read this before generating any plugin code.

---

## How to Activate This Skill

This skill lives at `skills/pocketmine-plugin-dev/SKILL.md`.

**Before generating any plugin**, always read `SKILL.md` fully. It contains the architecture rules, category playbooks, config system requirements, and the 17-point review checklist.

---

## Core Rules (Always Apply)

1. **Read `SKILL.md` first.** Never generate code from memory alone.
2. **Pick the right tier.** Simple → Medium → Complex. Do not over-engineer simple requests.
3. **Every plugin gets `config.yml` + `PluginConfig.php`.** No exceptions.
4. **Main class is named after the plugin.** Not "Practice", not "Core", not "Main" alone — `EconomyPlus.php`, `SpleefArena.php`, `ChatPro.php`.
5. **No stubs, no TODOs in output.** All generated code must be complete and runnable.

---

## How to Use This Skill Step-by-Step

```
1. User asks for a plugin
       ↓
2. Read SKILL.md § Step 1 — classify the request
       ↓
3. Pick the category playbook (§5) matching the request
       ↓
4. Select architecture tier (Simple/Medium/Complex)
       ↓
5. Plan: file structure → config schema → command tree → event hooks
       ↓
6. Generate all files in the order defined in SKILL.md §13
       ↓
7. Run the §14 review checklist before delivering
```

---

## File Output Order

Always output files in this order:

1. `plugin.yml`
2. `resources/config.yml`
3. `src/.../PluginConfig.php`
4. `src/.../Main.php`
5. `src/.../EventHandler.php` or `listener/EventListener.php`
6. Session + SessionFactory (Complex tier only)
7. Trait files (Complex tier only)
8. Core subsystem classes + factories
9. Database layer
10. Commands
11. Forms, Items, Entities
12. `utils/Utils.php`

---

## What Each File Must Contain

**Every PHP file:**
- `<?php`
- `declare(strict_types=1);`
- Full namespace declaration
- Explicit `use` imports (no wildcards)
- Return types on all methods
- DocBlocks on public/protected methods

**config.yml:**
- A comment on every key explaining what it does
- Examples of valid values in comments
- A "Permissions Overview" section at the bottom

**PluginConfig.php:**
- A typed getter for every key in config.yml
- `getMessage(string $key, array $placeholders): string`
- `getPrefix(): string`
- `isDebug(): bool`
- `reload(): void`

**Main.php:**
- Thin bootstrap only — no business logic
- Constructor-injects `PluginConfig` into all managers and commands
- Calls `Factory::loadAll()` in `onEnable()`
- Calls `Factory::saveAll()` and `Factory::disable()` in `onDisable()`

---

## Anti-Patterns (Never Generate These)

```
❌ api: "5.0.0"                   → must be api: ["5.0.0"]
❌ $players[$player->getName()]  → use XUID as key
❌ $this->player = $player        → store uuid/xuid/name, not Player
❌ $this->plugin->getConfig()->get() outside PluginConfig.php
❌ new \SQLite3() on the main thread
❌ EntityFactory::register() in onEnable() → must be onLoad()
❌ use pocketmine\*;               → explicit imports only
❌ var_dump() / print_r()          → use $plugin->getLogger()->debug()
❌ // TODO:                        → complete all implementations
❌ Logic inside Main.php           → belongs in Manager/Factory/Session
```

---

## Using the Scripts

```bash
# Generate a new plugin skeleton interactively
php scripts/scaffold.php

# Validate a generated plugin for issues
php scripts/validate.php path/to/plugin

# Package a plugin for distribution
php scripts/zip-plugin.php path/to/plugin
```

---

## Skill Version

`1.0.0` — PocketMine-MP 5.42.x / API 5.0.0 / PHP 8.2
