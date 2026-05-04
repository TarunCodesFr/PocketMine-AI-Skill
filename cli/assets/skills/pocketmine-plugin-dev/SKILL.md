---
name: pocketmine-plugin-dev
description: >
  Advanced AI skill for vibecoding high-quality, production-grade PocketMine-MP 5.x
  plugins. Targets the latest stable API (5.42+, PHP 8.2). Architecture scales from
  simple single-file utilities to complex multi-system plugins (PvP, minigames,
  economy, chat, admin tools, world managers, etc). Adapts structure to plugin complexity.
  Non-developer friendly: always generates a well-documented config.yml + Config.php.
---

# PocketMine-MP Advanced Plugin Development Skill

## Server Target
| Field | Value |
|-------|-------|
| PocketMine-MP | **5.42.x** (latest stable) |
| Minecraft Bedrock | **1.26.10** |
| PHP | **8.2** |
| `plugin.yml` API | `api: ["5.0.0"]` |
| Root namespace | `pocketmine\` |

---

## Step 1 — Read The Request, Pick The Right Scale

**Before writing any code**, classify the plugin into a complexity tier:

| Tier | Description | Architecture Used |
|------|-------------|-------------------|
| **Simple** | Single feature, few events (e.g. chat formatter, spawn teleport, anti-swear) | `Main.php` + single `Listener` + `Config.php` |
| **Medium** | Multiple features, player data, commands (e.g. economy, warps, homes, clans) | Factory + Session + `Config.php` + async DB |
| **Complex** | Multiple subsystems, game modes, world management (e.g. PvP arenas, minigame servers, faction systems) | Full Factory + Session + Traits + EventHandler dispatcher + multiple subsystems |

**Ask before assuming.** If the scope is unclear, ask:
- How many players does this serve?
- Does it need persistent player data (survives restarts)?
- Does it need a database (MySQL) or is flat YAML fine?
- What commands should non-developers be able to tweak in config?

**Always generate for the appropriate tier** — don't over-engineer a simple plugin, don't under-engineer a complex one.

---

## Step 2 — Universal Config System (Required for ALL Plugins)

**Every plugin** must ship with a `config.yml` + a `Config.php` wrapper class. This makes the plugin usable by server owners who have never written PHP.

### 2.1 resources/config.yml (Ship With Every Plugin)

Write it so a non-developer can understand and edit it without reading any code. Every key must have a comment explaining what it does and what values are valid.

```yaml
# =============================================================
# PluginName Configuration
# Version: 1.0.0
# Docs: https://github.com/author/pluginname
# =============================================================

# ---- General Settings ----------------------------------------

# The prefix shown before all plugin messages in chat.
# Supports color codes: & followed by a color code letter.
# Example: "&7[&bMyPlugin&7]" → gray brackets, aqua name
prefix: "&7[&bPluginName&7] &r"

# Enable debug mode. When true, extra messages appear in the server console.
# Useful for troubleshooting. Set to false on live servers.
debug: false


# ---- Messages ------------------------------------------------
# All messages support color codes (&a = green, &c = red, &e = yellow, etc.)
# You can use these placeholders where shown:
#   {player}   → player's name
#   {amount}   → a number (where applicable)
#   {value}    → a general value

messages:
  # Shown when a player lacks permission to run a command.
  no-permission: "{prefix}&cYou don't have permission to do that."

  # Shown when a command is run from the console but requires a player.
  player-only: "{prefix}&cThis command can only be used in-game."

  # Shown when the config is reloaded with /pluginname reload.
  reload: "{prefix}&aConfiguration reloaded successfully."

  # Shown when a player provides wrong command arguments.
  usage: "{prefix}&cIncorrect usage. Try: &e{usage}"


# ---- Feature-Specific Settings ------------------------------
# (Replace this section with actual features for the current plugin)
# Example for a cooldown-based plugin:

cooldowns:
  # Time in seconds a player must wait before using the feature again.
  # Set to 0 to disable cooldowns entirely.
  default: 30

  # Whether VIP players bypass the cooldown.
  # Permission node: pluginname.vip
  vip-bypass: true


# ---- Database -----------------------------------------------
# Choose "yaml" for small servers (saves to a .yml file),
# or "mysql" for larger servers with an existing MySQL database.
# yaml:  no extra setup needed, file stored in the plugin folder.
# mysql: requires a running MySQL/MariaDB server.

database:
  type: yaml   # Options: yaml | mysql

  # Only used when type is "mysql":
  mysql:
    host: "127.0.0.1"
    port: 3306
    user: "root"
    password: ""
    database: "minecraft"


# ---- Permissions Overview -----------------------------------
# pluginname.command         → Run the main command          (default: all players)
# pluginname.command.admin   → Admin sub-commands            (default: op only)
# pluginname.vip             → VIP perks                     (default: op only)
# Assign these via your permission plugin (e.g. LuckPerms).
```

### 2.2 src/.../Config.php (Type-Safe Config Wrapper)

This class is the single gateway to all config values. The rest of the plugin never calls `$plugin->getConfig()->get(...)` directly — it always goes through `PluginConfig`.

```php
<?php
declare(strict_types=1);

namespace Author\PluginName;

use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;

/**
 * Type-safe wrapper around config.yml.
 * All config access goes through this class — never call getConfig() elsewhere.
 *
 * HOW TO ADD A NEW CONFIG KEY:
 * 1. Add the key with a comment to resources/config.yml
 * 2. Add a typed getter method here
 * 3. Use the getter in your code
 */
final class PluginConfig {

    private Config $config;

    public function __construct(private readonly Main $plugin) {
        $this->config = $plugin->getConfig();
    }

    /** Reload config values from disk. */
    public function reload(): void {
        $this->plugin->reloadConfig();
        $this->config = $this->plugin->getConfig();
    }

    // -------------------------------------------------------------------------
    // General
    // -------------------------------------------------------------------------

    /**
     * The chat prefix, colorized and ready to use.
     * Example: "§7[§bPluginName§7] §r"
     */
    public function getPrefix(): string {
        return TextFormat::colorize((string) $this->config->get('prefix', '&7[&bPlugin&7] &r'));
    }

    /** Whether debug logging is enabled. */
    public function isDebug(): bool {
        return (bool) $this->config->get('debug', false);
    }

    // -------------------------------------------------------------------------
    // Messages
    // -------------------------------------------------------------------------

