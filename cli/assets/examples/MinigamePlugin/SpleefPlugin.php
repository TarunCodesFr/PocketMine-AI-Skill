<?php
declare(strict_types=1);

/**
 * MINIGAME PLUGIN EXAMPLE (Spleef)
 * Target: PocketMine-MP 5.x (API 5.0.0+)
 * PHP: 8.2
 *
 * Demonstrates:
 *  - Arena state machine (WAITING → STARTING → IN_GAME → ENDING → RESETTING)
 *  - Player inventory save/restore
 *  - Countdown task
 *  - Player elimination
 *  - Spectator system
 *  - Safe arena protection
 */

// =========================================================
// plugin.yml
// =========================================================
/*
name: SpleefGame
version: 1.0.0
main: AuthorName\SpleefGame\Main
api: ["5.0.0"]
description: "Spleef minigame with arena state machine"
author: AuthorName

commands:
  spleef:
    description: "Spleef commands"
    usage: "/spleef <join|leave|create|list|setmin|setmax>"
    permission: spleefgame.command
  spleefadmin:
    description: "Spleef admin commands"
    usage: "/spleefadmin <create|setspawn|setlobby|setmin|setmax>"
    permission: spleefgame.admin

permissions:
  spleefgame.command:
    description: "Access Spleef commands"
    default: true
  spleefgame.admin:
    description: "Access Spleef admin commands"
    default: op
*/

// =========================================================
// src/AuthorName/SpleefGame/arena/ArenaState.php
// =========================================================
namespace AuthorName\SpleefGame\arena;

enum ArenaState {
    case WAITING;
    case STARTING;
    case IN_GAME;
    case ENDING;
    case RESETTING;
}

// =========================================================
// src/AuthorName/SpleefGame/arena/Arena.php
// =========================================================
namespace AuthorName\SpleefGame\arena;

use AuthorName\SpleefGame\Main;
use AuthorName\SpleefGame\task\ArenaTickTask;
use pocketmine\block\VanillaBlocks;
use pocketmine\item\VanillaItems;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\scheduler\TaskHandler;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;

final class Arena {

    private ArenaState $state = ArenaState::WAITING;
    /** @var Player[] indexed by XUID */
    private array $players = [];
    /** @var Player[] spectators indexed by XUID */
    private array $spectators = [];
    /** @var array<string, array{Item[], Item[]}> saved inventory [xuid => [inv, armor]] */
    private array $savedInventories = [];
    private ?TaskHandler $tickHandler = null;
    private int $countdown = 10;
    private int $endTimer = 5;

    public function __construct(
        private readonly Main $plugin,
        private readonly string $name,
        private readonly string $worldName,
        private readonly int $minPlayers,
        private readonly int $maxPlayers,
        private readonly Position $lobbySpawn,
        private readonly Position $arenaSpawn,
    ) {}

    // -------------------------------------------------------
    // State management
    // -------------------------------------------------------

    public function getState(): ArenaState {
        return $this->state;
    }

    public function getName(): string {
        return $this->name;
    }

    public function getPlayerCount(): int {
        return count($this->players);
    }

    public function isFull(): bool {
        return count($this->players) >= $this->maxPlayers;
    }

    // -------------------------------------------------------
    // Player join/leave
    // -------------------------------------------------------

    public function addPlayer(Player $player): bool {
        if($this->isFull() || $this->state === ArenaState::IN_GAME || $this->state === ArenaState::ENDING) {
            $player->sendMessage(TextFormat::colorize("&cThis arena is not joinable right now."));
            return false;
        }

        $this->savePlayerState($player);
        $this->players[$player->getXuid()] = $player;
        $this->preparePlayer($player);
        $player->teleport($this->lobbySpawn);
        $this->broadcastMessage("&e{$player->getName()} &7joined! &8({$this->getPlayerCount()}/{$this->maxPlayers})");

        // Start countdown if minimum reached
        if($this->state === ArenaState::WAITING && $this->getPlayerCount() >= $this->minPlayers) {
            $this->setState(ArenaState::STARTING);
            $this->startTick();
        }

        return true;
    }

