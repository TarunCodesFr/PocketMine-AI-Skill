#!/usr/bin/env php
<?php

/**
 * scaffold.php — PocketMine Plugin Scaffolder
 *
 * Instantly generates a plugin folder structure with all boilerplate.
 *
 * Usage:
 *   php scripts/scaffold.php
 *
 * Interactive prompts will ask for:
 *   - Plugin name
 *   - Author name
 *   - Description
 *   - Complexity tier (1=Simple, 2=Medium, 3=Complex)
 *
 * Output: ./generated/<PluginName>/ ready to zip and drop into a server.
 */

declare(strict_types=1);

// ─── Helpers ────────────────────────────────────────────────────────────────

function prompt(string $question, string $default = ''): string {
    $hint = $default !== '' ? " [$default]" : '';
    echo $question . $hint . ': ';
    $input = trim((string) fgets(STDIN));
    return $input !== '' ? $input : $default;
}

function promptInt(string $question, int $default = 1): int {
    $raw = prompt($question, (string) $default);
    return is_numeric($raw) ? (int) $raw : $default;
}

function makeDir(string $path): void {
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
        echo "  📁 $path\n";
    }
}

function writeFile(string $path, string $content): void {
    file_put_contents($path, $content);
    echo "  📄 $path\n";
}

function toPascalCase(string $name): string {
    return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));
}

function toLower(string $name): string {
    return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $name));
}

// ─── Input ──────────────────────────────────────────────────────────────────

echo "\n";
echo "╔══════════════════════════════════════════╗\n";
echo "║  PocketMine Plugin Scaffolder            ║\n";
echo "║  Skill: pocketmine-plugin-dev            ║\n";
echo "╚══════════════════════════════════════════╝\n\n";

$pluginRaw   = prompt("Plugin name (e.g. EconomyPlus, SpleefArena, ChatFormat)");
$pluginName  = toPascalCase($pluginRaw);
$pluginLower = toLower($pluginName);

if ($pluginName === '') {
    echo "Error: plugin name is required.\n";
    exit(1);
}

$author      = prompt("Author name", "AuthorName");
$description = prompt("Description", "A PocketMine-MP plugin.");
$version     = prompt("Version", "1.0.0");

echo "\nComplexity tier:\n";
echo "  1 = Simple   (single feature, 1 listener, minimal structure)\n";
echo "  2 = Medium   (multiple features, manager class, YAML/SQLite storage)\n";
echo "  3 = Complex  (multiple subsystems, factories, sessions, MySQL)\n";
$tier = promptInt("Choose tier [1/2/3]", 1);
$tier = max(1, min(3, $tier));

$outDir = __DIR__ . '/../generated/' . $pluginName;
echo "\nGenerating into: $outDir\n\n";

// ─── Directories ─────────────────────────────────────────────────────────────

$ns   = "$author\\$pluginName";
$src  = "$outDir/src/$author/$pluginName";

makeDir("$outDir/resources");
makeDir("$src/command");
makeDir("$src/listener");
makeDir("$src/utils");

if ($tier >= 2) {
    makeDir("$src/manager");
    makeDir("$src/session");
}

if ($tier === 3) {
    makeDir("$src/session/data");
    makeDir("$src/session/setting");
    makeDir("$src/session/scoreboard");
    makeDir("$src/factory");
    makeDir("$src/database/queries");
    makeDir("$src/form");
    makeDir("$src/item");
    makeDir("$src/entity");
    makeDir("$src/world/async");
}

// ─── plugin.yml ──────────────────────────────────────────────────────────────

$commandName = $pluginLower;
writeFile("$outDir/plugin.yml", <<<YAML
name: $pluginName
version: $version
main: $ns\\$pluginName
api: ["5.0.0"]
description: "$description"
author: $author

commands:
  $commandName:
    description: "Main command for $pluginName"
    usage: "/$commandName <subcommand>"
    aliases: []
    permission: $pluginLower.command

permissions:
  $pluginLower.command:
    description: "Access $pluginName commands"
    default: true
  $pluginLower.command.admin:
    description: "Access admin $pluginName commands"
    default: op
  $pluginLower.vip:
    description: "VIP perks in $pluginName"
    default: op
YAML);

// ─── resources/config.yml ────────────────────────────────────────────────────

