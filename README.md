# PocketMine Plugin Dev - AI Skill

<div align="center">

**The advanced AI skill for vibecoding production-grade PocketMine-MP 5.x plugins.**

Build any plugin type - PvP arenas, minigames, economy systems, admin tools, chat formatters, world managers, and beyond - with a real-world scalable architecture that actually ships.

[![PHP 8.2](https://img.shields.io/badge/PHP-8.2-7E4798?style=flat-square&logo=php)](https://php.net)
[![PocketMine-MP](https://img.shields.io/badge/PocketMine--MP-5.42.x-00A6FF?style=flat-square)](https://github.com/pmmp/PocketMine-MP)
[![API](https://img.shields.io/badge/API-5.0.0-green?style=flat-square)](https://pmmp.github.io/PocketMine-MP/doxygen/index.html)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow?style=flat-square)](LICENSE)

</div>

---

## Installation

Here's a video showcasing the CLi and the skill itself: https://youtu.be/dEvFha8-n54?si=Zmf4xTetrvdrWRcV

You can install the CLI globally using npm:

```bash
npm install -g pocketmine-skill
```

Once installed, you can use the interactive installer to set up the skill in your coding assistant:

```bash
# Install interactively
pocketmine-skill init

# Install for a specific assistant
pocketmine-skill init --ai cursor
pocketmine-skill init --ai claude
pocketmine-skill init --ai windsurf
```

---

## What This Skill Does

When you describe a plugin idea, this skill:

1. **Classifies** the request - picks the correct architecture tier (Simple / Medium / Complex)
2. **Selects** the matching category playbook (PvP, minigame, economy, admin, chat, world, etc.)
3. **Generates** a complete, production-ready plugin with every file - no stubs, no TODOs
4. **Enforces** a non-developer-friendly `config.yml` + `PluginConfig.php` on every plugin
5. **Validates** output against a 17-point checklist before delivering

### What You Get

```
┌─────────────────────────────────────────────────────────┐
│          PLUGIN ARCHITECTURE OUTPUT                     │
├─────────────┬──────────────────┬────────────────────────┤
│  plugin.yml │  config.yml      │  PluginConfig.php      │
│             │  (with comments  │  (type-safe wrapper -  │
│  Every field│   for non-devs)  │   no raw getConfig()   │
│  correct    │                  │   anywhere else)       │
├─────────────┴──────────────────┴────────────────────────┤
│  Main.php        ← Thin bootstrap, zero business logic  │
│  EventHandler.php← Single listener that only routes     │
│  Session.php     ← Per-player state, never stores Player│
│  SessionFactory  ← Manages all active sessions          │
│  *Factory.php    ← One factory per entity collection    │
│  Manager/        ← All business logic lives here        │
│  command/        ← Constructor-injected dependencies     │
│  utils/Utils.php ← Shared helpers                       │
└─────────────────────────────────────────────────────────┘
```

---

## Architecture Tiers

The skill adapts the generated structure to match the plugin's complexity:

| Tier | When Used | What's Generated |
|------|-----------|-----------------|
| **Simple** | Single feature, 1–2 events (e.g. chat formatter, join message, anti-swear) | `Main` + `Listener` + `PluginConfig` |
| **Medium** | Multiple features, player data, commands (e.g. economy, warps, kits, staff mode) | + `Manager` class + `Session` + async storage |
| **Complex** | Multiple subsystems, game modes, world management (e.g. PvP arenas, minigame servers, matchmaking) | + Factories + Session Traits + EventHandler dispatcher + world copy/delete |

---

## Plugin Categories

The skill includes a dedicated playbook for each category:

| Category | Examples |
|----------|---------|
| **Chat / Social** | Chat formatter, anti-swear, private messages, staff chat, channels |
| **Economy** | Balances, /pay, /baltop, shop, auction house |
| **PvP / Combat** | Combat tags, kill streaks, custom knockback, bounties, anti-combat-log |
| **Minigame / Arena** | Spleef, Skywars, Bedwars, Murder Mystery, any area-based game |
| **Duel / Matchmaking** | Ranked/unranked 1v1, ELO, queue system, kit selection, world copy |
| **Clan / Guild** | Create, invite, war, friendly fire, leaderboard |
| **Admin / Staff** | Vanish, freeze, staff mode, spy, ban system, reports |
| **World / Region** | Warps, homes, region protection, AABB areas |
| **Utility / Misc** | Custom kits, crates, vote rewards, MOTD, ping display |

---

## Key Architecture Patterns

### 1. Config System (Mandatory on Every Plugin)

Every plugin ships with a commented `config.yml` and a `PluginConfig.php` wrapper:

```php
// Every config value accessed through typed getters - never raw getConfig()
$prefix   = $this->config->getPrefix();
$cooldown = $this->config->getDefaultCooldown();
$msg      = $this->config->getMessage('no-permission', ['player' => $player->getName()]);
```

The `config.yml` is written for server owners, not developers:
```yaml
# Time in seconds a player must wait before using the feature again.
# Set to 0 to disable cooldowns entirely.
cooldowns:
  default: 30
  vip-bypass: true  # Players with 'plugin.vip' permission skip the cooldown
```

### 2. Factory Pattern

Every entity collection gets its own factory:
```php
ArenaFactory::create($name, $worldTemplate, $kit);
ArenaFactory::get($name);        // → ?Arena
ArenaFactory::getAll();          // → Arena[]
ArenaFactory::task($plugin);     // Registers repeating update loop
ArenaFactory::loadAll($plugin);  // Called once in onEnable()
ArenaFactory::saveAll($plugin);  // Called once in onDisable()
ArenaFactory::disable();         // Called in onDisable() - clean shutdown
```

### 3. Session Pattern

Player state is stored in a `Session` class - never the `Player` object itself:

```php
final class Session {
    // Stores: uuid (raw bytes), xuid (string), name (string)
    // Recovers Player via Server::getInstance()->getPlayerByRawUUID($uuid)

    public function getPlayer(): ?Player { ... }  // Always nullable - player may be offline
    public function isIdle(): bool { ... }         // Not in any game context
    public function onJoin(): void { ... }         // Called on PlayerJoinEvent
    public function onQuit(): void { ... }         // Called on PlayerQuitEvent
    public function update(): void { ... }         // Called every tick
    public function persist(): void { ... }        // Flush to DB on disable
}
```

### 4. Single EventHandler Dispatcher

One `EventHandler implements Listener` routes events to the correct context:
```php
public function onDamage(EntityDamageEvent $event): void {
    // 1. Universal guards
    // 2. Route to context: session->getArena()->handleDamage($event)
    // 3. Post-routing cross-cutting: custom KB, hit particles, CPS
}
```

### 5. Duel Type Inheritance

Game mode variants extend a base class and only override what's different:
```
Duel (base)  ← All shared logic
  ├── Boxing.php   → Count hits to 100 instead of killing
  ├── Sumo.php     → Void = elimination; no damage
  ├── NoDebuff.php → Allow potion drinking
  └── Bridge.php   → Goal scoring via block placement
```

---

## Tools

Three PHP scripts are included in `scripts/` to speed up development:

### `scaffold.php` - Generate a Plugin Skeleton

```bash
php scripts/scaffold.php
```

Interactive prompts ask for plugin name, author, tier. Outputs a complete folder structure to `generated/`.

### `validate.php` - Check a Plugin for Issues

```bash
php scripts/validate.php generated/MyPlugin
```

Checks for 15+ issues including missing `declare(strict_types=1)`, wrong `api` format, raw `getConfig()` calls, debug code, wildcard imports, and more.

### `zip-plugin.php` - Package for Distribution

```bash
php scripts/zip-plugin.php generated/MyPlugin
# → produces: generated/MyPlugin_v1.0.0.zip
```

---

## Quick Start

### Using Antigravity

The skill activates automatically when you describe a PocketMine plugin. Just chat naturally:

```
Create a PvP plugin with combat tags, kill streaks, custom knockback, and MySQL storage

Create a Spleef minigame with arena management, ELO rankings, and scoreboard

Create an economy plugin with /pay, /baltop, shop, and YAML storage
```

### Using the Scaffold Script

```bash
# Generate a ready-to-code plugin skeleton
php scripts/scaffold.php

# Validate finished code
php scripts/validate.php generated/MyPlugin

# Package for upload to server
php scripts/zip-plugin.php generated/MyPlugin
```

---

## File Structure

```
pocketmine-plugin-dev/
├── skill.json                          ← Skill metadata
├── README.md                           ← This file
├── CLAUDE.md                           ← AI model instructions (how to use this skill)
├── LICENSE
│
├── skills/
│   └── pocketmine-plugin-dev/
│       ├── SKILL.md                    ← Core AI instructions (read this first)
│       └── resources/
│           └── api_cheatsheet.md       ← Quick API reference
│
├── examples/
│   ├── BasicPlugin/                    ← Tier 1: minimal skeleton
│   ├── PvPPlugin/                      ← Tier 3: combat tags, kill streaks, KB
│   ├── MinigamePlugin/                 ← Tier 3: Spleef arena with state machine
│   └── EconomyPlugin/                  ← Tier 2: balance system with async SQLite
│
├── docs/
│   ├── architecture.md                 ← Architecture deep dive
│   ├── config-system.md                ← Config.php pattern guide
│   ├── events-reference.md             ← Full PM5 event reference
│   └── anti-patterns.md                ← What NOT to do
│
└── scripts/
    ├── README.md                       ← Script usage guide
    ├── scaffold.php                    ← Interactive plugin generator
    ├── validate.php                    ← Code quality checker
    └── zip-plugin.php                  ← Plugin packager
```

---

## Skill Guarantees

Every plugin generated by this skill:

- ✅ Has `api: ["5.0.0"]` - never a plain string
- ✅ Ships with a commented `config.yml` a non-developer can edit
- ✅ Has a `PluginConfig.php` so no class calls `getConfig()` directly
- ✅ Has `declare(strict_types=1)` in every file
- ✅ Uses XUID as primary key everywhere - never player name
- ✅ Never stores `Player` objects in long-lived arrays
- ✅ Runs all DB operations async - never blocks the main thread
- ✅ Has null-checks on all `SessionFactory::get()` calls
- ✅ Registers custom entities in `onLoad()`, not `onEnable()`
- ✅ Contains zero stubs, zero TODOs, zero placeholder code

---

## Requirements

| Requirement | Version |
|------------|---------|
| PocketMine-MP | 5.42.x (latest stable) |
| Minecraft Bedrock | 1.26.10 |
| PHP (server) | 8.2 |
| PHP (scripts) | 8.0+ |

---

## License

MIT - Use freely, modify freely, no attribution required.