    /**
     * Retrieve a translated, colorized message string.
     *
     * @param string               $key          Dot-separated key under "messages"
     * @param array<string,string> $placeholders Map of {key} → value replacements
     */
    public function getMessage(string $key, array $placeholders = []): string {
        $raw = (string) $this->config->getNested("messages.$key", "&cMissing message: $key");
        $msg = str_replace('{prefix}', $this->getPrefix(), $raw);
        foreach ($placeholders as $ph => $value) {
            $msg = str_replace('{' . $ph . '}', $value, $msg);
        }
        return TextFormat::colorize($msg);
    }

    // -------------------------------------------------------------------------
    // Cooldowns
    // -------------------------------------------------------------------------

    /** Default cooldown in seconds. 0 = no cooldown. */
    public function getDefaultCooldown(): int {
        return max(0, (int) $this->config->getNested('cooldowns.default', 30));
    }

    /** Whether VIP players bypass the cooldown. */
    public function isVipBypassCooldown(): bool {
        return (bool) $this->config->getNested('cooldowns.vip-bypass', true);
    }

    // -------------------------------------------------------------------------
    // Database
    // -------------------------------------------------------------------------

    /** "yaml" or "mysql" */
    public function getDatabaseType(): string {
        $type = strtolower((string) $this->config->getNested('database.type', 'yaml'));
        return in_array($type, ['yaml', 'mysql'], true) ? $type : 'yaml';
    }

    public function getMysqlHost(): string {
        return (string) $this->config->getNested('database.mysql.host', '127.0.0.1');
    }

    public function getMysqlPort(): int {
        return (int) $this->config->getNested('database.mysql.port', 3306);
    }

    public function getMysqlUser(): string {
        return (string) $this->config->getNested('database.mysql.user', 'root');
    }

    public function getMysqlPassword(): string {
        return (string) $this->config->getNested('database.mysql.password', '');
    }

    public function getMysqlDatabase(): string {
        return (string) $this->config->getNested('database.mysql.database', 'minecraft');
    }

    // -------------------------------------------------------------------------
    // Debug helper
    // -------------------------------------------------------------------------

    /**
     * Log a message only when debug mode is enabled.
     */
    public function debug(string $message): void {
        if ($this->isDebug()) {
            $this->plugin->getLogger()->debug("[DEBUG] $message");
        }
    }
}
```

### 2.3 Using PluginConfig Throughout the Plugin

```php
// In Main.php — create once, pass via constructor injection
$this->pluginConfig = new PluginConfig($this);

// In a listener or manager — receive via constructor
public function __construct(private readonly PluginConfig $config) {}

// Usage examples:
$sender->sendMessage($this->config->getMessage('no-permission'));
$sender->sendMessage($this->config->getMessage('usage', ['usage' => '/myplugin reload']));

if ($this->config->isDebug()) { /* ... */ }
$cooldown = $this->config->getDefaultCooldown();
```

---

## Step 3 — Architecture by Plugin Tier

### Tier 1 — Simple Plugin Structure

```
MyPlugin/
├── plugin.yml
├── resources/
│   └── config.yml            ← Always included
└── src/
    └── Author/
        └── MyPlugin/
            ├── Main.php       ← Bootstrap + holds PluginConfig
            ├── PluginConfig.php
            ├── listener/
            │   └── EventListener.php
            └── command/
                └── MyCommand.php
```

### Tier 2 — Medium Plugin Structure

```
MyPlugin/
├── plugin.yml
├── resources/
│   ├── config.yml
│   └── data.yml              ← If YAML storage needed
└── src/
    └── Author/
        └── MyPlugin/
            ├── Main.php
            ├── PluginConfig.php
            ├── listener/
            │   └── EventListener.php
            ├── command/
            │   ├── MainCommand.php
            │   └── sub/
            │       ├── HelpSubCommand.php
            │       └── ReloadSubCommand.php
            ├── manager/
            │   └── [FeatureNameManager].php  ← All business logic lives here
            ├── session/
            │   └── PlayerSession.php         ← Per-player state
            └── utils/
                └── Utils.php
```

### Tier 3 — Complex Plugin Structure

```
MyPlugin/
├── plugin.yml
├── resources/
│   ├── config.yml
│   ├── kits.yml              ← If kits are used
│   ├── mysql.sql             ← DB schema
│   └── messages.yml          ← Separate messages file (optional for big plugins)
└── src/
    └── Author/
        └── MyPlugin/
            ├── Main.php               ← Bootstrap ONLY
            ├── PluginConfig.php       ← Type-safe config wrapper
            ├── EventHandler.php       ← Single listener dispatcher
            │
            ├── session/
            │   ├── Session.php        ← Composed via traits (never stores Player)
            │   ├── SessionFactory.php
            │   ├── data/
            │   │   └── PlayerDataTrait.php
            │   ├── setting/
            │   │   ├── Setting.php
            │   │   ├── SettingTrait.php
            │   │   └── display/       ← Per-setting display classes (CPS, ScoreTag, etc.)
            │   ├── scoreboard/
            │   │   ├── ScoreboardBuilder.php
            │   │   └── ScoreboardTrait.php
            │   └── handler/
            │       └── HandlerTrait.php
            │
            ├── [subsystem-a]/         ← Name after the actual domain (e.g. "game", "duel", "clan")
            │   ├── [SubsystemA].php   ← Core class
            │   ├── [SubsystemA]Factory.php
            │   ├── type/              ← Subclasses/variants (if polymorphic)
            │   └── command/
            │
            ├── [subsystem-b]/
            │   └── ...
            │
            ├── world/
            │   ├── WorldTemplate.php  ← Template world metadata (NOT pm\world\World)
            │   ├── WorldManager.php
            │   └── async/
            │       ├── WorldCopyTask.php
            │       └── WorldDeleteTask.php
            │
            ├── database/
            │   ├── DatabaseManager.php
            │   └── queries/
            │       ├── SelectQuery.php
            │       ├── InsertQuery.php
            │       └── UpdateQuery.php
            │
            ├── form/
            ├── item/
            ├── entity/
            ├── command/
            └── utils/
                └── Utils.php
```

**Naming rules:**
- The main class is always named after the plugin (e.g. `KitPvP.php`, `EconomyPlus.php`, `ChatManager.php`) — **never** named after the server type or brand
- Subsystem folders are named after what they manage (`game/`, `clan/`, `duel/`, `shop/`) — **never** "practice" or brand names
- Factories are named after the class they manage + `Factory` suffix

---

## Step 4 — plugin.yml Template

Adapt this to the actual plugin:

```yaml
name: MyPlugin
version: 1.0.0
main: Author\MyPlugin\Main
api: ["5.0.0"]
description: "What this plugin does in one sentence."
author: AuthorName
website: "https://github.com/author/myplugin"

