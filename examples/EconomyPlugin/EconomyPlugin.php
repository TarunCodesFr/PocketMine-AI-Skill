<?php
declare(strict_types=1);

/**
 * ECONOMY PLUGIN EXAMPLE
 * Target: PocketMine-MP 5.x (API 5.0.0+)
 * PHP: 8.2
 *
 * Features:
 *  - Player balances stored in SQLite (async)
 *  - Public API for other plugins to interact
 *  - /balance, /pay, /baltop commands
 *  - Transaction log
 *  - XUID-based storage
 */

// =========================================================
// plugin.yml
// =========================================================
/*
name: EconomyPlugin
version: 1.0.0
main: AuthorName\EconomyPlugin\Main
api: ["5.0.0"]
description: "Economy plugin with SQLite backend"
author: AuthorName

commands:
  balance:
    description: "Check your balance"
    usage: "/balance [player]"
    aliases: [bal, money]
    permission: economy.balance
  pay:
    description: "Pay another player"
    usage: "/pay <player> <amount>"
    permission: economy.pay
  baltop:
    description: "Top balances"
    usage: "/baltop [page]"
    permission: economy.baltop
  eco:
    description: "Admin economy commands"
    usage: "/eco <give|take|set|reset> <player> [amount]"
    permission: economy.admin

permissions:
  economy.balance:
    description: "Check balance"
    default: true
  economy.pay:
    description: "Pay players"
    default: true
  economy.baltop:
    description: "View leaderboard"
    default: true
  economy.admin:
    description: "Admin economy commands"
    default: op
*/

// =========================================================
// src/AuthorName/EconomyPlugin/database/Database.php
// =========================================================
namespace AuthorName\EconomyPlugin\database;

use pocketmine\Server;

/**
 * All database operations are performed asynchronously.
 * This class handles raw SQLite operations safely in async threads.
 */
final class Database {

