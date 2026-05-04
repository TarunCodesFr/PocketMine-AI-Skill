# Anti-Patterns Reference

This document lists what **not** to generate. Every item here represents a real bug, memory leak, or incompatibility that appears in low-quality PocketMine plugins.

---

## 🔴 Critical (Will break the plugin)

### `api: "5.0.0"` as a plain string

```yaml
# ❌ WRONG — PocketMine rejects plugins with this format
api: "5.0.0"

# ✅ CORRECT
api: ["5.0.0"]
```

**Why:** PocketMine-MP requires the `api` field to be a YAML array. A plain string makes the server refuse to load the plugin entirely.

---

### Registering custom entities in `onEnable()`

```php
// ❌ WRONG — entity types may already be needed before enable
protected function onEnable(): void {
    EntityFactory::getInstance()->register(EnderPearl::class, ...);
}

// ✅ CORRECT
protected function onLoad(): void {
    EntityFactory::getInstance()->register(EnderPearl::class, ...);
}
```

**Why:** `onLoad()` runs before any world is loaded. Registering in `onEnable()` can miss entities that spawn from saved worlds.

---

### Accessing Server/World/Player inside `AsyncTask::onRun()`

```php
// ❌ WRONG — crashes the worker thread
public function onRun(): void {
    $player = Server::getInstance()->getPlayerExact($this->name);  // CRASH
    $player->sendMessage("Done");
}

// ✅ CORRECT — schedule back to main thread
public function onRun(): void {
    $result = $this->doHeavyWork();
    $this->setResult($result);
}

public function onCompletion(): void {
    // Main thread — safe to access Server, Player, World
    $player = Server::getInstance()->getPlayerExact($this->name);
    $player?->sendMessage($this->getResult());
}
```

**Why:** Worker threads do not have access to PM's main-thread objects. Any access will cause a fatal crash or undefined behavior.

---

### Blocking DB queries on the main thread

```php
// ❌ WRONG — freezes the server for every player
public function onPlayerJoin(PlayerJoinEvent $event): void {
    $pdo = new \PDO("mysql:host=localhost;dbname=mc", "user", "pass");
    $stmt = $pdo->query("SELECT * FROM players WHERE xuid = '{$event->getPlayer()->getXuid()}'");
    // ... server is frozen while waiting for DB
}

// ✅ CORRECT — async via task pool
public function onPlayerJoin(PlayerJoinEvent $event): void {
    Server::getInstance()->getAsyncPool()->submitTask(
        new LoadPlayerDataTask($event->getPlayer()->getXuid())
    );
}
```

---

## 🟠 Memory / Safety Issues

### Storing `Player` objects in long-lived arrays

```php
// ❌ WRONG — dangling reference after disconnect; memory leak
class CombatManager {
    private array $tagged = [];  // Player[] by name

    public function tag(Player $player): void {
        $this->tagged[$player->getName()] = $player;  // LEAK
    }
}

// ✅ CORRECT — store xuid (string); recover Player on demand
class CombatManager {
    private array $tagged = [];  // string[] xuid → timestamp

    public function getPlayer(string $xuid): ?Player {
        return Server::getInstance()->getPlayerByXuid($xuid);
    }
}
```

**Why:** When a player disconnects, the `Player` object is destroyed. An array holding a reference to it keeps it alive in memory forever (memory leak) and any method call on it throws an error.

---

### Using player name as a database primary key

```php
// ❌ WRONG — players can change their names; causes orphaned data
INSERT INTO economy (name, balance) VALUES (?, ?)

// ✅ CORRECT — XUID never changes
INSERT INTO economy (xuid, username, balance) VALUES (?, ?, ?)
```

---

### Storing `World` objects as long-lived class properties