# Declare commands here for simple/medium plugins.
# For complex plugins with manager injection: register via CommandMap in onEnable().
commands:
  myplugin:
    description: "Main command for MyPlugin"
    usage: "/myplugin <subcommand>"
    aliases: [mp]
    permission: myplugin.command

permissions:
  myplugin.command:
    description: "Access basic MyPlugin commands"
    default: true
  myplugin.command.admin:
    description: "Access admin MyPlugin commands"
    default: op
  myplugin.vip:
    description: "VIP perks"
    default: op
```

**Rules:**
- `api` must ALWAYS be `["5.0.0"]` — array, not string
- Every permission must have a `default` (`true`, `false`, `op`, or `notop`)
- Every command must declare a `permission`

---

## Step 5 — Plugin Category Playbooks

These playbooks define what sub-systems to build depending on what the user asks for. **Select the matching playbook, then adapt it.**

---

### 5.1 Chat / Social Plugins
*(Chat formatter, private messages, staff chat, anti-swear, mentions, chat channels)*

**Systems needed:** `PluginConfig`, single `Listener`, optional `PlayerSession`  
**Events:** `PlayerChatEvent`, `PlayerCommandPreprocessEvent`  
**Storage:** YAML (small) or MySQL (large servers)

```php
// Chat formatting pattern
public function onChat(PlayerChatEvent $event): void {
    $player = $event->getPlayer();
    $prefix = $this->rankManager->getPrefix($player); // e.g. "[Admin]"
    $name   = $player->getName();
    $msg    = $event->getMessage();

    // Anti-swear check before formatting
    if ($this->config->isAntiSwearEnabled() && $this->containsSwear($msg)) {
        $event->cancel();
        $player->sendMessage($this->config->getMessage('swear-detected'));
        return;
    }

    $event->setFormat(TextFormat::colorize("$prefix &f$name&7: &f$msg"));
}

// Rate limiting (spam filter)
private array $lastMessage = [];  // xuid → timestamp

private function isSpamming(Player $player): bool {
    $xuid = $player->getXuid();
    $now  = microtime(true);
    if (isset($this->lastMessage[$xuid]) && ($now - $this->lastMessage[$xuid]) < $this->config->getCooldown()) {
        return true;
    }
    $this->lastMessage[$xuid] = $now;
    return false;
}
```

**config.yml additions for chat plugins:**
```yaml
chat:
  # Format string. Available: {prefix}, {name}, {message}, {world}
  format: "{prefix} &f{name}&7: &f{message}"

  # Minimum time in seconds between messages (0 = no limit)
  cooldown: 3

  # Block messages containing these words (case-insensitive)
  anti-swear:
    enabled: true
    words: ["badword1", "badword2"]
    # What to do: "block" (cancel) or "replace" (replace with ***)
    action: replace
```

---

### 5.2 Economy Plugins
*(Balances, pay, baltop, shop, auction)*

**Systems needed:** `PluginConfig`, `EconomyManager`, `PlayerSession`, async DB  
**Primary key:** XUID (never player name)  
**Storage:** SQLite (small) or MySQL (large)

```php
// EconomyManager — all balance logic lives here
final class EconomyManager {

    /** @var array<string, float> In-memory balance cache: xuid → balance */
    private array $balanceCache = [];

    public function getBalance(Player $player): float {
        return $this->balanceCache[$player->getXuid()] ?? $this->config->getStartingBalance();
    }

    public function canAfford(Player $player, float $amount): bool {
        return $this->getBalance($player) >= $amount;
    }

    public function addBalance(Player $player, float $amount): void {
        $this->balanceCache[$player->getXuid()] = $this->getBalance($player) + $amount;
        $this->persistBalance($player);
    }

    public function deductBalance(Player $player, float $amount): bool {
        if (!$this->canAfford($player, $amount)) return false;
        $this->balanceCache[$player->getXuid()] = $this->getBalance($player) - $amount;
        $this->persistBalance($player);
        return true;
    }

    public function transfer(Player $from, Player $to, float $amount): bool {
        if (!$this->deductBalance($from, $amount)) return false;
        $this->addBalance($to, $amount);
        return true;
    }

    public static function format(float $balance): string {
        return "$" . number_format($balance, 2);
    }

    private function persistBalance(Player $player): void {
        // Submit async DB task
    }
}
```

**config.yml additions for economy plugins:**
```yaml
economy:
  # Starting balance for new players.
  starting-balance: 500.0

  # Currency symbol shown in messages.
  currency-symbol: "$"

  # Maximum balance a player can hold. 0 = no limit.
  max-balance: 0

  # Minimum amount for /pay transfers.
  min-transfer: 0.01

  # Charge a fee on /pay transfers (percentage, 0 = no fee).
  transfer-fee-percent: 0
```

---

### 5.3 PvP / Combat Plugins
*(Combat tags, kill streaks, bounties, knockback, anti-combat-log)*

**Systems needed:** `PluginConfig`, `CombatManager`, per-player `CombatSession` via `WeakMap`  
**Events:** `EntityDamageByEntityEvent`, `PlayerDeathEvent`, `PlayerQuitEvent`, `EntityMotionEvent`

```php
// CombatSession — lightweight per-player combat state
final class CombatSession {
    private bool   $inCombat        = false;
    private float  $combatUntil     = 0.0;
    private ?string $opponentXuid   = null;
    private int    $killStreak      = 0;

    public function tag(string $opponentXuid, float $durationSeconds): void {
        $this->inCombat      = true;
        $this->combatUntil   = microtime(true) + $durationSeconds;
        $this->opponentXuid  = $opponentXuid;
    }

    public function isInCombat(): bool {
        if ($this->inCombat && microtime(true) >= $this->combatUntil) {
            $this->inCombat = false;
        }
        return $this->inCombat;
    }

    public function clearCombat(): void { $this->inCombat = false; $this->opponentXuid = null; }
    public function getOpponentXuid(): ?string { return $this->opponentXuid; }
    public function getRemainingSeconds(): float { return max(0.0, $this->combatUntil - microtime(true)); }

    public function addKill(): void  { $this->killStreak++; }
    public function resetKills(): void { $this->killStreak = 0; }
    public function getKillStreak(): int { return $this->killStreak; }
}
```

**Precise Knockback (two-step EntityMotionEvent pattern):**
```php
// Two public flags on the session — signals for EntityMotionEvent
public bool $pendingCustomKb = false;
public bool $cancelNextMotion = false;