writeFile("$outDir/resources/config.yml", <<<YAML
# =============================================================
# $pluginName Configuration  v$version
# Author: $author
# =============================================================

# ---- General -------------------------------------------------

# Prefix shown before plugin messages in chat.
# Color codes: & followed by a letter (e.g. &a = green, &c = red).
prefix: "&7[&b$pluginName&7] &r"

# Enable debug mode (extra console output). Disable on production servers.
debug: false


# ---- Messages ------------------------------------------------
# Placeholders: {prefix}, {player}, {amount}, {value}, {usage}
# Color codes: &a=green &c=red &e=yellow &b=aqua &7=gray &f=white

messages:
  no-permission: "{prefix}&cYou don't have permission to do that."
  player-only:   "{prefix}&cThis command can only be used in-game."
  reload:        "{prefix}&aConfiguration reloaded."
  usage:         "{prefix}&cUsage: &e{usage}"


# ---- Database ------------------------------------------------
# type: "yaml"  → stores data in plugin folder (no setup needed)
# type: "mysql" → requires a MySQL/MariaDB server

database:
  type: yaml

  # Only needed when type is "mysql":
  mysql:
    host: "127.0.0.1"
    port: 3306
    user: "root"
    password: ""
    database: "minecraft"


# ---- Permissions Overview ------------------------------------
# $pluginLower.command        → Basic commands    (default: all players)
# $pluginLower.command.admin  → Admin commands    (default: op)
# $pluginLower.vip            → VIP perks         (default: op)
# Assign via your permissions plugin (e.g. LuckPerms).
YAML);

// ─── PluginConfig.php ───────────────────────────────────────────────────────

writeFile("$src/PluginConfig.php", <<<PHP
<?php
declare(strict_types=1);

namespace $ns;

use pocketmine\\utils\\TextFormat;

/**
 * Type-safe wrapper around config.yml.
 * All config access goes through this class — never call getConfig() elsewhere.
 *
 * To add a new config key:
 * 1. Add it with a comment to resources/config.yml
 * 2. Add a typed getter method here
 * 3. Use the getter in your code
 */
final class PluginConfig {

    public function __construct(private readonly $pluginName \$plugin) {}

    /** Reload values from disk (called by /reload command). */
    public function reload(): void {
        \$this->plugin->reloadConfig();
    }

    // ── General ──────────────────────────────────────────────────────────────

    /** Colorized chat prefix ready for use in messages. */
    public function getPrefix(): string {
        return TextFormat::colorize((string) \$this->plugin->getConfig()->get('prefix', '&7[&b$pluginName&7] &r'));
    }

    public function isDebug(): bool {
        return (bool) \$this->plugin->getConfig()->get('debug', false);
    }

    // ── Messages ─────────────────────────────────────────────────────────────

    /**
     * Return a colorized message string from the messages section.
     *
     * @param string               \$key          Key under "messages" (e.g. "no-permission")
     * @param array<string,string> \$placeholders {placeholder} => replacement pairs
     */
    public function getMessage(string \$key, array \$placeholders = []): string {
        \$raw = (string) \$this->plugin->getConfig()->getNested("messages.\$key", "&cMissing message: \$key");
        \$msg = str_replace('{prefix}', \$this->getPrefix(), \$raw);
        foreach (\$placeholders as \$ph => \$value) {
            \$msg = str_replace('{' . \$ph . '}', \$value, \$msg);
        }
        return TextFormat::colorize(\$msg);
    }

    // ── Database ─────────────────────────────────────────────────────────────

    /** "yaml" or "mysql" */
    public function getDatabaseType(): string {
        \$type = strtolower((string) \$this->plugin->getConfig()->getNested('database.type', 'yaml'));
        return in_array(\$type, ['yaml', 'mysql'], true) ? \$type : 'yaml';
    }

    public function getMysqlHost(): string     { return (string) \$this->plugin->getConfig()->getNested('database.mysql.host', '127.0.0.1'); }
    public function getMysqlPort(): int        { return (int)    \$this->plugin->getConfig()->getNested('database.mysql.port', 3306); }
    public function getMysqlUser(): string     { return (string) \$this->plugin->getConfig()->getNested('database.mysql.user', 'root'); }
    public function getMysqlPassword(): string { return (string) \$this->plugin->getConfig()->getNested('database.mysql.password', ''); }
    public function getMysqlDatabase(): string { return (string) \$this->plugin->getConfig()->getNested('database.mysql.database', 'minecraft'); }

    // ── Debug Helper ─────────────────────────────────────────────────────────

    public function debug(string \$message): void {
        if (\$this->isDebug()) {
            \$this->plugin->getLogger()->debug("[DEBUG] \$message");
        }
    }
}
PHP);