    public function removePlayer(Player $player, bool $teleportToSpawn = true): void {
        $xuid = $player->getXuid();
        unset($this->players[$xuid], $this->spectators[$xuid]);
        $this->restorePlayerState($player);

        if($teleportToSpawn) {
            $defaultWorld = Server::getInstance()->getWorldManager()->getDefaultWorld();
            if($defaultWorld !== null) {
                $player->teleport($defaultWorld->getSpawnLocation());
            }
        }

        $this->broadcastMessage("&e{$player->getName()} &7left the game.");

        // Check if game should end (too few players)
        if($this->state === ArenaState::IN_GAME && $this->getAlivePlayerCount() <= 1) {
            $this->endGame();
        }
        // Revert to waiting if not enough players during countdown
        if($this->state === ArenaState::STARTING && $this->getPlayerCount() < $this->minPlayers) {
            $this->setState(ArenaState::WAITING);
            $this->stopTick();
            $this->countdown = 10;
            $this->broadcastMessage("&cNot enough players. Countdown cancelled.");
        }
    }

    public function eliminatePlayer(Player $player): void {
        $xuid = $player->getXuid();
        if(!isset($this->players[$xuid])) return;

        // Move to spectator
        unset($this->players[$xuid]);
        $this->spectators[$xuid] = $player;
        $player->setGamemode(GameMode::SPECTATOR);
        $player->sendMessage(TextFormat::colorize("&cYou have been eliminated! You are now spectating."));

        $this->broadcastMessage("&e{$player->getName()} &7has been eliminated! &8(" . $this->getAlivePlayerCount() . " remaining)");

        if($this->getAlivePlayerCount() <= 1) {
            $this->endGame();
        }
    }

    private function getAlivePlayerCount(): int {
        return count($this->players);
    }

    // -------------------------------------------------------
    // Inventory save/restore (CRITICAL for minigames)
    // -------------------------------------------------------

    private function savePlayerState(Player $player): void {
        $this->savedInventories[$player->getXuid()] = [
            $player->getInventory()->getContents(),
            $player->getArmorInventory()->getContents(),
        ];
    }

    private function restorePlayerState(Player $player): void {
        $xuid = $player->getXuid();
        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->getEffects()->clear();
        $player->setHealth($player->getMaxHealth());

        if(isset($this->savedInventories[$xuid])) {
            [$inv, $armor] = $this->savedInventories[$xuid];
            $player->getInventory()->setContents($inv);
            $player->getArmorInventory()->setContents($armor);
            unset($this->savedInventories[$xuid]);
        }
    }

    private function preparePlayer(Player $player): void {
        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->getEffects()->clear();
        $player->setHealth($player->getMaxHealth());
        $player->setGamemode(GameMode::SURVIVAL);
    }

    private function giveGameKit(Player $player): void {
        $shovel = VanillaItems::DIAMOND_SHOVEL();
        $shovel->setCustomName(TextFormat::colorize("&bSpleef Shovel"));
        $player->getInventory()->setItem(0, $shovel);
    }

    // -------------------------------------------------------
    // Game lifecycle
    // -------------------------------------------------------

    public function tick(): void {
        match($this->state) {
            ArenaState::STARTING  => $this->tickStarting(),
            ArenaState::IN_GAME   => $this->tickInGame(),
            ArenaState::ENDING    => $this->tickEnding(),
            ArenaState::RESETTING => $this->tickResetting(),
            default               => null,
        };
    }

    private function tickStarting(): void {
        if($this->countdown <= 0) {
            $this->startGame();
            return;
        }
        if($this->countdown <= 5 || $this->countdown % 5 === 0) {
            $this->broadcastTitle("&aGame Start", "&7in &e{$this->countdown}s");
        }
        $this->countdown--;
    }