// In EventHandler::handleDamageByEntity — after routing:
$event->setKnockBack(0.0);                                 // Null PM's built-in
$event->setAttackCooldown($kit->getAttackCooldown());      // Custom ticks
$session->applyKnockback($damager, $hKB, $vKB);           // Set our KB
// ↑ sets pendingCustomKb = true

// In EventHandler::handleMotion:
if ($session->pendingCustomKb) {
    $session->pendingCustomKb  = false;
    $session->cancelNextMotion = true;
} elseif ($session->cancelNextMotion) {
    $session->cancelNextMotion = false;
    $event->cancel(); // Cancel PM's second motion update
}
```

**config.yml additions for PvP plugins:**
```yaml
pvp:
  combat-tag:
    # Seconds a player remains tagged after hitting/being hit.
    duration: 15
    # What happens when a tagged player disconnects: "kill" or "nothing"
    log-punishment: kill

  knockback:
    horizontal: 0.4
    vertical: 0.4
    # Lock vertical KB when player is above this height difference from attacker.
    max-height: 1.5

  kill-streaks:
    enabled: true
    # Announce globally at these streak milestones.
    announce-at: [5, 10, 15, 25, 50, 100]
    announce-format: "&6[Streak] &e{player} &7is on a &c{streak} &7kill streak!"
```

---

### 5.4 Minigame / Arena Plugins
*(Spleef, Skywars, Bedwars, Murder, any area-based game)*

**Systems needed:** `PluginConfig`, `ArenaFactory`, `Arena` (state machine), `SessionFactory`, `Session`, `Kit`/`KitFactory`  
**Events:** `EntityDamageEvent`, `BlockBreakEvent`, `BlockPlaceEvent`, `PlayerDropItemEvent`, `PlayerQuitEvent`

**State Machine (use PHP enum):**
```php
enum ArenaState: int {
    case WAITING   = 0;
    case STARTING  = 1;
    case IN_GAME   = 2;
    case ENDING    = 3;
    case RESETTING = 4;
}
```

**Update Loop via ArenaFactory:**
```php
// ArenaFactory::task() spins up a 20-tick repeating loop
// that calls $arena->update() on every arena instance.
// Each arena advances its own state machine inside update().
```

**Arena handles events dispatched from EventHandler:**
```php
class Arena {
    // EventHandler routes to these:
    public function handleDamage(EntityDamageEvent $event): void {}
    public function handleBreak(BlockBreakEvent $event): void {}
    public function handlePlace(BlockPlaceEvent $event): void {}
    public function handleMove(PlayerMoveEvent $event): void {}
    public function handleConsume(PlayerItemConsumeEvent $event): void {}

    // Always save+restore player inventory:
    private function savePlayerState(Player $player): void { /* store inv+armor */ }
    private function restorePlayerState(Player $player): void { /* restore inv+armor */ }

    // AABB zone protection
    private function isInProtectedZone(Vector3 $pos): bool {
        foreach ($this->protectedZones as $zone) {
            if ($zone->isVectorInside($pos)) return true;
        }
        return false;
    }
}
```

**config.yml additions for minigame plugins:**
```yaml
arenas:
  # Minimum players required to start a countdown.
  min-players: 2

  # Maximum players per arena.
  max-players: 8

  # Countdown from this number before the game starts (seconds).
  countdown: 10

  # Seconds shown on the END screen before resetting.
  end-duration: 5

  # Whether players keep their items when eliminated (spectator mode).
  spectate-on-eliminate: true
```

---

### 5.5 Duel / 1v1 / Matchmaking Plugins
*(Ranked/unranked duels, queue system, ELO, kit selection)*

**Systems needed:** `PluginConfig`, `SessionFactory` + `Session`, `DuelFactory` + `Duel` (polymorphic types), `QueueFactory`, `KitFactory`, `WorldManager` (world copy/delete), async DB for ELO

**Duel Type Polymorphism:**
- Define a base `Duel` class with shared logic
- Each game mode extends `Duel` and overrides ONLY what is different:
  ```
  Duel (base)
    ├── Boxing.php      → Override handleDamage: no kill, count hits to 100
    ├── Sumo.php        → Override handleMove: push off platform = eliminate
    ├── NoDebuff.php    → Override handleConsume: allow potion drinking
    └── Bridge.php      → Override handlePlace+handleBreak: goal scoring
  ```

**ELO Formula (K=32):**
```php
public static function calculateElo(int $winnerElo, int $loserElo): array {
    $expected = 1 / (1 + pow(10, ($loserElo - $winnerElo) / 400));
    return [
        'gain' => (int) round(32 * (1 - $expected)),
        'loss' => (int) round(32 * $expected),
    ];
}
```

**Queue Matchmaking:**
```php
final class QueueManager {
    /** @var QueueEntry[] indexed by xuid */
    private static array $queue = [];

    public static function join(Player $player, string $gameType, bool $ranked): void {
        foreach (self::$queue as $xuid => $entry) {
            if ($entry->matches($gameType, $ranked) && $xuid !== $player->getXuid()) {
                self::remove($xuid);
                DuelFactory::create(SessionFactory::get($player), $entry->getSession(), $gameType, $ranked);
                return;
            }
        }
        self::$queue[$player->getXuid()] = new QueueEntry(SessionFactory::get($player), $gameType, $ranked);
    }

    public static function remove(string $xuid): void { unset(self::$queue[$xuid]); }
}
```

**config.yml additions for duel plugins:**
```yaml
duels:
  # Starting ELO for new players.
  starting-elo: 1000

  # The ELO K-factor (higher = bigger ELO swings per match, standard is 32).
  elo-k-factor: 32

  # Countdown before a duel starts (seconds).
  starting-countdown: 5

  # Seconds after a duel ends before players are teleported back.
  ending-duration: 5

  # Available ranked game types. Players can queue for these.
  ranked-types: [nodebuff, boxing, sumo, gapple]

  # Available unranked game types.
  unranked-types: [nodebuff, boxing, sumo, gapple, bridge, bedfight]
```

---

### 5.6 Clan / Guild / Faction Plugins
*(Create, invite, war, territory, members)*

**Systems needed:** `PluginConfig`, `ClanFactory`, `Clan`, `Session`, async DB  
**Events:** `PlayerJoinEvent`, `PlayerQuitEvent`, `EntityDamageByEntityEvent` (friendly fire)

```php
final class Clan {
    public function __construct(
        private string $name,
        private string $tag,
        private string $ownerXuid,
        /** @var string[] member XUIDs */
        private array  $members = [],
        private int    $createdAt = 0,
    ) {}

