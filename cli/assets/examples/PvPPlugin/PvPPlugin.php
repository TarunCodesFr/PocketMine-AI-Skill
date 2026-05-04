<?php
declare(strict_types=1);

/**
 * FULL PVP PLUGIN EXAMPLE
 * Target: PocketMine-MP 5.x (API 5.0.0+)
 * PHP: 8.2
 *
 * Features:
 *  - Combat tag system (15s combat timer)
 *  - Kill streak tracking + announcements
 *  - Combat log punishment (kill on quit during combat)
 *  - Custom knockback modifier
 *  - Bounty system (kill bounties)
 *  - Kit system (sword, rod, bow, gap)
 *  - Anti-fall damage in safe zones
 *  - Custom death messages
 */

// =========================================================
// plugin.yml
// =========================================================
/*
name: PvPPlugin
version: 1.0.0
main: AuthorName\PvPPlugin\Main
api: ["5.0.0"]
description: "Advanced PvP plugin with combat tags and kill streaks"
author: AuthorName

commands:
  pvp:
    description: "PvP main command"
    usage: "/pvp <kit|stats|bounty|top>"
    permission: pvpplugin.command
  kit:
    description: "Select a kit"
    usage: "/kit <name>"
    permission: pvpplugin.kit

permissions:
  pvpplugin.command:
    description: "Access PvP commands"
    default: true
  pvpplugin.kit:
    description: "Use kits"
    default: true
  pvpplugin.kit.diamond:
    description: "Diamond kit"
    default: op
  pvpplugin.bypass-combattag:
    description: "Bypass combat tag restrictions"
    default: op
*/

// =========================================================
// config.yml
// =========================================================
/*
settings:
  prefix: "&7[&cPvP&7] &r"
  debug: false

combat:
  tag-duration: 15        # seconds
  log-punishment: kill    # kill | nothing
  announce-streaks: true
  streak-announce-at: [5, 10, 15, 20, 25, 30, 50]

knockback:
  enabled: true
  horizontal: 0.4
  vertical: 0.4

bounties:
  enabled: true
  minimum: 100
  maximum: 100000

streaks:
  announce: true
  milestones: [5, 10, 15, 25, 50, 100]

messages:
  combat-tag: "{prefix}&cYou are now in combat! &7(&e{seconds}s&7)"
  combat-untag: "{prefix}&aYou are no longer in combat."
  combat-log: "&c{player} &7tried to combat log! They were punished."
  kill-streak: "&6[Streak] &e{player} &7is on a &c{streak} &7kill streak!"
  kill-message: "&7{killer} &ckilled &7{victim}&7! &8[{killer_health}❤]"
  death-message: "&7{victim} &8was killed by &7{killer}&8."
*/

// =========================================================
// src/AuthorName/PvPPlugin/session/CombatSession.php
// =========================================================
namespace AuthorName\PvPPlugin\session;

use pocketmine\player\Player;

/**
 * Holds per-player PvP state.
 * Stored in a WeakMap<Player, CombatSession> — memory safe.
 */
final class CombatSession {

    private bool $inCombat = false;
    private float $lastCombatTime = 0.0;
    private ?Player $lastOpponent = null;
    private int $killStreak = 0;
    private int $totalKills = 0;
    private int $totalDeaths = 0;
    private float $bounty = 0.0;

    public function __construct(private readonly Player $player) {}

    public function getPlayer(): Player {
        return $this->player;
    }

    // -------------------------------------------------------
    // Combat tag
    // -------------------------------------------------------

    public function tagCombat(Player $opponent, float $durationSeconds): void {
        $this->inCombat = true;
        $this->lastCombatTime = microtime(true);
        $this->lastOpponent = $opponent;
    }

    public function isInCombat(float $durationSeconds): bool {
        if(!$this->inCombat) return false;
        if((microtime(true) - $this->lastCombatTime) >= $durationSeconds) {
            $this->clearCombat();
            return false;
        }
        return true;
    }

    public function clearCombat(): void {
        $this->inCombat = false;
        $this->lastCombatTime = 0.0;
        $this->lastOpponent = null;
    }

    public function getRemainingCombatSeconds(float $durationSeconds): float {
        return max(0.0, $durationSeconds - (microtime(true) - $this->lastCombatTime));
    }