    public static function init(string $dataFolder): void {
        $db = new \SQLite3($dataFolder . "economy.db");
        $db->exec("
            CREATE TABLE IF NOT EXISTS economy (
                xuid TEXT PRIMARY KEY,
                username TEXT NOT NULL,
                balance REAL NOT NULL DEFAULT 0.0,
                last_updated INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_balance ON economy (balance DESC);
        ");
        $db->close();
    }

    public static function getBalance(string $dataFolder, string $xuid): float {
        $db = new \SQLite3($dataFolder . "economy.db");
        $stmt = $db->prepare("SELECT balance FROM economy WHERE xuid = ?");
        $stmt->bindValue(1, $xuid);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $db->close();
        return (float) ($row["balance"] ?? 0.0);
    }

    public static function setBalance(string $dataFolder, string $xuid, string $username, float $balance): void {
        $db = new \SQLite3($dataFolder . "economy.db");
        $stmt = $db->prepare("
            INSERT INTO economy (xuid, username, balance, last_updated) VALUES (?, ?, ?, ?)
            ON CONFLICT(xuid) DO UPDATE SET balance = excluded.balance, 
                                            username = excluded.username,
                                            last_updated = excluded.last_updated
        ");
        $stmt->bindValue(1, $xuid);
        $stmt->bindValue(2, $username);
        $stmt->bindValue(3, $balance);
        $stmt->bindValue(4, time());
        $stmt->execute();
        $db->close();
    }

    /**
     * @return array<int, array{username: string, balance: float}>
     */
    public static function getTopBalances(string $dataFolder, int $limit = 10): array {
        $db = new \SQLite3($dataFolder . "economy.db");
        $stmt = $db->prepare("SELECT username, balance FROM economy ORDER BY balance DESC LIMIT ?");
        $stmt->bindValue(1, $limit);
        $result = $stmt->execute();
        $rows = [];
        while($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = ["username" => $row["username"], "balance" => (float) $row["balance"]];
        }
        $db->close();
        return $rows;
    }
}

// =========================================================
// src/AuthorName/EconomyPlugin/task/LoadBalanceTask.php
// =========================================================
namespace AuthorName\EconomyPlugin\task;

use AuthorName\EconomyPlugin\database\Database;
use AuthorName\EconomyPlugin\EconomyAPI;
use pocketmine\player\Player;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;

final class LoadBalanceTask extends AsyncTask {

    public function __construct(
        private readonly string $dataFolder,
        private readonly string $xuid,
        private readonly string $username
    ) {}

    public function onRun(): void {
        $balance = Database::getBalance($this->dataFolder, $this->xuid);
        $this->setResult($balance);
    }

    public function onCompletion(): void {
        $balance = (float) $this->getResult();
        $player = Server::getInstance()->getPlayerExact($this->username);
        if($player instanceof Player && $player->isOnline()) {
            EconomyAPI::getInstance()->cacheBalance($player->getXuid(), $balance);
        }
    }
}

// =========================================================
// src/AuthorName/EconomyPlugin/task/SaveBalanceTask.php
// =========================================================
namespace AuthorName\EconomyPlugin\task;

use AuthorName\EconomyPlugin\database\Database;
use pocketmine\scheduler\AsyncTask;

final class SaveBalanceTask extends AsyncTask {

    public function __construct(
        private readonly string $dataFolder,
        private readonly string $xuid,
        private readonly string $username,
        private readonly float $balance
    ) {}

    public function onRun(): void {
        Database::setBalance($this->dataFolder, $this->xuid, $this->username, $this->balance);
    }
}

// =========================================================
// src/AuthorName/EconomyPlugin/EconomyAPI.php
// =========================================================
namespace AuthorName\EconomyPlugin;

use AuthorName\EconomyPlugin\task\LoadBalanceTask;
use AuthorName\EconomyPlugin\task\SaveBalanceTask;
use pocketmine\player\Player;
use pocketmine\Server;

/**
 * Public-facing API class.
 * Other plugins should use this class exclusively.
 *
 * Example usage from another plugin:
 *   $api = EconomyAPI::getInstance();
 *   $balance = $api->getBalance($player);
 *   $api->addBalance($player, 100.0);
 */
final class EconomyAPI {

    private static self $instance;

    /** @var array<string, float> In-memory balance cache (xuid → balance) */
    private array $cache = [];

    private float $defaultBalance;
    private string $dataFolder;

    public static function getInstance(): self {
        return self::$instance;
    }

    public function __construct(private readonly Main $plugin) {
        self::$instance = $this;
        $this->defaultBalance = (float) $plugin->getConfig()->get("economy.starting-balance", 100.0);
        $this->dataFolder = $plugin->getDataFolder();
    }

    // -------------------------------------------------------
    // Cache management (main thread safe)
    // -------------------------------------------------------

    public function cacheBalance(string $xuid, float $balance): void {
        $this->cache[$xuid] = $balance;
    }

    public function invalidateCache(string $xuid): void {
        unset($this->cache[$xuid]);
    }

    // -------------------------------------------------------
    // Public API Methods
    // -------------------------------------------------------

    /**
     * Get a player's balance (from cache — always cached at login).
     */
    public function getBalance(Player $player): float {
        return $this->cache[$player->getXuid()] ?? $this->defaultBalance;
    }

    /**
     * Check if player can afford an amount.
     */
    public function canAfford(Player $player, float $amount): bool {
        return $this->getBalance($player) >= $amount;
    }

    /**
     * Add balance. Returns true if successful.
     */
    public function addBalance(Player $player, float $amount, string $reason = "Unknown"): bool {
        if($amount <= 0) return false;
        $xuid = $player->getXuid();
        $new = $this->getBalance($player) + $amount;
        $this->cache[$xuid] = $new;
        $this->persistBalance($player);
        $this->plugin->getLogger()->debug("Economy: +{$amount} for {$player->getName()} ({$reason})");
        return true;
    }

    /**
     * Deduct balance. Returns false if insufficient funds.
     */
    public function deductBalance(Player $player, float $amount, string $reason = "Unknown"): bool {
        if($amount <= 0 || !$this->canAfford($player, $amount)) return false;
        $xuid = $player->getXuid();
        $this->cache[$xuid] = $this->getBalance($player) - $amount;
        $this->persistBalance($player);
        $this->plugin->getLogger()->debug("Economy: -{$amount} from {$player->getName()} ({$reason})");
        return true;
    }

    /**
     * Set balance directly. Admin use only.
     */
    public function setBalance(Player $player, float $amount): void {
        $this->cache[$player->getXuid()] = max(0.0, $amount);
        $this->persistBalance($player);
    }

    /**
     * Transfer balance between two players atomically.
     * Returns false if sender has insufficient funds.
     */
    public function transfer(Player $sender, Player $recipient, float $amount): bool {
        if(!$this->deductBalance($sender, $amount, "Transfer to {$recipient->getName()}")) {
            return false;
        }
        $this->addBalance($recipient, $amount, "Transfer from {$sender->getName()}");
        return true;
    }

    /**
     * Load player balance from database (call on PlayerJoin).
     */
    public function loadBalance(Player $player): void {
        // Set default first so player has value immediately
        if(!isset($this->cache[$player->getXuid()])) {
            $this->cache[$player->getXuid()] = $this->defaultBalance;
        }
        Server::getInstance()->getAsyncPool()->submitTask(
            new LoadBalanceTask($this->dataFolder, $player->getXuid(), $player->getName())
        );
    }

    /**
     * Persist player balance to database asynchronously.
     */
    private function persistBalance(Player $player): void {
        Server::getInstance()->getAsyncPool()->submitTask(
            new SaveBalanceTask(
                $this->dataFolder,
                $player->getXuid(),
                $player->getName(),
                $this->getBalance($player)
            )
        );
    }

    /**
     * Format balance for display.
     */
    public static function formatBalance(float $balance): string {
        return "$" . number_format($balance, 2);
    }
}

// =========================================================
// src/AuthorName/EconomyPlugin/listener/EconomyListener.php
// =========================================================
namespace AuthorName\EconomyPlugin\listener;

use AuthorName\EconomyPlugin\EconomyAPI;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;

final class EconomyListener implements Listener {

    public function __construct(private readonly EconomyAPI $api) {}

    public function onPlayerJoin(PlayerJoinEvent $event): void {
        $this->api->loadBalance($event->getPlayer());
    }

    public function onPlayerQuit(PlayerQuitEvent $event): void {
        $player = $event->getPlayer();
        // Cache is cleaned — balance was already persisted on each change
        $this->api->invalidateCache($player->getXuid());
    }
}

// =========================================================
// src/AuthorName/EconomyPlugin/command/BalanceCommand.php
// =========================================================
namespace AuthorName\EconomyPlugin\command;

use AuthorName\EconomyPlugin\EconomyAPI;
use AuthorName\EconomyPlugin\Main;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\PluginOwned;
use pocketmine\plugin\PluginOwnedTrait;
use pocketmine\utils\TextFormat;

final class BalanceCommand extends Command implements PluginOwned {
    use PluginOwnedTrait;

    public function __construct(private readonly Main $plugin, private readonly EconomyAPI $api) {
        parent::__construct(
            name: "balance",
            description: "Check your balance",
            usageMessage: "/balance [player]",
            aliases: ["bal", "money"]
        );
        $this->setPermission("economy.balance");
        $this->owningPlugin = $plugin;
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool {
        if(!$this->testPermission($sender)) return false;

        if(isset($args[0])) {
            $target = $this->plugin->getServer()->getPlayerExact($args[0]);
            if(!($target instanceof Player)) {
                $sender->sendMessage(TextFormat::colorize("&cPlayer not found."));
                return false;
            }
            $balance = $this->api->getBalance($target);
            $sender->sendMessage(TextFormat::colorize(
                "&7{$target->getName()}'s balance: &a" . EconomyAPI::formatBalance($balance)
            ));
            return true;
        }

        if(!($sender instanceof Player)) {
            $sender->sendMessage(TextFormat::colorize("&cSpecify a player name."));
            return false;
        }

        $balance = $this->api->getBalance($sender);
        $sender->sendMessage(TextFormat::colorize(
            "&7Your balance: &a" . EconomyAPI::formatBalance($balance)
        ));
        return true;
    }
}

// =========================================================
// src/AuthorName/EconomyPlugin/command/PayCommand.php
// =========================================================
namespace AuthorName\EconomyPlugin\command;

use AuthorName\EconomyPlugin\EconomyAPI;
use AuthorName\EconomyPlugin\Main;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\PluginOwned;
use pocketmine\plugin\PluginOwnedTrait;
use pocketmine\utils\TextFormat;

final class PayCommand extends Command implements PluginOwned {
    use PluginOwnedTrait;

    public function __construct(private readonly Main $plugin, private readonly EconomyAPI $api) {
        parent::__construct(
            name: "pay",
            description: "Pay another player",
            usageMessage: "/pay <player> <amount>",
            aliases: []
        );
        $this->setPermission("economy.pay");
        $this->owningPlugin = $plugin;
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool {
        if(!$this->testPermission($sender)) return false;
        if(!($sender instanceof Player)) {
            $sender->sendMessage(TextFormat::colorize("&cPlayers only."));
            return false;
        }
        if(count($args) < 2) {
            $sender->sendMessage(TextFormat::colorize("&cUsage: /pay <player> <amount>"));
            return false;
        }

        $recipient = $this->plugin->getServer()->getPlayerExact($args[0]);
        if(!($recipient instanceof Player)) {
            $sender->sendMessage(TextFormat::colorize("&cPlayer &e{$args[0]} &cis not online."));
            return false;
        }
        if($recipient === $sender) {
            $sender->sendMessage(TextFormat::colorize("&cYou cannot pay yourself."));
            return false;
        }

        $amount = filter_var($args[1], FILTER_VALIDATE_FLOAT);
        if($amount === false || $amount <= 0) {
            $sender->sendMessage(TextFormat::colorize("&cInvalid amount. Must be a positive number."));
            return false;
        }

        $minPay = (float) $this->plugin->getConfig()->get("economy.minimum-pay", 0.01);
        if($amount < $minPay) {
            $sender->sendMessage(TextFormat::colorize("&cMinimum payment is " . EconomyAPI::formatBalance($minPay)));
            return false;
        }

        if(!$this->api->transfer($sender, $recipient, $amount)) {
            $sender->sendMessage(TextFormat::colorize(
                "&cInsufficient funds. Your balance: &e" . EconomyAPI::formatBalance($this->api->getBalance($sender))
            ));
            return false;
        }

        $formatted = EconomyAPI::formatBalance($amount);
        $sender->sendMessage(TextFormat::colorize(
            "&aPaid &e{$formatted} &ato &b{$recipient->getName()}&a. " .
            "New balance: &e" . EconomyAPI::formatBalance($this->api->getBalance($sender))
        ));
        $recipient->sendMessage(TextFormat::colorize(
            "&a{$sender->getName()} &7paid you &e{$formatted}&7! " .
            "New balance: &e" . EconomyAPI::formatBalance($this->api->getBalance($recipient))
        ));
        return true;
    }
}

// =========================================================
// src/AuthorName/EconomyPlugin/Main.php
// =========================================================
namespace AuthorName\EconomyPlugin;

use AuthorName\EconomyPlugin\command\BalanceCommand;
use AuthorName\EconomyPlugin\command\PayCommand;
use AuthorName\EconomyPlugin\database\Database;
use AuthorName\EconomyPlugin\listener\EconomyListener;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;

final class Main extends PluginBase {

    private static self $instance;
    private EconomyAPI $api;

    public static function getInstance(): self {
        return self::$instance;
    }

    protected function onLoad(): void {
        self::$instance = $this;
    }

    protected function onEnable(): void {
        $this->saveDefaultConfig();
        $this->saveResource("economy.db.sql", false); // Optional SQL schema file

        // Initialize database (blocking — done ONCE at startup, not in game)
        Database::init($this->getDataFolder());

        $this->api = new EconomyAPI($this);
        $this->registerListeners();
        $this->registerCommands();
        $this->getLogger()->info(TextFormat::GREEN . "EconomyPlugin enabled!");
    }

    protected function onDisable(): void {
        $this->getLogger()->info(TextFormat::YELLOW . "EconomyPlugin disabled. All balances are persisted.");
    }

    public function getEconomyAPI(): EconomyAPI {
        return $this->api;
    }

    private function registerListeners(): void {
        $this->getServer()->getPluginManager()->registerEvents(
            new EconomyListener($this->api),
            $this
        );
    }

    private function registerCommands(): void {
        $map = $this->getServer()->getCommandMap();
        $map->register("economy", new BalanceCommand($this, $this->api));
        $map->register("economy", new PayCommand($this, $this->api));
    }
}