    public function isMember(Player $player): bool {
        return in_array($player->getXuid(), $this->members, true);
    }

    public function isOwner(Player $player): bool {
        return $this->ownerXuid === $player->getXuid();
    }

    public function addMember(string $xuid): void  { $this->members[] = $xuid; }
    public function removeMember(string $xuid): void {
        $this->members = array_filter($this->members, fn($x) => $x !== $xuid);
    }

    public function getMemberCount(): int { return count($this->members); }
}
```

**config.yml additions for clan plugins:**
```yaml
clans:
  # Maximum characters in a clan name.
  max-name-length: 20

  # Maximum characters in a clan tag (shown in chat, e.g. [TAG]).
  max-tag-length: 5

  # Maximum members per clan.
  max-members: 20

  # Can clan members damage each other? (Friendly fire)
  friendly-fire: false

  # Cost to create a clan (requires economy plugin). 0 = free.
  creation-cost: 0
```

---

### 5.7 Admin / Staff Plugins
*(Vanish, freeze, staff mode, spy, ban system, reports)*

**Systems needed:** `PluginConfig`, single `Listener`, `StaffManager`, per-player `StaffSession`  
**Events:** `PlayerMoveEvent` (freeze), `PlayerLoginEvent` (vanish re-apply), `PlayerChatEvent` (spy)

```php
// StaffManager — holds all active staff states
final class StaffManager {
    /** @var \WeakMap<Player, StaffSession> */
    private \WeakMap $sessions;

    public function __construct() { $this->sessions = new \WeakMap(); }

    public function getSession(Player $player): StaffSession {
        return $this->sessions[$player] ??= new StaffSession($player);
    }

    public function cleanSession(Player $player): void { unset($this->sessions[$player]); }
}

// StaffSession — per-player staff state
final class StaffSession {
    private bool $staffMode  = false;
    private bool $vanished   = false;
    private bool $frozen     = false;
    private bool $spyEnabled = false;

    // Saved state for toggling staff mode on/off
    private array $savedInventory    = [];
    private array $savedArmorInventory = [];

    public function isStaffMode(): bool { return $this->staffMode; }
    public function isVanished(): bool  { return $this->vanished; }
    public function isFrozen(): bool    { return $this->frozen; }
    public function isSpying(): bool    { return $this->spyEnabled; }

    public function toggleStaffMode(Player $player): void { /* save/restore state */ }
    public function toggleVanish(Player $player): void    { /* setInvisible + packet */ }
    public function toggleFreeze(): void { $this->frozen = !$this->frozen; }
}
```

**Freeze implementation:**
```php
public function onPlayerMove(PlayerMoveEvent $event): void {
    $player  = $event->getPlayer();
    $session = $this->staffManager->getSession($player);
    if ($session->isFrozen()) {
        $event->cancel(); // Locks position while frozen
    }
}
```

**Vanish (hide from non-staff on join):**
```php
public function onPlayerJoin(PlayerJoinEvent $event): void {
    $newPlayer = $event->getPlayer();
    foreach (Server::getInstance()->getOnlinePlayers() as $online) {
        $session = $this->staffManager->getSession($online);
        if ($session->isVanished() && !$newPlayer->hasPermission('staffplugin.see-vanish')) {
            $newPlayer->hidePlayer($online);
        }
    }
}
```

**config.yml additions for staff plugins:**
```yaml
staff:
  vanish:
    # Can vanished staff still be seen by other staff?
    visible-to-staff: true

    # Announce to staff when a player vanishes/unvanishes.
    announce-to-staff: true

  freeze:
    # Message shown to the frozen player every N seconds.
    remind-interval: 10
    remind-message: "&cYou are frozen by a staff member."

  staffmode:
    # Items given to staff in staff mode (slot: item_name).
    items:
      0: compass        # Teleport menu
      4: chest          # Inventory inspection
      8: nether_star    # Settings

  spy:
    # Format for staff chat spy. {player} = sender, {message} = message
    format: "&8[SPY] &7{player}: &f{message}"
```

---

### 5.8 World Management / Region Plugins
*(Warps, homes, regions, world guard, spawn protection)*

**Systems needed:** `PluginConfig`, `RegionManager`, `WarpManager`, async YAML/DB  
**Events:** `BlockBreakEvent`, `BlockPlaceEvent`, `PlayerMoveEvent` (for enter/leave triggers)

```php
// Region — immutable data object + AABB queries
final class Region {
    public function __construct(
        private readonly string  $name,
        private readonly string  $worldName,
        private readonly Vector3 $min,
        private readonly Vector3 $max,
        private readonly array   $flags = [], // e.g. ['no-pvp' => true, 'no-break' => true]
    ) {}

    public function contains(Position $pos): bool {
        if ($pos->getWorld()->getFolderName() !== $this->worldName) return false;
        return $pos->getX() >= $this->min->getX() && $pos->getX() <= $this->max->getX()
            && $pos->getY() >= $this->min->getY() && $pos->getY() <= $this->max->getY()
            && $pos->getZ() >= $this->min->getZ() && $pos->getZ() <= $this->max->getZ();
    }

    public function hasFlag(string $flag): bool { return ($this->flags[$flag] ?? false) === true; }

    public static function fromStorageArray(string $name, array $data): self { /* deserialization */ }
    public function toStorageArray(): array { /* serialization */ }
}
```

**Position serialization (Utils):**
```php
public static function posToArray(Position $pos): array {
    return ['x' => $pos->getX(), 'y' => $pos->getY(), 'z' => $pos->getZ(), 'world' => $pos->getWorld()->getFolderName()];
}

public static function arrayToPosition(array $data): ?Position {
    $world = Server::getInstance()->getWorldManager()->getWorldByName($data['world'] ?? '');
    if ($world === null) return null;
    return new Position((float)$data['x'], (float)$data['y'], (float)$data['z'], $world);
}
```

**config.yml additions for world plugins:**
```yaml
worlds:
  # Default flags applied to all regions unless overridden.
  default-flags:
    no-pvp: false
    no-break: false
    no-place: false
    no-drop: false

  # Whether to show entry/exit messages when a player enters a region.
  show-region-messages: true

warps:
  # Maximum number of warps a player can create (0 = unlimited, requires permission).
  max-per-player: 0