// ─── Main.php ────────────────────────────────────────────────────────────────

$tierComment = match($tier) {
    1 => "// Tier 1 — Simple plugin",
    2 => "// Tier 2 — Medium plugin (manager pattern)",
    3 => "// Tier 3 — Complex plugin (factory + session + subsystems)",
};

writeFile("$src/$pluginName.php", <<<PHP
<?php
declare(strict_types=1);

namespace $ns;

use pocketmine\\plugin\\PluginBase;
use pocketmine\\utils\\TextFormat;
use $ns\\command\\{$pluginName}Command;
use $ns\\listener\\EventListener;

$tierComment
final class $pluginName extends PluginBase {

    private static self \$instance;
    private PluginConfig \$pluginConfig;

    public static function getInstance(): self {
        return self::\$instance;
    }

    protected function onLoad(): void {
        self::\$instance = \$this;
        \$this->saveDefaultConfig();
        // Register custom entities here (onLoad, NOT onEnable):
        // EntityFactory::getInstance()->register(...)
    }

    protected function onEnable(): void {
        \$this->pluginConfig = new PluginConfig(\$this);

        // Initialize managers / factories here
        // \$this->someManager = new SomeManager(\$this->pluginConfig);

        // Register event listener
        \$this->getServer()->getPluginManager()->registerEvents(
            new EventListener(\$this->pluginConfig),
            \$this
        );

        // Register commands
        \$this->getServer()->getCommandMap()->register(
            strtolower(\$this->getName()),
            new {$pluginName}Command(\$this, \$this->pluginConfig)
        );

        \$this->getLogger()->info(
            TextFormat::GREEN . \$this->getName() . " v" . \$this->getDescription()->getVersion() . " enabled!"
        );
    }

    protected function onDisable(): void {
        // Shutdown managers / save data here
        \$this->getLogger()->info(TextFormat::RED . \$this->getName() . " disabled.");
    }

    public function getPluginConfig(): PluginConfig {
        return \$this->pluginConfig;
    }
}
PHP);

// ─── EventListener.php ──────────────────────────────────────────────────────

writeFile("$src/listener/EventListener.php", <<<PHP
<?php
declare(strict_types=1);

namespace $ns\\listener;

use pocketmine\\event\\Listener;
use pocketmine\\event\\player\\PlayerJoinEvent;
use pocketmine\\event\\player\\PlayerQuitEvent;
use pocketmine\\event\\player\\PlayerChatEvent;
use $ns\\PluginConfig;

final class EventListener implements Listener {

    public function __construct(private readonly PluginConfig \$config) {}

    public function onPlayerJoin(PlayerJoinEvent \$event): void {
        \$player = \$event->getPlayer();
        // TODO: initialize player session / state
    }

    public function onPlayerQuit(PlayerQuitEvent \$event): void {
        \$player = \$event->getPlayer();
        // TODO: clean up player session / save data
    }

    // Add more event handlers below as needed.
    // Use \$this->config->getMessage('key') for messages.
}
PHP);

// ─── Command ────────────────────────────────────────────────────────────────

writeFile("$src/command/{$pluginName}Command.php", <<<PHP
<?php
declare(strict_types=1);

namespace $ns\\command;

use pocketmine\\command\\Command;
use pocketmine\\command\\CommandSender;
use pocketmine\\player\\Player;
use pocketmine\\plugin\\PluginOwned;
use pocketmine\\plugin\\PluginOwnedTrait;
use $ns\\$pluginName;
use $ns\\PluginConfig;

final class {$pluginName}Command extends Command implements PluginOwned {
    use PluginOwnedTrait;

    public function __construct(
        private readonly $pluginName \$plugin,
        private readonly PluginConfig \$config
    ) {
        parent::__construct(
            name: "$commandName",
            description: "Main command for $pluginName",
            usageMessage: "/$commandName <reload|help>",
            aliases: []
        );
        \$this->setPermission("$pluginLower.command");
        \$this->owningPlugin = \$plugin;
    }

    public function execute(CommandSender \$sender, string \$commandLabel, array \$args): bool {
        if (!\$this->testPermission(\$sender)) return false;

        \$sub = strtolower(\$args[0] ?? 'help');

        match(\$sub) {
            'reload' => \$this->handleReload(\$sender),
            'help'   => \$this->handleHelp(\$sender),
            default  => \$sender->sendMessage(
                \$this->config->getMessage('usage', ['usage' => \$this->getUsage()])
            )
        };

        return true;
    }

    private function handleReload(CommandSender \$sender): void {
        if (!\$sender->hasPermission("$pluginLower.command.admin")) {
            \$sender->sendMessage(\$this->config->getMessage('no-permission'));
            return;
        }
        \$this->plugin->reloadConfig();
        \$this->config->reload();
        \$sender->sendMessage(\$this->config->getMessage('reload'));
    }

    private function handleHelp(CommandSender \$sender): void {
        \$prefix = \$this->config->getPrefix();
        \$sender->sendMessage(\$prefix . "&e/$commandName reload &7— Reload the config");
        \$sender->sendMessage(\$prefix . "&e/$commandName help   &7— Show this help");
    }
}
PHP);

