---
description: How to use the PocketMine Plugin Dev skill to generate production-grade plugins
---

# PocketMine Plugin Dev — Workflow

## Step 1 — Activate the Skill

Always read `skills/pocketmine-plugin-dev/SKILL.md` before generating any code.
Also read `CLAUDE.md` at the repo root for the condensed rules summary.

## Step 2 — Generate a Plugin

Follow this exact process:

```
1. Classify request → Simple / Medium / Complex tier
2. Match to category playbook (Chat / Economy / PvP / Minigame / Duel / Clan / Admin / World / Utility)
3. Design: file structure → config schema → command tree → event hooks
4. Generate ALL files — no stubs, no TODOs
5. Run the §14 review checklist from SKILL.md
```

## Step 3 — File Output Order

Always output files in this order:
1. `plugin.yml`
2. `resources/config.yml` (with comments on every key — mandatory)
3. `PluginConfig.php`
4. `Main.php`
5. `EventHandler.php` / `EventListener.php`
6. Session + SessionFactory (Complex only)
7. Traits (Complex only)
8. Core subsystems + factories
9. Database layer
10. Commands → Forms → Items → Entities → Utils

## Architecture at a Glance

| Tier | When | What's Generated |
|------|------|-----------------|
| Simple | 1 feature, ≤2 events | Main + Listener + PluginConfig |
| Medium | Multi-feature, player data | + Manager + Session + DB |
| Complex | Multi-subsystem, game modes | + Factory + Traits + Dispatcher + WorldCopy |

## Example Prompts

```
Create a Spleef minigame plugin with arena management, ELO rankings, and scoreboard

Create an economy plugin with /pay, /baltop, MySQL, and a public API

Create a PvP plugin with custom knockback, combat tags, kill streaks, and kit editor

Create a staff mode plugin with vanish, freeze, inventory spy, and chat spy

Create a region protection plugin with AABB selection and per-region flags
```

## Reference Docs

| File | Purpose |
|------|---------|
| `docs/architecture.md` | Deep dive into Factory / Session / Trait patterns |
| `docs/config-system.md` | How to build and extend PluginConfig |
| `docs/events-reference.md` | All PM5 events with when/how to use each |
| `docs/anti-patterns.md` | What NOT to generate and why |