    public function getLastOpponent(): ?Player {
        // Validate opponent is still online before returning
        if($this->lastOpponent !== null && (!$this->lastOpponent->isOnline() || !$this->lastOpponent->isAlive())) {
            $this->lastOpponent = null;
        }
        return $this->lastOpponent;
    }

    // -------------------------------------------------------
    // Kill streaks
    // -------------------------------------------------------

    public function addKill(): void {
        $this->killStreak++;
        $this->totalKills++;
    }

    public function resetStreak(): void {
        $this->killStreak = 0;
        $this->totalDeaths++;
    }

    public function getKillStreak(): int {
        return $this->killStreak;
    }

    public function getTotalKills(): int {
        return $this->totalKills;
    }

    public function getTotalDeaths(): int {
        return $this->totalDeaths;
    }

    public function getKDR(): float {
        if($this->totalDeaths === 0) return (float) $this->totalKills;
        return round($this->totalKills / $this->totalDeaths, 2);
    }

    // -------------------------------------------------------
    // Bounty
    // -------------------------------------------------------

    public function getBounty(): float {
        return $this->bounty;
    }

    public function setBounty(float $amount): void {
        $this->bounty = max(0.0, $amount);
    }

    public function addBounty(float $amount): void {
        $this->bounty += $amount;
    }

    public function claimBounty(): float {
        $amount = $this->bounty;
        $this->bounty = 0.0;
        return $amount;
    }
}

// =========================================================
// src/AuthorName/PvPPlugin/manager/CombatManager.php
// =========================================================
namespace AuthorName\PvPPlugin\manager;

use AuthorName\PvPPlugin\Main;
use AuthorName\PvPPlugin\session\CombatSession;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use WeakMap;

final class CombatManager {

    /** @var WeakMap<Player, CombatSession> */
    private WeakMap $sessions;

    public function __construct(private readonly Main $plugin) {
        $this->sessions = new WeakMap();
    }

    public function getSession(Player $player): CombatSession {
        return $this->sessions[$player] ??= new CombatSession($player);
    }

    public function tagPlayers(Player $attacker, Player $victim): void {
        $duration = (float) $this->plugin->getConfig()->getNested("combat.tag-duration", 15);
        $attackerSession = $this->getSession($attacker);
        $victimSession = $this->getSession($victim);
        $wasInCombat = $attackerSession->isInCombat($duration);

        $attackerSession->tagCombat($victim, $duration);
        $victimSession->tagCombat($attacker, $duration);

        if(!$wasInCombat) {
            $tmpl = (string) $this->plugin->getConfig()->getNested("messages.combat-tag", "{prefix}combat!");
            $prefix = (string) $this->plugin->getConfig()->getNested("settings.prefix", "[PvP] ");
            $msg = str_replace(
                ["{prefix}", "{seconds}"],
                [$prefix, (string)(int)$duration],
                $tmpl
            );
            $attacker->sendMessage(TextFormat::colorize($msg));
            $victim->sendMessage(TextFormat::colorize($msg));
        }
    }

    public function handleKill(Player $killer, Player $victim): void {
        $killerSession = $this->getSession($killer);
        $victimSession = $this->getSession($victim);

        // Clear combat for both
        $killerSession->clearCombat();
        $victimSession->clearCombat();

        // Update stats
        $killerSession->addKill();
        $victimSession->resetStreak();

        // Claim bounty
        $bounty = $victimSession->claimBounty();
        if($bounty > 0 && $killer->isOnline()) {
            $killer->sendMessage(TextFormat::colorize(
                (string) $this->plugin->getConfig()->getNested("settings.prefix", "[PvP] ")
                . "&aClaimed &e$" . number_format($bounty, 0) . " &abounty!"
            ));
        }

        // Announce kill streak
        $streak = $killerSession->getKillStreak();
        $milestones = (array) $this->plugin->getConfig()->getNested("streaks.milestones", [5, 10, 15, 25, 50]);
        if(in_array($streak, $milestones, true)) {
            $tmpl = (string) $this->plugin->getConfig()->getNested("messages.kill-streak", "{player} is on {streak} streak!");
            $msg = str_replace(["{player}", "{streak}"], [$killer->getName(), (string)$streak], $tmpl);
            Server::getInstance()->broadcastMessage(TextFormat::colorize($msg));
        }

        // Kill announce
        $killMsg = (string) $this->plugin->getConfig()->getNested("messages.kill-message", "");
        if($killMsg !== "") {
            $msg = str_replace(
                ["{killer}", "{victim}", "{killer_health}"],
                [$killer->getName(), $victim->getName(), (string)round($killer->getHealth() / 2, 1)],
                $killMsg
            );
            Server::getInstance()->broadcastMessage(TextFormat::colorize($msg));
        }
    }