homes:
  # Maximum number of homes a player can set.
  max-homes: 3
  # Maximum homes for VIP players (permission: plugin.vip).
  max-homes-vip: 10
```

---

### 5.9 Utility / Misc Plugins
*(Kits, crates, voting rewards, custom join messages, MOTD, ping display)*

**Systems needed:** `PluginConfig`, lightweight `Manager`, optional `Listener`  
**Typical anti-patterns to avoid:** monolithic single-class plugins with hundreds of lines in `Main.php`

For any utility plugin, still create a dedicated `Manager` class even if it's small:
```php
// KitManager — even for a simple kit plugin, logic belongs here, not in Main.php
final class KitManager {
    /** @var Kit[] indexed by name */
    private array $kits = [];

    public function getKit(string $name): ?Kit { return $this->kits[strtolower($name)] ?? null; }
    public function getAllKits(): array { return $this->kits; }

    public function giveKit(Player $player, Kit $kit): bool {
        if (!$player->hasPermission("kits.kit." . strtolower($kit->getName()))) {
            return false;
        }
        if ($this->cooldownManager->isOnCooldown($player, $kit)) {
            return false;
        }
        $kit->giveTo($player);
        $this->cooldownManager->setCooldown($player, $kit);
        return true;
    }
}
```

---

## Step 6 — The Factory Pattern (All Complex Plugins)

Every entity collection gets its own `*Factory` class:

```php
/**
 * Template for any Factory class.
 * Replace "Arena" with the actual type (Duel, Clan, Region, Kit, etc.)
 */
final class ArenaFactory {

    /** @var Arena[] indexed by string name or int id */
    private static array $arenas = [];

    /** Creates a new instance and stores it. */
    public static function create(/* required args */): void {
        /* build the Arena object, call loadAll-style setup */
    }

    public static function get(string $name): ?Arena {
        return self::$arenas[strtolower($name)] ?? null;
    }

    public static function remove(string $name): void {
        unset(self::$arenas[strtolower($name)]);
    }

    /** @return Arena[] */
    public static function getAll(): array { return self::$arenas; }

    /** Register the update loop once from Main::onEnable(). */
    public static function task(Main $plugin): void {
        $plugin->getScheduler()->scheduleRepeatingTask(
            new ClosureTask(static fn() => array_walk(self::$arenas, fn(Arena $a) => $a->update())),
            20
        );
    }

    /** Load all arenas from storage at startup. */
    public static function loadAll(Main $plugin): void { /* read YAML/DB, call create() */ }

    /** Save all arenas to storage at shutdown. */
    public static function saveAll(Main $plugin): void { /* serialize, write YAML/DB */ }

    /** Called from Main::onDisable — clean up all running instances. */
    public static function disable(): void {
        foreach (self::$arenas as $arena) $arena->shutdown();
        self::$arenas = [];
    }
}
```

---

## Step 7 — The Session Pattern (Complex Plugins Only)

When a plugin needs per-player state across multiple subsystems, use `Session` + `SessionFactory`:

```php
// Session — stores xuid/uuid/name ONLY; recovers Player via Server
final class Session {
    use PlayerDataTrait;    // Stats, ELO, DB sync (only if plugin needs stats)
    use SettingTrait;       // Toggle settings (only if plugin has player settings)
    use ScoreboardTrait;    // Scoreboard builder (only if plugin has scoreboards)

    public function __construct(
        private string  $uuid,   // Raw UUID bytes
        private string  $xuid,   // XUID string (primary DB key)
        private string  $name,   // Current display name
        // Context references — only include ones relevant to the plugin:
        private ?Arena  $arena  = null,
        private ?object $game   = null,  // Whatever the plugin calls its game object
    ) {
        // Init trait data
        $this->initData();
    }

    /** Recover the live Player object safely. */
    public function getPlayer(): ?Player {
        return Server::getInstance()->getPlayerByRawUUID($this->uuid);
    }

    /** True when player is not in any game context. */
    public function isIdle(): bool {
        return $this->arena === null && $this->game === null;
    }

    /** Called on PlayerLoginEvent. */
    public function onJoin(): void { /* setup scoreboard, give lobby items */ }

    /** Called on PlayerQuitEvent. */
    public function onQuit(): void { /* delegate cleanup to active context, clear refs */ }

    /** Called every tick from SessionFactory::task(). */
    public function update(): void { /* scoreboard update, cooldown timers, etc. */ }

    /** Called on plugin disable — flush all data to DB. */
    public function persist(): void { $this->updateData(); }
}
```

```php
// SessionFactory — manages all active sessions
final class SessionFactory {

    /** @var array<string, Session> indexed by xuid */
    private static array $sessions = [];

    public static function create(Player $player): void {
        self::$sessions[$player->getXuid()] = new Session(
            $player->getUniqueId()->getBytes(),
            $player->getXuid(),
            $player->getName()
        );
    }

    public static function get(Player|string $player): ?Session {
        $xuid = $player instanceof Player ? $player->getXuid() : $player;
        return self::$sessions[$xuid] ?? null;
    }

    public static function remove(string $xuid): void { unset(self::$sessions[$xuid]); }

    public static function getAll(): array { return self::$sessions; }

    public static function task(Main $plugin): void {
        $plugin->getScheduler()->scheduleRepeatingTask(
            new ClosureTask(static fn() => array_walk(self::$sessions, fn(Session $s) => $s->update())),
            1  // Every tick for responsive scoreboards/cooldowns
        );
    }

    public static function saveAll(): void {
        array_walk(self::$sessions, fn(Session $s) => $s->persist());
    }

    public static function loadAll(): void {}  // Data loads async per-player on join
}
```

---

## Step 8 — The EventHandler Dispatcher (Complex Plugins)

For complex plugins, use ONE `EventHandler` that routes events to the correct context:

```php
final class EventHandler implements Listener {

    // RULE: EventHandler only routes — it never contains business logic.
    // Pattern: get session → null check → check context → delegate to context handler

    public function onDamage(EntityDamageEvent $event): void {
        // 1. Universal guards
        if ($event instanceof EntityDamageByBlockEvent) { $event->cancel(); return; }

        $player  = $event->getEntity();
        if (!$player instanceof Player) return;

        $session = SessionFactory::get($player);
        if ($session === null) return;

        // 2. Context routing — adapt to your plugin's contexts
        if ($session->isIdle()) {
            $event->cancel(); // Protect lobby
        } elseif (!$session->isIdle()) {
            $session->getArena()?->handleDamage($event);
        }

        // 3. Cross-cutting post-processing
        if (!$event->isCancelled() && $event instanceof EntityDamageByEntityEvent) {
            // e.g. custom knockback, hit particles, CPS tracking
        }
    }