    private function tickInGame(): void {
        // Check for players in void (fell through)
        foreach($this->players as $player) {
            if($player->getPosition()->getY() < -10) {
                $this->eliminatePlayer($player);
            }
        }
    }

    private function tickEnding(): void {
        if($this->endTimer <= 0) {
            $this->setState(ArenaState::RESETTING);
            $this->resetArena();
            return;
        }
        $this->endTimer--;
    }

    private function tickResetting(): void {
        // Resetting is handled in resetArena() - stop tick after
        $this->stopTick();
    }

    private function startGame(): void {
        $this->setState(ArenaState::IN_GAME);
        foreach($this->players as $player) {
            $player->teleport($this->arenaSpawn);
            $this->giveGameKit($player);
        }
        $this->broadcastTitle("&aGame Started!", "&7Break snow blocks to eliminate opponents!");
    }

    private function endGame(): void {
        $this->setState(ArenaState::ENDING);
        $this->endTimer = 5;

        $winners = array_values($this->players);
        if(count($winners) === 1) {
            $winner = $winners[0];
            $this->broadcastTitle("&6Winner!", "&e{$winner->getName()} &7wins the game!");
            Server::getInstance()->broadcastMessage(TextFormat::colorize(
                "&6[Spleef] &e{$winner->getName()} &7won in &b{$this->name}&7!"
            ));
        } else {
            $this->broadcastTitle("&6Draw!", "&7No winner this round.");
        }
    }

    private function resetArena(): void {
        // Kick remaining players back to lobby
        foreach(array_merge($this->players, $this->spectators) as $player) {
            $this->removePlayer($player, true);
        }
        $this->players = [];
        $this->spectators = [];
        $this->savedInventories = [];
        $this->countdown = 10;
        $this->endTimer = 5;
        $this->setState(ArenaState::WAITING);
        // TODO: Restore arena blocks (use world copy or snapshot)
    }

    // -------------------------------------------------------
    // Utilities
    // -------------------------------------------------------

    private function setState(ArenaState $state): void {
        $this->state = $state;
    }

    private function startTick(): void {
        $this->tickHandler = $this->plugin->getScheduler()->scheduleRepeatingTask(
            new ArenaTickTask($this),
            20 // Every 20 ticks = 1 second
        );
    }

    private function stopTick(): void {
        $this->tickHandler?->cancel();
        $this->tickHandler = null;
    }

    private function broadcastMessage(string $message): void {
        foreach(array_merge($this->players, $this->spectators) as $player) {
            $player->sendMessage(TextFormat::colorize("&7[&bSpleef&7] &r" . $message));
        }
    }

    private function broadcastTitle(string $title, string $subtitle): void {
        foreach(array_merge($this->players, $this->spectators) as $player) {
            $player->sendTitle(
                title: TextFormat::colorize($title),
                subtitle: TextFormat::colorize($subtitle),
                fadeIn: 5,
                stay: 30,
                fadeOut: 5
            );
        }
    }

    public function hasPlayer(Player $player): bool {
        return isset($this->players[$player->getXuid()]) || isset($this->spectators[$player->getXuid()]);
    }
}

// =========================================================
// src/AuthorName/SpleefGame/task/ArenaTickTask.php
// =========================================================
namespace AuthorName\SpleefGame\task;

use AuthorName\SpleefGame\arena\Arena;
use pocketmine\scheduler\Task;

final class ArenaTickTask extends Task {

    public function __construct(private readonly Arena $arena) {}

    public function onRun(): void {
        $this->arena->tick();
    }
}

// =========================================================
// src/AuthorName/SpleefGame/listener/GameListener.php
// =========================================================
namespace AuthorName\SpleefGame\listener;

use AuthorName\SpleefGame\manager\ArenaManager;
use AuthorName\SpleefGame\arena\ArenaState;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\player\Player;