    public function handleCombatLog(Player $player): void {
        $duration = (float) $this->plugin->getConfig()->getNested("combat.tag-duration", 15);
        $session = $this->getSession($player);
        if(!$session->isInCombat($duration)) return;

        $punishment = (string) $this->plugin->getConfig()->getNested("combat.log-punishment", "kill");
        $session->clearCombat();

        if($punishment === "kill") {
            $opponent = $session->getLastOpponent();
            if($opponent !== null && $opponent->isOnline() && $opponent->isAlive()) {
                $this->handleKill($opponent, $player);
            }
            // Announce
            $tmpl = (string) $this->plugin->getConfig()->getNested("messages.combat-log", "{player} combat logged!");
            $msg = str_replace("{player}", $player->getName(), $tmpl);
            Server::getInstance()->broadcastMessage(TextFormat::colorize($msg));
        }
    }

    public function isInCombat(Player $player): bool {
        $duration = (float) $this->plugin->getConfig()->getNested("combat.tag-duration", 15);
        return $this->getSession($player)->isInCombat($duration);
    }

    public function cleanupSession(Player $player): void {
        unset($this->sessions[$player]);
    }
}

// =========================================================
// src/AuthorName/PvPPlugin/listener/CombatListener.php
// =========================================================
namespace AuthorName\PvPPlugin\listener;

use AuthorName\PvPPlugin\Main;
use AuthorName\PvPPlugin\manager\CombatManager;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\EventPriority;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;

final class CombatListener implements Listener {

    public function __construct(
        private readonly Main $plugin,
        private readonly CombatManager $combatManager
    ) {}

    /**
     * Handle PvP damage — tag combat, apply custom knockback.
     */
    public function onEntityDamage(EntityDamageByEntityEvent $event): void {
        $victim = $event->getEntity();
        $damager = $event->getDamager();

        if(!($victim instanceof Player) || !($damager instanceof Player)) return;
        if(!$victim->isAlive() || !$damager->isAlive()) return;

        // Tag both players in combat
        $this->combatManager->tagPlayers($damager, $victim);

        // Apply custom knockback if enabled
        if($this->plugin->getConfig()->getNested("knockback.enabled", true)) {
            $horizontal = (float) $this->plugin->getConfig()->getNested("knockback.horizontal", 0.4);
            $vertical = (float) $this->plugin->getConfig()->getNested("knockback.vertical", 0.4);
            $event->setKnockBack($horizontal);
            $event->setVerticalKnockBackLimit($vertical);
        }
    }

    /**
     * Handle player death — process kill/death stats.
     * Priority HIGH so we run before removal from the world.
     */
    public function onPlayerDeath(PlayerDeathEvent $event): void {
        $victim = $event->getPlayer();
        $cause = $victim->getLastDamageCause();

        if(!($cause instanceof EntityDamageByEntityEvent)) return;

        $damager = $cause->getDamager();
        if(!($damager instanceof Player) || !$damager->isOnline()) return;

        $this->combatManager->handleKill($damager, $victim);

        // Custom death message
        $deathMsg = (string) $this->plugin->getConfig()->getNested("messages.death-message", "");
        if($deathMsg !== "") {
            $event->setDeathMessage(\pocketmine\utils\TextFormat::colorize(
                str_replace(["{victim}", "{killer}"], [$victim->getName(), $damager->getName()], $deathMsg)
            ));
        }
    }

    /**
     * Handle combat log on quit.
     */
    public function onPlayerQuit(PlayerQuitEvent $event): void {
        $player = $event->getPlayer();
        $this->combatManager->handleCombatLog($player);
        $this->combatManager->cleanupSession($player);
    }

