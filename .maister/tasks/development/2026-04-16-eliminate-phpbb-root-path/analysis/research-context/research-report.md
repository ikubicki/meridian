# Research Report: Eliminate $phpbb_root_path from Filesystem Operations

**Date**: 2026-04-15 | **Confidence**: High (Layer 1+2), Medium (Layer 3)

---

## Executive Summary

- **Root cause**: `$phpbb_root_path = './'` is URL-relative; when used as filesystem prefix with CWD=`/var/www/phpbb/web/`, all `./src/phpbb/...` paths resolve to the wrong location.
- **Layer 1 fix (immediate, 2 files)**: Replace `$phpbb_root_path` in `common.php` and `startup.php` with `__DIR__`-based absolute paths. Fixes the fatal error. Zero autoload involvement — files are procedural.
- **Layer 2 fix (medium, 1 file)**: `container_builder` uses `$phpbb_root_path` for config path, cache dir, ext compiler pass — must receive `PHPBB_FILESYSTEM_ROOT` as a second argument.
- **Layer 3 (larger, deferred)**: ~60 DI YAML usages of `%core.root_path%` for filesystem ops need a new `core.filesystem_root_path` parameter. Not needed for the forum to function — these work because the container is built with the correct path after Layer 2 is fixed.

---

## Layer 1: Bootstrap Chain (`common.php` + `startup.php`)

### `src/phpbb/common/startup.php`

`__DIR__` here = `/var/www/phpbb/src/phpbb/common`

| Line | Current (broken) | Replace with |
|------|-----------------|--------------|
| 75 | `if (!file_exists($phpbb_root_path . 'vendor/autoload.php'))` | `if (!file_exists(__DIR__ . '/../../../vendor/autoload.php'))` |
| 83 | `require($phpbb_root_path . 'vendor/autoload.php');` | `require(__DIR__ . '/../../../vendor/autoload.php');` |

After this change: `startup.php` no longer reads `$phpbb_root_path` at all.

---

### `src/phpbb/common/common.php`

`__DIR__` here = `/var/www/phpbb/src/phpbb/common`

| Line | Current (broken) | Replace with |
|------|-----------------|--------------|
| 23 | `require($phpbb_root_path . 'src/phpbb/common/startup.php');` | `require(__DIR__ . '/startup.php');` |
| 36 | `require($phpbb_root_path . 'src/phpbb/common/functions.php');` *(in !PHPBB_INSTALLED block)* | `require(__DIR__ . '/functions.php');` |
| 53 | `require($phpbb_root_path . 'src/phpbb/forums/filesystem/filesystem.php');` *(in !PHPBB_INSTALLED block)* | `require(__DIR__ . '/../forums/filesystem/filesystem.php');` |
| 78 | `require($phpbb_root_path . 'src/phpbb/common/functions.php');` | `require(__DIR__ . '/functions.php');` |
| 79 | `require($phpbb_root_path . 'src/phpbb/common/functions_content.php');` | `require(__DIR__ . '/functions_content.php');` |
| 80 | `include($phpbb_root_path . 'src/phpbb/common/functions_compatibility.php');` | `include(__DIR__ . '/functions_compatibility.php');` |
| 82 | `require($phpbb_root_path . 'src/phpbb/common/constants.php');` | `require(__DIR__ . '/constants.php');` |
| 83 | `require($phpbb_root_path . 'src/phpbb/common/utf/utf_tools.php');` | `require(__DIR__ . '/utf/utf_tools.php');` |
| 137 | `require($phpbb_root_path . 'src/phpbb/common/compatibility_globals.php');` | `require(__DIR__ . '/compatibility_globals.php');` |
| 142 | `require($phpbb_root_path . 'src/phpbb/common/hooks/index.php');` | `require(__DIR__ . '/hooks/index.php');` |
| 150 | `@include($phpbb_root_path . 'src/phpbb/common/hooks/' . $hook . '.php');` | `@include(__DIR__ . '/hooks/' . $hook . '.php');` |

**Note**: `$phpbb_root_path` remains in `common.php` — it is still passed to `container_builder`, `config_php_file`, `class_loader_ext`. These are Layer 2+3 concerns.

---

## Layer 2: `container_builder` Filesystem Uses

`container_builder` uses `$phpbb_root_path` (= `'./'`) for 3 internal filesystem operations before building the container:

```php
// get_config_path() — broken with './'
$this->phpbb_root_path . 'src/phpbb/common/config'

// get_cache_dir() — broken with './'
$this->phpbb_root_path . 'cache/' . PHPBB_ENVIRONMENT . '/'

// register_ext_compiler_pass() — broken with './'
->in($this->phpbb_root_path . 'ext')
```