// ─── Utils.php ──────────────────────────────────────────────────────────────

writeFile("$src/utils/Utils.php", <<<PHP
<?php
declare(strict_types=1);

namespace $ns\\utils;

use pocketmine\\math\\Vector3;
use pocketmine\\world\\Position;
use pocketmine\\Server;

final class Utils {

    /**
     * Serialize a Vector3 to a compact string key.
     * Example: Vector3(10, 64, -5) → "10:64:-5"
     */
    public static function vecToString(Vector3 \$vec): string {
        return (int)\$vec->getX() . ':' . (int)\$vec->getY() . ':' . (int)\$vec->getZ();
    }

    /** Deserialize a "x:y:z" string back to a Vector3. */
    public static function stringToVec(string \$str): Vector3 {
        [\$x, \$y, \$z] = explode(':', \$str) + [0, 0, 0];
        return new Vector3((int)\$x, (int)\$y, (int)\$z);
    }

    /**
     * Serialize a Position (including world name) for YAML/DB storage.
     * @return array{x: float, y: float, z: float, world: string}
     */
    public static function posToArray(Position \$pos): array {
        return [
            'x'     => \$pos->getX(),
            'y'     => \$pos->getY(),
            'z'     => \$pos->getZ(),
            'world' => \$pos->getWorld()->getFolderName(),
        ];
    }

    /**
     * Deserialize a stored position array back to a Position.
     * Returns null if the world is not loaded.
     */
    public static function arrayToPos(array \$data): ?Position {
        \$world = Server::getInstance()->getWorldManager()->getWorldByName(\$data['world'] ?? '');
        if (\$world === null) return null;
        return new Position((float)\$data['x'], (float)\$data['y'], (float)\$data['z'], \$world);
    }

    /**
     * Format seconds into "m:ss" countdown string.
     * Example: 125 → "2:05"
     */
    public static function formatTime(int \$seconds): string {
        return gmdate('i:s', \$seconds);
    }

    /**
     * Format a float as a dollar-style currency string.
     * Example: 1234.5 → "\$1,234.50"
     */
    public static function formatMoney(float \$amount, string \$symbol = '\$'): string {
        return \$symbol . number_format(\$amount, 2);
    }

    /**
     * Clamp a float between a minimum and maximum.
     */
    public static function clamp(float \$value, float \$min, float \$max): float {
        return max(\$min, min(\$max, \$value));
    }

    /**
     * Convert ticks (game ticks at 20/s) to seconds.
     */
    public static function ticksToSeconds(int \$ticks): float {
        return \$ticks / 20.0;
    }

    /**
     * Convert seconds to ticks.
     */
    public static function secondsToTicks(float \$seconds): int {
        return (int) round(\$seconds * 20);
    }
}
PHP);

// ─── Tier 2+ additions ──────────────────────────────────────────────────────

if ($tier >= 2) {
    writeFile("$src/manager/{$pluginName}Manager.php", <<<PHP
<?php
declare(strict_types=1);

namespace $ns\\manager;

use pocketmine\\player\\Player;
use $ns\\PluginConfig;

/**
 * {$pluginName}Manager — central business logic hub.
 *
 * All feature logic belongs here, not in the listener or command classes.
 * The listener routes events here. Commands call methods here.
 */
final class {$pluginName}Manager {

    public function __construct(private readonly PluginConfig \$config) {}

    // ── Example Feature Methods ───────────────────────────────────────────────

    /**
     * Example: check if a player can perform the main action.
     * Replace this with your actual feature logic.
     */
    public function canUse(Player \$player): bool {
        // Check permission, cooldown, game state, etc.
        return \$player->hasPermission("$pluginLower.command");
    }

    /**
     * Example: execute the main feature for a player.
     * Replace with actual functionality.
     */
    public function execute(Player \$player): void {
        if (!\$this->canUse(\$player)) {
            \$player->sendMessage(\$this->config->getMessage('no-permission'));
            return;
        }
        // TODO: implement feature
    }
}
PHP);
}