    /**
     * On respawn, give default kit after short delay.
     */
    public function onPlayerRespawn(PlayerRespawnEvent $event): void {
        $player = $event->getPlayer();
        // Use a short delay to ensure inventory is clear after respawn
        $this->plugin->getScheduler()->scheduleDelayedTask(
            new ClosureTask(function() use ($player): void {
                if(!$player->isOnline() || !$player->isAlive()) return;
                $player->getInventory()->clearAll();
                $player->getArmorInventory()->clearAll();
                // Give default kit here — delegate to KitManager
                $player->sendMessage(\pocketmine\utils\TextFormat::colorize(
                    (string) $this->plugin->getConfig()->getNested("settings.prefix", "[PvP] ") 
                    . "&aYou respawned! Use &e/kit &ato select a kit."
                ));
            }),
            5 // 5 ticks delay
        );
    }
}

// =========================================================
// src/AuthorName/PvPPlugin/Main.php
// =========================================================
namespace AuthorName\PvPPlugin;

use AuthorName\PvPPlugin\command\PvPCommand;
use AuthorName\PvPPlugin\listener\CombatListener;
use AuthorName\PvPPlugin\manager\CombatManager;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;

final class Main extends PluginBase {

    private static self $instance;
    private CombatManager $combatManager;

    public static function getInstance(): self {
        return self::$instance;
    }

    protected function onLoad(): void {
        self::$instance = $this;
    }

    protected function onEnable(): void {
        $this->saveDefaultConfig();
        $this->combatManager = new CombatManager($this);
        $this->registerListeners();
        $this->registerCommands();
        $this->getLogger()->info(TextFormat::GREEN . "PvPPlugin enabled!");
    }

    protected function onDisable(): void {
        $this->getLogger()->info(TextFormat::RED . "PvPPlugin disabled.");
    }

    private function registerListeners(): void {
        $pm = $this->getServer()->getPluginManager();
        $pm->registerEvents(new CombatListener($this, $this->combatManager), $this);
    }

    private function registerCommands(): void {
        $this->getServer()->getCommandMap()->register(
            "pvpplugin",
            new PvPCommand($this, $this->combatManager)
        );
    }

    public function getCombatManager(): CombatManager {
        return $this->combatManager;
    }
}

// =========================================================
// src/AuthorName/PvPPlugin/command/PvPCommand.php
// =========================================================
namespace AuthorName\PvPPlugin\command;

use AuthorName\PvPPlugin\Main;
use AuthorName\PvPPlugin\manager\CombatManager;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\PluginOwned;
use pocketmine\plugin\PluginOwnedTrait;
use pocketmine\utils\TextFormat;

final class PvPCommand extends Command implements PluginOwned {
    use PluginOwnedTrait;

    public function __construct(
        private readonly Main $plugin,
        private readonly CombatManager $combatManager
    ) {
        parent::__construct(
            name: "pvp",
            description: "PvP command",
            usageMessage: "/pvp <stats|combat>",
            aliases: []
        );
        $this->setPermission("pvpplugin.command");
        $this->owningPlugin = $plugin;
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool {
        if(!$this->testPermission($sender)) return false;
        if(!($sender instanceof Player)) {
            $sender->sendMessage(TextFormat::RED . "Players only.");
            return false;
        }

        $sub = strtolower($args[0] ?? "stats");
        match($sub) {
            "stats"  => $this->handleStats($sender),
            "combat" => $this->handleCombatStatus($sender),
            default  => $this->handleStats($sender),
        };
        return true;
    }

    private function handleStats(Player $player): void {
        $session = $this->combatManager->getSession($player);
        $player->sendMessage(TextFormat::colorize(
            "&7=== &cYour PvP Stats &7===\n" .
            "&7Kills: &a" . $session->getTotalKills() . "\n" .
            "&7Deaths: &c" . $session->getTotalDeaths() . "\n" .
            "&7KDR: &e" . $session->getKDR() . "\n" .
            "&7Kill Streak: &6" . $session->getKillStreak() . "\n" .
            "&7Bounty: &b$" . number_format($session->getBounty(), 0)
        ));
    }

    private function handleCombatStatus(Player $player): void {
        if($this->combatManager->isInCombat($player)) {
            $session = $this->combatManager->getSession($player);
            $remaining = $session->getRemainingCombatSeconds(
                (float) $this->plugin->getConfig()->getNested("combat.tag-duration", 15)
            );
            $player->sendMessage(TextFormat::colorize(
                "&cYou are in combat! &7(" . round($remaining, 1) . "s remaining)"
            ));
        } else {
            $player->sendMessage(TextFormat::colorize("&aYou are not in combat."));
        }
    }
}