final class GameListener implements Listener {

    public function __construct(private readonly ArenaManager $arenaManager) {}

    /**
     * Allow breaking only snow blocks during spleef.
     */
    public function onBlockBreak(BlockBreakEvent $event): void {
        $player = $event->getPlayer();
        $arena = $this->arenaManager->getArenaForPlayer($player);
        if($arena === null) return;

        if($arena->getState() !== ArenaState::IN_GAME) {
            $event->cancel();
            return;
        }

        // Only allow breaking snow layers in spleef
        // (Adjust block type to match your arena floor)
        $block = $event->getBlock();
        if(!($block instanceof \pocketmine\block\SnowLayer)) {
            $event->cancel();
        } else {
            $event->setDrops([]); // No drops from broken snow
        }
    }

    /**
     * Prevent block placing during game.
     */
    public function onBlockPlace(BlockPlaceEvent $event): void {
        $player = $event->getPlayer();
        if($this->arenaManager->getArenaForPlayer($player) !== null) {
            $event->cancel();
        }
    }

    /**
     * Prevent fall damage during waiting/starting.
     */
    public function onEntityDamage(EntityDamageEvent $event): void {
        $player = $event->getEntity();
        if(!($player instanceof Player)) return;

        $arena = $this->arenaManager->getArenaForPlayer($player);
        if($arena === null) return;

        // Cancel all damage during non-game phases
        if($arena->getState() !== ArenaState::IN_GAME) {
            $event->cancel();
        }
    }

    /**
     * Remove player from arena on disconnect.
     */
    public function onPlayerQuit(PlayerQuitEvent $event): void {
        $player = $event->getPlayer();
        $arena = $this->arenaManager->getArenaForPlayer($player);
        $arena?->removePlayer($player);
    }

    /**
     * Prevent item drops during game.
     */
    public function onPlayerDropItem(PlayerDropItemEvent $event): void {
        if($this->arenaManager->getArenaForPlayer($event->getPlayer()) !== null) {
            $event->cancel();
        }
    }
}

// =========================================================
// src/AuthorName/SpleefGame/manager/ArenaManager.php
// =========================================================
namespace AuthorName\SpleefGame\manager;

use AuthorName\SpleefGame\arena\Arena;
use AuthorName\SpleefGame\arena\ArenaState;
use AuthorName\SpleefGame\Main;
use pocketmine\player\Player;

final class ArenaManager {

    /** @var array<string, Arena> */
    private array $arenas = [];

    public function __construct(private readonly Main $plugin) {
        $this->loadArenas();
    }

    private function loadArenas(): void {
        // Load arena configs from data folder — implement YAML loading here
        // For the example, we leave this as a stub
    }

    public function getArena(string $name): ?Arena {
        return $this->arenas[strtolower($name)] ?? null;
    }

    /** @return Arena[] */
    public function getArenas(): array {
        return $this->arenas;
    }

    public function getArenaForPlayer(Player $player): ?Arena {
        foreach($this->arenas as $arena) {
            if($arena->hasPlayer($player)) return $arena;
        }
        return null;
    }

    public function joinArena(Player $player, string $name): bool {
        $arena = $this->getArena($name);
        if($arena === null) {
            $player->sendMessage(\pocketmine\utils\TextFormat::colorize("&cArena &e{$name} &cdoes not exist."));
            return false;
        }
        return $arena->addPlayer($player);
    }

    public function leaveArena(Player $player): bool {
        $arena = $this->getArenaForPlayer($player);
        if($arena === null) {
            $player->sendMessage(\pocketmine\utils\TextFormat::colorize("&cYou are not in any arena."));
            return false;
        }
        $arena->removePlayer($player);
        return true;
    }

    public function getAvailableArenas(): array {
        return array_filter(
            $this->arenas,
            fn(Arena $arena) => $arena->getState() === ArenaState::WAITING && !$arena->isFull()
        );
    }
}