    // All event methods follow the same pattern:
    // onBreak, onPlace, onMove, onChat, onInteract, onConsume, onDrop, onQuit, onJoin, onLogin
}
```

**For simple/medium plugins:** Skip the dispatcher — just use a normal listener class with direct logic.

---

## Step 9 — Main Class (Always Thin Bootstrap)

The Main class name matches the plugin name. It **only** bootstraps:

```php
final class MyPlugin extends PluginBase {

    private static self $instance;
    private PluginConfig $pluginConfig;

    // Only add managers/factories that this specific plugin actually needs:
    // private EconomyManager $economy;
    // private StaffManager $staff;

    public static function getInstance(): self { return self::$instance; }

    protected function onLoad(): void {
        self::$instance = $this;

        // Save default resource files
        $this->saveDefaultConfig();
        // $this->saveResource('kits.yml');
        // $this->saveResource('messages.yml');

        // Register custom entities HERE (must be onLoad, not onEnable)
        // $this->registerEntities();
    }

    protected function onEnable(): void {
        $this->pluginConfig = new PluginConfig($this);

        // Initialize managers in dependency order
        // $this->economy = new EconomyManager($this->pluginConfig);

        // Load data from storage
        // ArenaFactory::loadAll($this);
        // SessionFactory::loadAll();

        // Register events
        $this->getServer()->getPluginManager()->registerEvents(
            new EventListener($this /*, managers... */),
            $this
        );

        // Register commands
        $this->registerCommands();

        $this->getLogger()->info(TextFormat::GREEN . $this->getName() . " v" . $this->getDescription()->getVersion() . " enabled.");
    }

    protected function onDisable(): void {
        // Shutdown in reverse init order
        // ArenaFactory::disable();
        // SessionFactory::saveAll();

        $this->getLogger()->info(TextFormat::RED . $this->getName() . " disabled.");
    }

    private function registerCommands(): void {
        $this->getServer()->getCommandMap()->register(
            strtolower($this->getName()),
            new MyCommand($this, $this->pluginConfig /*, managers... */)
        );
    }

    public function getPluginConfig(): PluginConfig { return $this->pluginConfig; }
    // Expose managers as getters: getEconomy(), getStaff(), etc.
}
```

---

## Step 10 — Key API Reference (PM5 / 5.42.x)

### Player
```php
$player->getName();              // Display name
$player->getXuid();              // XUID — USE THIS as DB primary key
$player->getUniqueId()->getBytes(); // UUID bytes — use for in-memory session lookup
$player->isOnline();             // Always check before acting in delayed tasks
$player->isAlive();
$player->sendMessage(string);
$player->sendTitle(string, string, int $fadeIn, int $stay, int $fadeOut);
$player->sendActionBarMessage(string);
$player->sendPopup(string);
$player->sendTip(string);
$player->setHealth(float); $player->getHealth();
$player->setGamemode(GameMode::SURVIVAL);  // GameMode enum
$player->teleport(Position|Location);
$player->hasPermission(string);
$player->kick(string $reason);
$player->setNameTag(string); $player->setScoreTag(string);
$player->setInvisible(bool); $player->hidePlayer(Player); $player->showPlayer(Player);
$player->getInventory();       // PlayerInventory
$player->getArmorInventory();  // ArmorInventory
$player->getOffHandInventory();
$player->getCursorInventory();
$player->getEffects();         // EffectManager — add(), remove(), clear()
$player->getXpManager();       // setXpAndProgress(int level, float 0.0–1.0)
$player->getHungerManager();   // setFood(float)
$player->getNetworkSession()->getPing();
$player->getNetworkSession()->sendDataPacket($packet);
```

### World
```php
$server->getWorldManager()->getWorldByName(string): ?World;
$server->getWorldManager()->loadWorld(string): bool;
$server->getWorldManager()->getDefaultWorld(): ?World;
$world->getBlockAt(int x, int y, int z): Block;
$world->setBlock(Vector3, Block, bool $update = true): void;
$world->getPlayers(): Player[];
$world->getEntities(): Entity[];
$world->setTime(int); $world->stopTime(); $world->startTime();
$world->addParticle(Vector3, Particle, ?Player[]);
$world->addSound(Vector3, Sound, ?Player[]);
$world->dropItem(Vector3, Item): ItemEntity;
Position::fromObject(Vector3 $vec, World $world): Position;
```

### Items
```php
VanillaItems::DIAMOND_SWORD()->setCustomName("§bKit Sword")->setCount(1);
$item->setLore(["§7Line 1", "§7Line 2"]);
$item->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SHARPNESS(), 5));
$item->getNamedTag()->setString("plugin_tag", "value"); // Custom NBT
StringToItemParser::getInstance()->parse("diamond_sword"); // From string (config)
```

### Scheduler
```php
// One-off delayed
$this->getScheduler()->scheduleDelayedTask(new ClosureTask(fn() => doSomething()), 20);
// Repeating (save the handler to cancel it later)
$handler = $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(fn() => tick()), 20);
$handler->cancel();
// Async (worker thread — NO Server/World/Player access)
Server::getInstance()->getAsyncPool()->submitTask(new MyAsyncTask());
```

### Effects
```php
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\effect\EffectInstance;

