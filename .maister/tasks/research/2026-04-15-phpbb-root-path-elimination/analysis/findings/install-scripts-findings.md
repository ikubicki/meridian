# Install Scripts — $phpbb_root_path Findings

**Source category**: install-scripts
**Date**: 2026-04-16
**Research question**: How to eliminate `$phpbb_root_path` from filesystem operations in the installer bootstraps?

---

## 1. Entry-Point Chain

```
web/install.php
  └─ define('PHPBB_ROOT_PATH', __DIR__ . '/../')  ← absolute, safe
  └─ require('../src/phpbb/install/app.php')

src/phpbb/install/app.php
  └─ $phpbb_root_path = defined('PHPBB_ROOT_PATH') ? PHPBB_ROOT_PATH : '../../../'
  └─ require($startup_path)   ← dynamic, upgrade-aware

src/phpbb/install/startup.php  (the real bootstrap)
  └─ phpbb_require_updated('src/phpbb/common/startup.php', $phpbb_root_path)
  └─ phpbb_require_updated('src/phpbb/forums/class_loader.php', $phpbb_root_path)
  └─ ... more requires (see §3)

src/phpbb/common/startup.php
  └─ require($phpbb_root_path . 'vendor/autoload.php')
```

---

## 2. How `$phpbb_root_path` Is Set

### web/install.php (line 14)
```php
define('PHPBB_ROOT_PATH', __DIR__ . '/../');
```
**Already absolute** — uses `__DIR__`. Safe.

### src/phpbb/install/app.php (line 20)
```php
$phpbb_root_path = defined('PHPBB_ROOT_PATH') ? PHPBB_ROOT_PATH : '../../../';
```
- When called from `web/install.php` (the normal path), `PHPBB_ROOT_PATH` is already defined as an absolute path.
- The fallback `'../../../'` is relative — only used if someone calls `app.php` directly without the constant, which is not a normal code path.
- **Verdict**: Effectively safe because the constant is always set by `web/install.php`.

---

## 3. Full Inventory of `$phpbb_root_path` Usages

### src/phpbb/install/app.php

| Line | Code | Classification |
|------|------|----------------|
| 20 | `$phpbb_root_path = defined('PHPBB_ROOT_PATH') ? PHPBB_ROOT_PATH : '../../../'` | **[USE-CONSTANT]** reads absolute constant |
| 27 | `$startup_new_path = $phpbb_root_path . 'install/update/update/new/install/startup.php'` | **[KEEP-DYNAMIC]** upgrade path check (runtime selection) |
| 28 | `$startup_path = … $phpbb_root_path . 'src/phpbb/install/startup.php'` | **[KEEP-DYNAMIC]** upgrade path check |
| 38 | `$paths = array($phpbb_root_path . 'install/update/new/adm/style', …)` | **[KEEP-DYNAMIC]** optional upgrade assets |

All uses in `app.php` are either reading the already-absolute constant or performing upgrade-path runtime selection that must remain dynamic.

### src/phpbb/install/startup.php

| Line | Code | Classification |
|------|------|----------------|
| 20–45 | `phpbb_require_updated()` / `phpbb_include_updated()` function params | **[KEEP-DYNAMIC]** — functions accept $phpbb_root_path as param |
| 134–142 | `installer_class_loader($phpbb_root_path)` — 4 `class_loader` instantiations with `$phpbb_root_path` | **[KEEP-DYNAMIC]** — upgrade-aware paths |
| 163 | `$phpbb_root_path = __DIR__ . '/../';` inside `installer_shutdown_function()` | **[USE-__DIR__]** already absolute, local override |
| 168 | `new \phpbb\cache\driver\file(__DIR__ . '/../cache/installer/')` | **[USE-__DIR__]** already absolute |
| 246 | `phpbb_require_updated('src/phpbb/common/startup.php', $phpbb_root_path)` | **[KEEP-DYNAMIC]** upgrade-aware |
| 247 | `phpbb_require_updated('src/phpbb/forums/class_loader.php', $phpbb_root_path)` | **[KEEP-DYNAMIC]** upgrade-aware |
| 249 | `installer_class_loader($phpbb_root_path)` | **[KEEP-DYNAMIC]** upgrade-aware |
| 253 | `$phpbb_admin_path = … $phpbb_root_path . $phpbb_adm_relative_path` | **[KEEP-DYNAMIC]** runtime admin path |
| 256–261 | Six `phpbb_require_updated()` calls for common/ files | **[KEEP-DYNAMIC]** upgrade-aware |
| 272 | `new \phpbb\di\container_builder($phpbb_root_path)` | **[KEEP-DYNAMIC]** DI container |
| 277–278 | `$other_config_path = $phpbb_root_path . 'install/update/new/config'` + fallback | **[KEEP-DYNAMIC]** upgrade-aware |

