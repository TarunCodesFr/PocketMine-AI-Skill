# Events Reference — PocketMine-MP 5.42.x

Quick reference of all commonly-used events and when to hook them. For the full API, see the [PMDoc](https://pmmp.github.io/PocketMine-MP/doxygen/index.html).

---

## Player Events

| Event | Namespace | When to Hook |
|-------|-----------|-------------|
| `PlayerLoginEvent` | `event\player` | Session creation, ban/whitelist checks. **Before join — player not in world yet.** |
| `PlayerJoinEvent` | `event\player` | Session warm-up, scoreboard spawn, first-join welcome. |
| `PlayerQuitEvent` | `event\player` | Session teardown, save data, combat-log punishment. |
| `PlayerDeathEvent` | `event\player` | Kill tracking, drop rewards, custom respawn logic. |
| `PlayerRespawnEvent` | `event\player` | Custom respawn position, lobby kit, clear state. |
| `PlayerMoveEvent` | `event\player` | Freeze, region enter/leave, boundary checks. **Keep extremely light — fires 20+/s/player.** |
| `PlayerChatEvent` | `event\player` | Format, filter (anti-swear), rate-limit, channels. |
| `PlayerCommandPreprocessEvent` | `event\player` | Command logging, alias expansion. |
| `PlayerInteractEvent` | `event\player` | NPC clicks, sign actions, custom item right-click, setup wizards. |
| `PlayerItemUseEvent` | `event\player` | Custom item air-click actions. Use `@handleCancelled` for spectators. |
| `PlayerItemConsumeEvent` | `event\player` | Custom potion/food effects, duel rule enforcement (NoDebuff). |
| `PlayerDropItemEvent` | `event\player` | Prevent item drops during game modes. |
| `PlayerExhaustEvent` | `event\player` | Disable hunger drain in game modes. |
| `PlayerKickEvent` | `event\player` | Log kicks, handle combat-log on disconnect. |
| `PlayerChangeSkinEvent` | `event\player` | Block skin changes during games. |

---

## Entity & Combat Events

| Event | Namespace | When to Hook |
|-------|-----------|-------------|
| `EntityDamageEvent` | `event\entity` | Universal damage — check entity type, cancel in lobby, route to context. |
| `EntityDamageByEntityEvent` | `event\entity` | PvP: custom knockback, CPS counter, hit particles, combat tag, kills. |
| `EntityDamageByChildEntityEvent` | `event\entity` | Projectile damage (arrow, trident). Check for self-hit. |
| `EntityDamageByBlockEvent` | `event\entity` | Cancel globally (void/cactus) or per-context. |
| `EntityMotionEvent` | `event\entity` | Precise knockback: two-step flag pattern to cancel PM's own KB. |
| `EntityRegainHealthEvent` | `event\entity` | Cancel saturation regen in PvP modes. |
| `EntityItemPickupEvent` | `event\entity` | Prevent projectile-bounce re-pickup. |
| `EntityTeleportEvent` | `event\entity` | Intercept/cancel teleports in certain game states. |
| `EntitySpawnEvent` | `event\entity` | Track custom entity spawns, manage lifetimes. |
| `EntityDeathEvent` | `event\entity` | Drop custom loot, track mob kills for quests. |

---

## Block & World Events

| Event | Namespace | When to Hook |
|-------|-----------|-------------|
| `BlockBreakEvent` | `event\block` | Region protection, game-mode restrictions, Spleef floor tracking. |
| `BlockPlaceEvent` | `event\block` | Region protection, AABB zone guard, track placed blocks for cleanup. |
| `BlockSpreadEvent` | `event\block` | Disable fire/water spread in arenas. |
| `LeavesDecayEvent` | `event\block` | Disable in game worlds. |
| `EntityTrampleFarmlandEvent` | `event\entity` | Protect lobby farmland. |
| `ExplosionPrimeEvent` | `event\entity` | Cancel TNT in protected zones. |

---

## Inventory & Packet Events

| Event | Namespace | When to Hook |
|-------|-----------|-------------|
| `InventoryTransactionEvent` | `event\inventory` | Block moving plugin "lobby items" to prevent abuse; Kit-edit pass-through. |
| `CraftItemEvent` | `event\inventory` | Disable crafting during game modes. |
| `DataPacketReceiveEvent` | `event\server` | CPS counter (count LevelSoundEventPacket::ATTACK_NODAMAGE), swing animation sync. |
| `DataPacketSendEvent` | `event\server` | Suppress attack sound packets for no-hit-sound modes. |

---

## Server Events

| Event | Namespace | When to Hook |
|-------|-----------|-------------|
| `CommandEvent` | `event\server` | Remap or log server console commands. |
| `DataPacketReceiveEvent` | `event\server` | **Read** packets from client (CPS, swing). |
| `DataPacketSendEvent` | `event\server` | **Intercept** packets sent to client (sounds, movements). |

---

## Event Priorities

```php
// Always declare priority using attribute — never rely on default:
#[Priority(EventPriority::NORMAL)]
public function onDamage(EntityDamageEvent $event): void {}

// For cancel-sensitive handlers (read after other plugins may cancel):
#[Priority(EventPriority::MONITOR)]
#[HandleCancelled]
public function onDamageMonitor(EntityDamageEvent $event): void {}
```

Priority order (lowest to highest priority in execution): `LOWEST → LOW → NORMAL → HIGH → HIGHEST → MONITOR`

Use `MONITOR` only to observe, never to modify.

---

## Handler Annotations

```php
// Handle cancelled events (e.g. CustomItem air-use while spectating):
#[HandleCancelled]
public function onItemUse(PlayerItemUseEvent $event): void {}

// Ignore if already cancelled:
// (default — just don't add #[HandleCancelled])
public function onBreak(BlockBreakEvent $event): void {}
```

---

## Common Event Patterns

### Universal damage guard

```php
public function onDamage(EntityDamageEvent $event): void {
    // 1. Always guard for block damage first
    if ($event instanceof EntityDamageByBlockEvent) {
        $event->cancel();
        return;
    }

    // 2. Only care about players
    $entity = $event->getEntity();
    if (!$entity instanceof Player) return;

    // 3. Session lookup — null means not set up yet
    $session = SessionFactory::get($entity);
    if ($session === null) return;

    // 4. Route to context
    if ($session->isIdle()) {
        $event->cancel(); // Protect lobby
    } elseif (!$session->isIdle()) {
        $session->getArena()?->handleDamage($event);
    }
}
```

### CPS counter via DataPacketReceiveEvent

```php
public function onPacketReceive(DataPacketReceiveEvent $event): void {
    $packet = $event->getPacket();
    if (!$packet instanceof LevelSoundEventPacket) return;
    if ($packet->sound !== LevelSoundEvent::ATTACK_NODAMAGE) return;

    $player  = $event->getOrigin()->getPlayer();
    if ($player === null) return;

    $session = SessionFactory::get($player);
    $session?->addClick();  // Handled in SettingTrait
}
```

### Preventing item drops

```php
public function onDrop(PlayerDropItemEvent $event): void {
    $player  = $event->getPlayer();
    $session = SessionFactory::get($player);
    if ($session === null) return;

    // Block drops in arenas, duels, etc.
    if (!$session->isIdle()) {
        $event->cancel();
    }
}
```

### Inventory transaction guard (protect lobby items)

```php
public function onTransaction(InventoryTransactionEvent $event): void {
    foreach ($event->getTransaction()->getActions() as $action) {
        if ($action instanceof SlotChangeAction) {
            $item = $action->getSourceItem();
            // Check for your plugin's NBT tag
            if ($item->getNamedTag()->getTag('my_plugin_item') !== null) {
                $event->cancel();
                return;
            }
        }
    }
}
```