$player->getEffects()->add(new EffectInstance(VanillaEffects::SPEED(), 200, 1, false));
// duration=200 ticks = 10s, amplifier=1 = level II, visible=false
$player->getEffects()->remove(VanillaEffects::SPEED());
$player->getEffects()->clear();
```

### TextFormat / Colors
```php
// In code:
TextFormat::colorize("&aGreen &cRed &eYellow &bAqua");
// Reference:
// §0 Black  §1 DarkBlue §2 DarkGreen §3 DarkAqua §4 DarkRed §5 DarkPurple §6 Gold §7 Gray
// §8 DkGray §9 Blue     §a Green     §b Aqua     §c Red     §d Pink       §e Yellow §f White
// §l Bold   §o Italic   §n Underline §m Strike   §r Reset
```

---

## Step 11 — Events Reference

### Player Events
| Event | When to hook |
|-------|-------------|
| `PlayerLoginEvent` | Session creation, ban checks, whitelist |
| `PlayerJoinEvent` | First-join welcome, session setup, scoreboard spawn |
| `PlayerQuitEvent` | Context cleanup, session save, combat-log |
| `PlayerDeathEvent` | Kill tracking, drop loot, respawn setup |
| `PlayerRespawnEvent` | Custom respawn location, re-give items |
| `PlayerMoveEvent` | Freeze, region triggers, boundary checks — **keep VERY light** |
| `PlayerChatEvent` | Format, filter, rate-limit, channels |
| `PlayerCommandPreprocessEvent` | Command logging, aliases |
| `PlayerInteractEvent` | NPC clicks, sign actions, item actions, setup wizards |
| `PlayerItemUseEvent` | Custom item air-click (add `@handleCancelled` for spectators) |
| `PlayerItemConsumeEvent` | Custom potion/food effects |
| `PlayerDropItemEvent` | Prevent drops during game modes |
| `PlayerExhaustEvent` | Disable hunger drain |

### Entity / Combat Events
| Event | When to hook |
|-------|-------------|
| `EntityDamageEvent` | Universal damage routing |
| `EntityDamageByEntityEvent` | PvP: KB, CPS, hit particles, combat tag |
| `EntityDamageByChildEntityEvent` | Projectile damage (self-hit prevention) |
| `EntityDamageByBlockEvent` | Cancel block damage globally |
| `EntityMotionEvent` | Precise knockback (two-step flag pattern) |
| `EntityRegainHealthEvent` | Cancel saturation regen |
| `EntityItemPickupEvent` | Cancel projectile-bounce pickup |

### Block / World Events
| Event | When to hook |
|-------|-------------|
| `BlockBreakEvent` | Region protection, game mode restrictions |
| `BlockPlaceEvent` | Region protection, AABB zone guard |
| `BlockSpreadEvent` | Allow or deny fire spread |
| `LeavesDecayEvent` | Disable decay |
| `EntityTrampleFarmlandEvent` | Protect lobby farmland |

### Inventory / Packet Events
| Event | When to hook |
|-------|-------------|
| `InventoryTransactionEvent` | Block moving tagged plugin items; kit-edit pass-through |
| `CraftItemEvent` | Disable crafting during games |
| `DataPacketReceiveEvent` | CPS counter, custom swing animation sync |
| `DataPacketSendEvent` | Suppress attack sound packets |

---

## Step 12 — Code Quality Standards (Non-Negotiable)

```
✅ declare(strict_types=1) in EVERY file
✅ All properties and method signatures have type declarations
✅ Constructor property promotion (private readonly where immutable)
✅ match expressions instead of switch/case where applicable
✅ Null-safe operator ?-> for chained nullable calls
✅ Full use-import per class — no wildcard imports
✅ DocBlocks on all public/protected methods
✅ XUID as primary key everywhere (never player name)
✅ isOnline() + isAlive() checked in all delayed task callbacks
✅ AsyncTask::onRun() — zero Server/World/Player access
✅ WeakMap or XUID strings for long-lived player references

❌ Player objects stored in arrays that outlive the event scope
❌ Business logic in Main.php
❌ api: "5.0.0" as plain string — must be ["5.0.0"]
❌ Database queries on the main thread
❌ Empty catch blocks
❌ var_dump / print_r left in code
❌ Wildcard imports (use pocketmine\*)
❌ Custom entities registered in onEnable() — must be onLoad()
❌ PlayerMoveEvent with heavy computation (DB calls, loops)
❌ World objects stored as long-lived class properties
❌ Stub/placeholder code — all generated code must be complete and runnable
```

---

## Step 13 — Output Order (Always Follow)

1. `plugin.yml`
2. `resources/config.yml` (full, with comments — always)
3. `src/.../PluginConfig.php` (type-safe config wrapper — always)
4. `src/.../Main.php`
5. `src/.../EventHandler.php` or `listener/EventListener.php`
6. Session + SessionFactory (if tier 3)
7. Trait files (if tier 3)
8. Core subsystem classes + factories
9. Database layer (if async DB used)
10. Command classes
11. Form classes (if forms used)
12. Item classes (if custom items)
13. Entity classes (if custom entities)
14. `src/.../utils/Utils.php`

---

## Step 14 — Final Review Checklist

**Manifest:**
- [ ] `api: ["5.0.0"]` — array syntax
- [ ] All permissions have `default` values
- [ ] Plugin main class name matches plugin name (not "Practice" or brand name)

**Config:**
- [ ] `resources/config.yml` generated with comments on every key
- [ ] `PluginConfig.php` generated with typed getters for every key
- [ ] No other class calls `getConfig()` directly — only `PluginConfig`
- [ ] Config hot-reloads via `/pluginname reload` command

**Architecture:**
- [ ] Main.php only bootstraps — zero business logic
- [ ] Correct tier selected (Simple / Medium / Complex)
- [ ] Managers exist — no logic in listeners or commands
- [ ] Session stores uuid/xuid/name — never a Player object
- [ ] Factories provide: create / get / remove / getAll / loadAll / saveAll / task / disable

**Safety:**
- [ ] No Player objects in long-lived arrays
- [ ] `SessionFactory::get()` results null-checked
- [ ] `isOnline()` checked in delayed task callbacks
- [ ] `AsyncTask::onRun()` has no Server/World/Player access
- [ ] XUID used as primary DB key everywhere

**Quality:**
- [ ] `declare(strict_types=1)` in every file
- [ ] No wildcard imports
- [ ] No empty catch blocks
- [ ] No debug code left in
- [ ] All generated code is complete — no stubs, no TODOs left as output

---

## Adaptive Architecture Summary

```
User asks for...              → Tier → What to generate
──────────────────────────────────────────────────────────────
Simple chat formatter         → 1   → Main + PluginConfig + Listener
Simple spawn teleport         → 1   → Main + PluginConfig + Command
Economy with /pay, /bal       → 2   → + EconomyManager + Session + async DB
Warps / Homes                 → 2   → + RegionManager + YAML storage
PvP combat tags               → 2   → + CombatManager + WeakMap sessions
Kit plugin with cooldowns     → 2   → + KitManager + KitFactory + cooldowns
Staff mode / vanish / freeze  → 2   → + StaffManager + StaffSession
Clan / guild system           → 2-3 → + ClanFactory + Clan + DB + Session
Minigame (Spleef, etc.)       → 3   → + ArenaFactory + Arena (state machine) + Kit + Session
Duel / 1v1 matchmaking        → 3   → + DuelFactory + Duel base + type subclasses + Queue + ELO + WorldCopy
Full practice server plugin   → 3   → All systems combined
```