### Fix: Add `$filesystem_root` parameter to `container_builder`

**In `common.php`**, change the construction:
```php
// FROM:
$phpbb_container_builder = new \phpbb\di\container_builder($phpbb_root_path);

// TO:
$phpbb_filesystem_root = defined('PHPBB_FILESYSTEM_ROOT') ? PHPBB_FILESYSTEM_ROOT : realpath($phpbb_root_path) . '/';
$phpbb_container_builder = new \phpbb\di\container_builder($phpbb_root_path, $phpbb_filesystem_root);
```

**In `src/phpbb/forums/di/container_builder.php`**, update `__construct()`:
```php
// FROM:
public function __construct(string $phpbb_root_path, string $php_ext = 'php')

// TO:
public function __construct(string $phpbb_root_path, string $filesystem_root_path = '', string $php_ext = 'php')
```

Store as `$this->filesystem_root_path` (defaulting to `$phpbb_root_path` if empty for backward compatibility) and use it in `get_config_path()`, `get_cache_dir()`, `register_ext_compiler_pass()`.

Also `config_php_file` needs the same treatment — pass `PHPBB_FILESYSTEM_ROOT`:
```php
// FROM:
$phpbb_config_php_file = new \phpbb\config_php_file($phpbb_root_path);

// TO:
$phpbb_config_php_file = new \phpbb\config_php_file($phpbb_filesystem_root);
```

---

## Layer 3: DI Services (Deferred)

35+ services use `%core.root_path%` for filesystem operations (hook_finder, language.*, ext.manager, finder, avatar.driver.*, cron.*, auth.provider.*, migrator.*).

After Layer 2 is fixed (container_builder receives `$filesystem_root_path`), add a second DI parameter:

```php
// In container_builder::get_core_parameters():
'core.root_path' => $this->phpbb_root_path,           // URL-relative './' — unchanged
'core.filesystem_root_path' => $this->filesystem_root_path,  // absolute /var/www/phpbb/
```

Then in YAML service files, switch filesystem services from `%core.root_path%` to `%core.filesystem_root_path%`. This is ~35 changes across 8 YAML files.

**This layer is NOT needed to fix the current fatal error — defer it.**

---

## Risk Assessment

| Change | Risk | Mitigation |
|--------|------|-----------|
| `__DIR__` in startup.php (2 lines) | Low | Pure path arithmetic, easily verified |
| `__DIR__` in common.php (10 lines) | Low | Same directory, no logic change |
| `container_builder` 2nd param | Medium | Default empty = backward compat; phpbbcli.php also instantiates it |
| `config_php_file` receives absolute path | Low | File path becomes absolute — works in all cases |
| Layer 3 YAML changes (35+ services) | Medium-High | Large surface; each service must be tested |

---

## Recommended Execution Order

### Step 1 — Fix `startup.php` (2 lines, immediate)
Replace `$phpbb_root_path` with `__DIR__ . '/../../../'` for vendor/autoload.php checks.

### Step 2 — Fix `common.php` bootstrap requires (10 lines, immediate)
Replace all `$phpbb_root_path . 'src/phpbb/common/*'` with `__DIR__ . '/*'`.
Replace `src/phpbb/forums/filesystem/filesystem.php` with `__DIR__ . '/../forums/filesystem/filesystem.php'`.
Replace `hooks/$hook.php` dynamic include with `__DIR__ . '/hooks/' . $hook . '.php'`.

### Step 3 — Fix `container_builder` (add `$filesystem_root_path` param)
Update `__construct()` signature, store, use in `get_config_path()`, `get_cache_dir()`, `register_ext_compiler_pass()`.
Update call site in `common.php` and `bin/phpbbcli.php`.

### Step 4 — Fix `config_php_file` construction
Pass `$phpbb_filesystem_root` (absolute) instead of `$phpbb_root_path` (URL-relative).

### Step 5 (deferred) — YAML service migration
Add `core.filesystem_root_path` to container parameters. Switch filesystem services.

---

## Files Changed: Steps 1-4

- `src/phpbb/common/startup.php` — 2 lines
- `src/phpbb/common/common.php` — ~12 lines
- `src/phpbb/forums/di/container_builder.php` — `__construct()` + `get_config_path()` + `get_cache_dir()` + `register_ext_compiler_pass()` (~8 lines)
- `bin/phpbbcli.php` — update container_builder construction call