```php
// ❌ WRONG — World can be unloaded; property becomes a dead reference
class Arena {
    private World $world;  // Dangerous
}

// ✅ CORRECT — store the world name; re-fetch SafeToAutoRun
class Arena {
    private string $worldName;

    public function getWorld(): ?World {
        return Server::getInstance()->getWorldManager()->getWorldByName($this->worldName);
    }
}
```

---

## 🟡 Code Quality Issues

### Wildcard imports

```php
// ❌ WRONG — ambiguous; can shadow PM classes; poor IDE support
use pocketmine\*;

// ✅ CORRECT — explicit, one per line
use pocketmine\player\Player;
use pocketmine\event\Listener;
```

---

### Logic in `Main.php`

```php
// ❌ WRONG — Main becomes a 500-line god class
final class MyPlugin extends PluginBase {
    public function calculateElo(int $winner, int $loser): array {
        // ...business logic here...
    }
}

// ✅ CORRECT — Main only bootstraps; logic lives in managers
final class MyPlugin extends PluginBase {
    private EloManager $eloManager;

    protected function onEnable(): void {
        $this->eloManager = new EloManager($this->pluginConfig);
    }
}
```

---

### Direct `getConfig()` calls outside `PluginConfig`

```php
// ❌ WRONG — scattered config access; hard to refactor; no type safety
class CombatListener implements Listener {
    public function onDamage(EntityDamageEvent $event): void {
        $duration = MyPlugin::getInstance()->getConfig()->get('combat-tag.duration', 15);
    }
}

// ✅ CORRECT — all config goes through the typed wrapper
class CombatListener implements Listener {
    public function __construct(private readonly PluginConfig $config) {}

    public function onDamage(EntityDamageEvent $event): void {
        $duration = $this->config->getCombatTagDuration();
    }
}
```

---

### Missing `declare(strict_types=1)`

```php
// ❌ WRONG — implicit coercions cause subtle bugs
<?php
namespace Author\MyPlugin;

// ✅ CORRECT
<?php
declare(strict_types=1);
namespace Author\MyPlugin;
```

---

### Debug code left in production

```php
// ❌ Never ship these
var_dump($player->getInventory()->getContents());
print_r($session);
echo "DEBUG: " . $value;

// ✅ Use the logger with debug mode check
$this->config->debug("Session state: " . json_encode($data));
// Only printed when debug: true in config.yml
```

---

### Not checking `isOnline()` in delayed tasks

```php
// ❌ WRONG — player may have disconnected during the delay
$plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player): void {
    $player->sendMessage("Done!");  // Player may be offline!
}), 60);

// ✅ CORRECT
$name = $player->getName();
$plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($name): void {
    $p = Server::getInstance()->getPlayerExact($name);
    $p?->sendMessage("Done!");
}), 60);
```

---

### Heavy computation in `PlayerMoveEvent`

```php
// ❌ WRONG — fires 20+ times per second per player; DB call = server freeze
public function onMove(PlayerMoveEvent $event): void {
    $player = $event->getPlayer();
    $db = new \SQLite3("data.db");  // ABSOLUTELY NOT
    $db->query("SELECT * FROM regions WHERE ...");
}

// ✅ CORRECT — pre-cache regions; only check AABB in memory
public function onMove(PlayerMoveEvent $event): void {
    $player = $event->getPlayer();
    foreach ($this->regionManager->getAll() as $region) {
        if ($region->contains($player->getPosition())) {
            // Only in-memory AABB check — very fast
            $this->handleRegionEnter($player, $region);
        }
    }
}
```

---

### Stub/placeholder code in output

```php
// ❌ NEVER generate these
public function handleDamage(EntityDamageEvent $event): void {
    // TODO: implement damage handling
}

// ✅ Always provide a complete, working implementation
public function handleDamage(EntityDamageEvent $event): void {
    if (!$this->isRunning()) { $event->cancel(); return; }
    if (($event->getEntity()->getHealth() - $event->getFinalDamage()) <= 0) {
        $event->cancel();
        $this->finish($event->getEntity());
    }
}
```
