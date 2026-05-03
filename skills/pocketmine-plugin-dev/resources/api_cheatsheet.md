# PocketMine-MP 5.x API Quick Cheatsheet

**Server:** 5.42.x | **MC Bedrock:** 1.26.10 | **PHP:** 8.2 | **API:** `["5.0.0"]`

## Namespaces Index
```
pocketmine\              — Root namespace
pocketmine\block\        — Block types (VanillaBlocks)
pocketmine\command\      — Command, CommandSender, CommandMap
pocketmine\crafting\     — Crafting recipes
pocketmine\data\         — Data enums (GameMode, etc.)
pocketmine\entity\       — Entity, Living, Human
pocketmine\event\        — All events + Listener interface
pocketmine\form\         — Form interface
pocketmine\inventory\    — Inventory types
pocketmine\item\         — Item, VanillaItems, Enchantments
pocketmine\math\         — Vector3, AxisAlignedBB, Math utils
pocketmine\network\mcpe\ — RAW packet access (advanced)
pocketmine\player\       — Player, GameMode, PlayerInfo
pocketmine\plugin\       — PluginBase, PluginOwned, Scheduler
pocketmine\scheduler\    — Task, ClosureTask, AsyncTask
pocketmine\utils\        — Config, TextFormat, Utils
pocketmine\world\        — World, Position, Location, Chunk
pocketmine\world\particle\ — All particle types
pocketmine\world\sound\  — All sound types
```

## GameMode Enum
```php
use pocketmine\player\GameMode;

GameMode::SURVIVAL
GameMode::CREATIVE
GameMode::ADVENTURE
GameMode::SPECTATOR
```

## TextFormat Colors (§ codes)
```php
use pocketmine\utils\TextFormat;

TextFormat::colorize("&aGreen &bAqua &cRed");
// Manual: §0=Black §1=DarkBlue §2=DarkGreen §3=DarkAqua
//         §4=DarkRed §5=DarkPurple §6=Gold §7=Gray
//         §8=DarkGray §9=Blue §a=Green §b=Aqua
//         §c=Red §d=LightPurple §e=Yellow §f=White
//         §l=Bold §m=Strike §n=Underline §o=Italic §r=Reset
```

## Common VanillaItems
```php
use pocketmine\item\VanillaItems;

VanillaItems::DIAMOND_SWORD()
VanillaItems::DIAMOND_CHESTPLATE()
VanillaItems::GOLDEN_APPLE()
VanillaItems::ARROW()
VanillaItems::BOW()
VanillaItems::ENDER_PEARL()
VanillaItems::DIAMOND_PICKAXE()
VanillaItems::AIR()         // Empty slot
```

## Common VanillaBlocks
```php
use pocketmine\block\VanillaBlocks;

VanillaBlocks::STONE()
VanillaBlocks::GRASS()
VanillaBlocks::AIR()
VanillaBlocks::GLASS()
VanillaBlocks::OBSIDIAN()
VanillaBlocks::BEDROCK()
VanillaBlocks::BARRIER()
```

## Common VanillaEnchantments
```php
use pocketmine\item\enchantment\VanillaEnchantments;

VanillaEnchantments::SHARPNESS()
VanillaEnchantments::PROTECTION()
VanillaEnchantments::EFFICIENCY()
VanillaEnchantments::UNBREAKING()
VanillaEnchantments::FIRE_ASPECT()
VanillaEnchantments::KNOCKBACK()
VanillaEnchantments::INFINITY()
VanillaEnchantments::MENDING()
VanillaEnchantments::SILK_TOUCH()
VanillaEnchantments::FORTUNE()
```

## Effect API
```php
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\effect\EffectInstance;

$effect = new EffectInstance(
    type: VanillaEffects::SPEED(),
    duration: 200,   // ticks (200 = 10 seconds)
    amplifier: 1,    // level - 1 (0 = level I, 1 = level II)
    visible: false   // show particles?
);
$player->getEffects()->add($effect);
$player->getEffects()->remove(VanillaEffects::SPEED());
$player->getEffects()->clear();
```

## Common Effects
```php
VanillaEffects::SPEED()
VanillaEffects::SLOWNESS()
VanillaEffects::STRENGTH()
VanillaEffects::RESISTANCE()
VanillaEffects::FIRE_RESISTANCE()
VanillaEffects::REGENERATION()
VanillaEffects::SATURATION()
VanillaEffects::INVISIBILITY()
VanillaEffects::NIGHT_VISION()
VanillaEffects::WEAKNESS()
VanillaEffects::POISON()
VanillaEffects::NAUSEA()
VanillaEffects::JUMP_BOOST()
VanillaEffects::ABSORPTION()
VanillaEffects::HEALTH_BOOST()
```

## Scheduling Quick Reference
```php
// One-time delayed task
$this->getScheduler()->scheduleDelayedTask(new ClosureTask(fn() => doThing()), 20);

// Repeating task (cancel by storing handler)
$handler = $this->getScheduler()->scheduleRepeatingTask(new MyTask(), 20);
$handler->cancel(); // to stop

// Async (thread-safe)
Server::getInstance()->getAsyncPool()->submitTask(new MyAsyncTask());
```

## Server Instance Access
```php
use pocketmine\Server;

// From PluginBase subclass:
$this->getServer()

// From anywhere (static — use sparingly):
Server::getInstance()

// Get player by name:
$player = $server->getPlayerExact("PlayerName");
// Get all online players:
$players = $server->getOnlinePlayers(); // Player[]
```

## Chunk Loading
```php
// Always check chunk is loaded before block operations
if($world->isChunkLoaded($chunkX, $chunkZ)) {
    $block = $world->getBlockAt($x, $y, $z);
}
// Force load (use carefully — can cause lag)
$world->loadChunk($chunkX, $chunkZ);
```

## Nametag Color Tricks
```php
$player->setNameTag("§6[VIP] §e" . $player->getName());
$player->setDisplayName("§e" . $player->getName()); // in chat
$player->setScoreTag("§7" . $score . " kills");     // below nametag
```

## Title/Subtitle/Actionbar
```php
// Title + Subtitle
$player->sendTitle(
    title: "§aGame Start!",
    subtitle: "§7Fight to win!",
    fadeIn: 10,   // ticks
    stay: 40,     // ticks  
    fadeOut: 10   // ticks
);

// Actionbar (above hotbar)
$player->sendActionBarMessage("§eHP: §c" . $player->getHealth());

// Tip (same as popup, bottom of screen)
$player->sendTip("§7Press §fSNEAK §7to activate...");

// Popup (above hotbar, slightly different position)
$player->sendPopup("§aYou earned §6100 coins!");
```

## Event Cancellability
```php
// Check if event can be cancelled
if($event instanceof Cancellable) {
    $event->cancel();
    // or
    $event->setCancelled(true);
}

// Check if already cancelled
if($event->isCancelled()) return;
```

## Metadata / Custom Data on Entities (PM5 way)
```php
// PM5 does NOT have the old metadata system for plugins.
// Use WeakMap or external session maps instead:
/** @var \WeakMap<Player, PlayerSession> */
private \WeakMap $sessions;

public function getSession(Player $player): PlayerSession {
    return $this->sessions[$player] ??= new PlayerSession($player);
}
```
