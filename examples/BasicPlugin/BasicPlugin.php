<?php
declare(strict_types=1);

/**
 * BASIC PLUGIN SKELETON EXAMPLE
 * Target: PocketMine-MP 5.x (API 5.0.0+)
 * PHP: 8.2
 *
 * This demonstrates the correct minimal structure for any plugin.
 */

// =========================================================
// plugin.yml
// =========================================================
/*
name: BasicPlugin
version: 1.0.0
main: AuthorName\BasicPlugin\Main
api: ["5.0.0"]
description: "A minimal skeleton plugin"
author: AuthorName

commands:
  basicplugin:
    description: "Main command"
    usage: "/basicplugin [reload]"
    aliases: [bp]
    permission: basicplugin.command

permissions:
  basicplugin.command:
    description: "Access BasicPlugin commands"
    default: op
*/

// =========================================================
// config.yml
// =========================================================
/*
# BasicPlugin Configuration

settings:
  debug: false
  prefix: "&7[&bBasicPlugin&7] &r"

messages:
  no-permission: "{prefix}&cYou don't have permission."
  player-only: "{prefix}&cPlayers only."
  reload: "{prefix}&aReloaded successfully."
*/

// =========================================================
// src/AuthorName/BasicPlugin/Main.php
// =========================================================
namespace AuthorName\BasicPlugin;

use AuthorName\BasicPlugin\command\MainCommand;
use AuthorName\BasicPlugin\listener\PlayerListener;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;

final class Main extends PluginBase {

    private static self $instance;

    public static function getInstance(): self {
        return self::$instance;
    }

    protected function onLoad(): void {
        self::$instance = $this;
    }

    protected function onEnable(): void {
        $this->saveDefaultConfig();
        $this->registerListeners();
        $this->registerCommands();
        $this->getLogger()->info(TextFormat::GREEN . $this->getName() . " v" . $this->getDescription()->getVersion() . " enabled!");
    }

    protected function onDisable(): void {
        $this->getLogger()->info(TextFormat::RED . $this->getName() . " disabled.");
    }

    private function registerListeners(): void {
        $this->getServer()->getPluginManager()->registerEvents(
            new PlayerListener($this),
            $this
        );
    }

    private function registerCommands(): void {
        $this->getServer()->getCommandMap()->register(
            strtolower($this->getName()),
            new MainCommand($this)
        );
    }
}

// =========================================================
// src/AuthorName/BasicPlugin/listener/PlayerListener.php
// =========================================================
namespace AuthorName\BasicPlugin\listener;

use AuthorName\BasicPlugin\Main;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\utils\TextFormat;

final class PlayerListener implements Listener {

    public function __construct(private readonly Main $plugin) {}

    public function onPlayerJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        if($this->plugin->getConfig()->get("settings.debug", false)) {
            $this->plugin->getLogger()->info("Player joined: " . $player->getName());
        }
        $player->sendMessage(TextFormat::colorize(
            $this->plugin->getConfig()->getNested("settings.prefix", "[Plugin] ") . 
            "&aWelcome to the server!"
        ));
    }

    public function onPlayerQuit(PlayerQuitEvent $event): void {
        // Clean up any player-specific resources here
    }
}

// =========================================================
// src/AuthorName/BasicPlugin/command/MainCommand.php
// =========================================================
namespace AuthorName\BasicPlugin\command;

use AuthorName\BasicPlugin\Main;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\PluginOwned;
use pocketmine\plugin\PluginOwnedTrait;
use pocketmine\utils\TextFormat;

final class MainCommand extends Command implements PluginOwned {
    use PluginOwnedTrait;

    public function __construct(private readonly Main $plugin) {
        parent::__construct(
            name: "basicplugin",
            description: "Main command for BasicPlugin",
            usageMessage: "/basicplugin [reload]",
            aliases: ["bp"]
        );
        $this->setPermission("basicplugin.command");
        $this->owningPlugin = $plugin;
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool {
        if(!$this->testPermission($sender)) {
            return false;
        }

        $subCommand = strtolower($args[0] ?? "help");

        match($subCommand) {
            "reload" => $this->handleReload($sender),
            default  => $this->handleHelp($sender),
        };

        return true;
    }

    private function handleReload(CommandSender $sender): void {
        $this->plugin->reloadConfig();
        $msg = (string) $this->plugin->getConfig()->getNested("messages.reload", "[Plugin] Reloaded.");
        $prefix = (string) $this->plugin->getConfig()->getNested("settings.prefix", "[Plugin] ");
        $msg = str_replace("{prefix}", $prefix, $msg);
        $sender->sendMessage(TextFormat::colorize($msg));
    }

    private function handleHelp(CommandSender $sender): void {
        $sender->sendMessage(TextFormat::colorize(
            "&7=== &bBasicPlugin Help &7===\n" .
            "&b/bp reload &7- Reload config"
        ));
    }
}