### src/phpbb/common/startup.php (called via phpbb_require_updated)

| Line | Code | Classification |
|------|------|----------------|
| 75 | `if (!file_exists($phpbb_root_path . 'vendor/autoload.php'))` | **[KEEP-DYNAMIC]** upgrade-aware path |
| 83 | `require($phpbb_root_path . 'vendor/autoload.php')` | **[KEEP-DYNAMIC]** upgrade-aware path |

---

## 4. Key Architectural Observations

### 4a. Installer has its own startup chain — does NOT call common.php
The installer does **not** call `web/common.php`. It has a dedicated:
- `src/phpbb/install/startup.php` (installer bootstrap)
- which calls `phpbb_require_updated('src/phpbb/common/startup.php', …)` (autoloader only, not the full forum init)

### 4b. `phpbb_require_updated()` is the upgrade mechanism
All `$phpbb_root_path` uses inside `startup.php` go through `phpbb_require_updated()` / `phpbb_include_updated()`, which:
1. Checks for a "new" file at `$phpbb_root_path . 'install/update/new/' . $path`
2. Falls back to the canonical path `$phpbb_root_path . $path`

These calls **cannot** be converted to `__DIR__`-based paths because they must resolve to either the upgrade package or the current codebase at runtime.

### 4c. `installer_shutdown_function()` already uses `__DIR__`
The shutdown error handler (line 163) already resets `$phpbb_root_path = __DIR__ . '/../'` locally — confirming the developers know the absolute path and use it when not subject to upgrade selection.

### 4d. `web/install.php` is already safe
`PHPBB_ROOT_PATH` is defined as `__DIR__ . '/../'` — absolute at the entry point. The variable `$phpbb_root_path` in `app.php` line 20 receives this absolute value through the constant.

---

## 5. Is the Installer Already Safe?

**Partially yes, partially no** — but the "no" parts are intentional:

| File | Status | Reason |
|------|--------|--------|
| `web/install.php` | ✅ Safe | Uses `__DIR__ . '/../'` via constant |
| `src/phpbb/install/app.php` line 20 | ✅ Safe | Reads absolute constant from `web/install.php` |
| `src/phpbb/install/startup.php` shutdown fn (line 163) | ✅ Safe | Uses `__DIR__ . '/../'` explicitly |
| `src/phpbb/install/startup.php` all other uses | ⚠️ Dynamic | Intentional — `phpbb_require_updated()` upgrade mechanism |
| `src/phpbb/common/startup.php` lines 75,83 | ⚠️ Dynamic | Called via upgrade-aware require |

The dynamic uses are **not bugs** — they are the upgrade system's intentional runtime path selection.

---

## 6. Proposed Fix: Eliminate the Relative Fallback

The only genuine problem is the relative fallback in `app.php` line 20:

```php
// CURRENT — has relative fallback
$phpbb_root_path = defined('PHPBB_ROOT_PATH') ? PHPBB_ROOT_PATH : '../../../';
```

Since `web/install.php` always defines `PHPBB_ROOT_PATH` before including `app.php`, the fallback is dead code. Replace with:

```php
// PROPOSED — hardened, no relative path
$phpbb_root_path = defined('PHPBB_ROOT_PATH') ? PHPBB_ROOT_PATH : (realpath(__DIR__ . '/../../..') . '/');
```

This makes the fallback absolute using `__DIR__` instead of a relative string, should `app.php` ever be invoked without `web/install.php` (e.g. CLI or tests).

**Exact change** — `src/phpbb/install/app.php` line 20:
```diff
-$phpbb_root_path = defined('PHPBB_ROOT_PATH') ? PHPBB_ROOT_PATH : '../../../';
+$phpbb_root_path = defined('PHPBB_ROOT_PATH') ? PHPBB_ROOT_PATH : (realpath(__DIR__ . '/../../..') . '/');
```

---

## 7. What Should NOT Be Changed

All `phpbb_require_updated()` / `phpbb_include_updated()` calls in `startup.php` must keep using `$phpbb_root_path` — converting them to `__DIR__`-based paths would break the upgrade mechanism by hard-coding the canonical path and preventing file selection from the `install/update/new/` package.

---

## 8. Summary

- The installer bootstrap is **effectively safe** when called normally via `web/install.php` because `PHPBB_ROOT_PATH` is defined as an absolute `__DIR__`-based constant.
- The only actionable fix is hardening the **relative fallback** on `app.php:20`.
- All other `$phpbb_root_path` uses in `startup.php` are **intentional upgrade-aware dynamic paths** — classification: `[KEEP-DYNAMIC]`.
- There are no `[AUTOLOAD-REMOVED]` candidates in the installer (the autoloader is already loaded through `phpbb_require_updated` → `common/startup.php` → `vendor/autoload.php`).
