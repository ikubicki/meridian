# Implementation Work Log

## Task: Eliminate $phpbb_root_path from Filesystem Operations (Layer 1+2)

---

## Group 1: Layer 1 Bootstrap Requires

### src/phpbb/common/startup.php
- L75: `file_exists($phpbb_root_path . 'vendor/autoload.php')` → `__DIR__ . '/../../../vendor/autoload.php'`
- L83: `require($phpbb_root_path . 'vendor/autoload.php')` → `require(__DIR__ . '/../../../vendor/autoload.php')`

**Status**: ✅ Complete

### src/phpbb/common/common.php (Layer 1 requires)
- L23: startup.php require → `__DIR__ . '/startup.php'`
- L25: Dodano `$phpbb_filesystem_root` (before config_php_file)
- L26: `new config_php_file($phpbb_root_path)` → `new config_php_file($phpbb_filesystem_root)`
- L37: functions.php (inside !PHPBB_INSTALLED) → `__DIR__ . '/functions.php'`
- L54: filesystem.php → `__DIR__ . '/../forums/filesystem/filesystem.php'`
- L79-84: functions.php, functions_content.php, functions_compatibility.php, constants.php, utf_tools.php → `__DIR__` based
- L103: `new container_builder($phpbb_root_path)` → `new container_builder($phpbb_root_path, $phpbb_filesystem_root)`
- L138: compatibility_globals.php → `__DIR__ . '/compatibility_globals.php'`
- L143: hooks/index.php → `__DIR__ . '/hooks/index.php'`
- L151: @include hooks → `__DIR__ . '/hooks/' . $hook . '.php'`

**Status**: ✅ Complete

---

## Group 2: Layer 2 container_builder Internals

### src/phpbb/forums/di/container_builder.php
- Added `protected $filesystem_root_path;` property (L44)
- Updated `__construct` signature: added `$filesystem_root_path = ''` at position 2, `$php_ext` moved to position 3
- Constructor stores `$this->filesystem_root_path = $filesystem_root_path ?: (realpath($phpbb_root_path) . '/');`
- `get_config_path()`: `$this->phpbb_root_path` → `$this->filesystem_root_path`
- `get_cache_dir()`: `$this->phpbb_root_path` → `$this->filesystem_root_path`
- `load_extensions()` L443: `new container_builder($this->phpbb_root_path, $this->php_ext)` → `new container_builder($this->phpbb_root_path, $this->filesystem_root_path, $this->php_ext)`
- `get_core_parameters()`: Added `'core.filesystem_root_path' => $this->filesystem_root_path`
- `register_ext_compiler_pass()`: `->in($this->phpbb_root_path . 'ext')` → `->in($this->filesystem_root_path . 'ext')`

**Status**: ✅ Complete

---

## Group 3: Layer 2 Call Sites

### bin/phpbbcli.php
- L42: `new container_builder($phpbb_root_path)` → `new container_builder($phpbb_root_path, $phpbb_root_path)` (already absolute `__DIR__.'/../'`)

**Status**: ✅ Complete (config_php_file at L28 already receives absolute path — no change needed)

### src/phpbb/forums/install/helper/container_factory.php
- L147: `new container_builder($this->phpbb_root_path, $this->php_ext)` → `new container_builder($this->phpbb_root_path, '', $this->php_ext)`
  (prevents silent assignment of 'php' to $filesystem_root_path after Option B signature change)

**Status**: ✅ Complete

---

## Group 4: web/download/file.php

- L18: `$phpbb_root_path = '../../'` → `'./'` (URL-relative, consistent with web/*.php)
- L19: Added `$phpbb_filesystem_root = defined('PHPBB_FILESYSTEM_ROOT') ? PHPBB_FILESYSTEM_ROOT : realpath(__DIR__ . '/../../') . '/';`
- L36: startup.php require → `$phpbb_filesystem_root`
- L38-39: class_loader require + instantiation → `$phpbb_filesystem_root`
- L42: config_php_file → `$phpbb_filesystem_root`
- L55-58: constants, functions, functions_download, utf_tools → `$phpbb_filesystem_root`
- L61: class_loader_ext → `$phpbb_filesystem_root`
- L65: `new container_builder($phpbb_root_path)` → `new container_builder($phpbb_root_path, $phpbb_filesystem_root)`
- L150: include common.php → `$phpbb_filesystem_root`
- L151: require functions_download → `$phpbb_filesystem_root`

**Status**: ✅ Complete

---

## Verification Checks

- [x] startup.php: only __DIR__-based paths for vendor/autoload.php
- [x] common.php: $phpbb_filesystem_root defined before L25 (config_php_file)
- [x] container_builder: constructor Option B (pos 1: root_path, pos 2: filesystem_root, pos 3: php_ext)
- [x] container_builder L443 self-instantiation updated
- [x] core.filesystem_root_path added to get_core_parameters()
- [x] container_factory.php: '' passed as 2nd arg (prevents silent php_ext corruption)
- [x] file.php: both avatar and non-avatar blocks use $phpbb_filesystem_root

## Group 5: Layer 3 — YAML Services (user-requested, pre-existing errors)

User requested fixing pre-existing language file and routing errors which were Layer 3 concerns.

### services_language.yml
- language.helper.language_file: `%core.root_path%` → `%core.filesystem_root_path%`
- language.loader_abstract: `%core.root_path%` → `%core.filesystem_root_path%`

### All 20 default container YAML files (mass replacement via sed)
65 usages of `%core.root_path%` replaced with `%core.filesystem_root_path%` in:
services.yml, services_attachment.yml, services_auth.yml, services_avatar.yml,
services_console.yml, services_content.yml, services_cron.yml, services_db.yml,
services_files.yml, services_help.yml, services_hook.yml, services_migrator.yml,
services_module.yml, services_notification.yml, services_password.yml,
services_report.yml, services_routing.yml, services_text_formatter.yml,
services_ucp.yml, services_user.yml

**Status**: ✅ Complete — Forum now renders `<title>phpbb vibed - Index page</title>`, no PHP fatal errors.

## Files Changed
1. src/phpbb/common/startup.php
2. src/phpbb/common/common.php
3. src/phpbb/forums/di/container_builder.php
4. bin/phpbbcli.php
5. src/phpbb/forums/install/helper/container_factory.php
6. web/download/file.php
7. src/phpbb/common/config/default/container/services_language.yml
8. 19 other YAML service files (Layer 3 mass replacement)