// ─── Tier 3 additions ───────────────────────────────────────────────────────

if ($tier === 3) {
    writeFile("$src/session/Session.php", <<<PHP
<?php
declare(strict_types=1);

namespace $ns\\session;

use pocketmine\\player\\Player;
use pocketmine\\Server;

/**
 * Session — per-player runtime state.
 *
 * Stores uuid/xuid/name ONLY — never a Player object.
 * Player is recovered on demand via getPlayer().
 */
final class Session {

    public function __construct(
        private string \$uuid,   // Raw UUID bytes
        private string \$xuid,   // XUID — primary DB key
        private string \$name,   // Display name (updated on login)
    ) {}

    public static function create(Player \$player): self {
        return new self(
            \$player->getUniqueId()->getBytes(),
            \$player->getXuid(),
            \$player->getName()
        );
    }

    /** Get the live Player object (null if offline). */
    public function getPlayer(): ?Player {
        return Server::getInstance()->getPlayerByRawUUID(\$this->uuid);
    }

    public function getXuid(): string { return \$this->xuid; }
    public function getName(): string { return \$this->name; }

    public function setName(string \$name): void { \$this->name = \$name; }

    /** Called on PlayerJoinEvent after session already exists. */
    public function onJoin(): void {
        \$player = \$this->getPlayer();
        if (\$player === null) return;
        // Setup scoreboard, give lobby items, etc.
    }

    /** Called on PlayerQuitEvent. */
    public function onQuit(): void {
        // Cleanup game contexts, cancel timers, etc.
    }

    /** Called every tick from SessionFactory::task(). */
    public function update(): void {
        // Scoreboard updates, cooldown timers, etc.
    }

    /** Called on plugin disable — flush data to DB. */
    public function persist(): void {
        // Async DB save
    }
}
PHP);

    writeFile("$src/session/SessionFactory.php", <<<PHP
<?php
declare(strict_types=1);

namespace $ns\\session;

use pocketmine\\player\\Player;
use pocketmine\\scheduler\\ClosureTask;
use $ns\\$pluginName;

final class SessionFactory {

    /** @var array<string, Session> indexed by xuid */
    private static array \$sessions = [];

    public static function create(Player \$player): void {
        self::\$sessions[\$player->getXuid()] = Session::create(\$player);
    }

    public static function get(Player|string \$player): ?Session {
        \$xuid = \$player instanceof Player ? \$player->getXuid() : \$player;
        return self::\$sessions[\$xuid] ?? null;
    }

    public static function remove(string \$xuid): void {
        unset(self::\$sessions[\$xuid]);
    }

    /** @return Session[] */
    public static function getAll(): array { return self::\$sessions; }

    /** Register 1-tick repeating update loop. Call once from onEnable(). */
    public static function task($pluginName \$plugin): void {
        \$plugin->getScheduler()->scheduleRepeatingTask(
            new ClosureTask(static fn() => array_walk(self::\$sessions, fn(Session \$s) => \$s->update())),
            1
        );
    }

    /** Flush all sessions to DB at plugin disable. */
    public static function saveAll(): void {
        array_walk(self::\$sessions, fn(Session \$s) => \$s->persist());
    }

    /** No-op — data loads async per-player on join. */
    public static function loadAll(): void {}
}
PHP);
}

// ─── Done ────────────────────────────────────────────────────────────────────

echo "\n✅ Plugin scaffolded successfully!\n";
echo "   Location: $outDir\n";
echo "   Tier:     $tier\n\n";
echo "Next steps:\n";
echo "  1. Edit resources/config.yml to add your actual config keys\n";
echo "  2. Add matching typed getters to PluginConfig.php\n";
echo "  3. Implement your feature logic in manager/{$pluginName}Manager.php\n";
echo "  4. Zip the folder and drop it into your server's plugins/ directory\n\n";
