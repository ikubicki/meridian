# Dynamic Includes Findings

**Category**: dynamic-includes
**Date**: 2026-04-15
**Research Question**: How can all `require`/`include` statements in the phpBB codebase be replaced with Composer PSR-4 autoloading?

---

## Summary

The phpBB codebase uses `require`/`include` extensively for two fundamentally different purposes:
1. **Bootstrapping procedural files** — large global-function files that cannot be autoloaded by PSR-4 (no class, or global state they export)
2. **Truly dynamic/runtime includes** — paths computed at runtime, cache files, convertor plugins, external config files, and hooks

Both categories are MUST-KEEP: neither can be replaced purely by PSR-4 autoloading.

---

## 1. MUST-KEEP Requires — Category: Bootstrap (Static, but procedural)

These files are always required once per request via `common.php` or an entry point. They define global functions and constants — **not classes** — so PSR-4 cannot help. They CAN be moved to Composer `files[]` autoload.

| File | Loaded In | Reason |
|------|-----------|--------|
| `src/phpbb/common/startup.php` | `common.php:23`, `install/startup.php` | Bootstraps error levels, timezone, Composer autoloader |
| `src/phpbb/common/functions.php` | `common.php:82` | ~2800 lines of global helper functions (`msg_handler`, `redirect`, etc.) |
| `src/phpbb/common/functions_content.php` | `common.php:83` | Global content-rendering helpers; at module-scope also includes bbcode.php and message_parser.php |
| `src/phpbb/common/constants.php` | `common.php:86` | `define()` calls — cannot be autoloaded |
| `src/phpbb/common/utf/utf_tools.php` | `common.php:87` | Global UTF-8 helper functions |
| `src/phpbb/common/compatibility_globals.php` | `common.php:142` | Registers global compatibility aliases |
| `src/phpbb/common/hooks/index.php` | `common.php:147` | Defines `phpbb_hook` class (non-namespaced legacy class) and hook mechanism |
| `src/phpbb/forums/class_loader.php` | `common.php:24` | Non-namespaced `phpbb\class_loader` legacy class loader |
| `src/phpbb/common/functions_acp.php` | `web/adm/index.php:23` | ACP-only global functions |
| `src/phpbb/common/functions_admin.php` | `web/adm/index.php:24`, `mcp.php:21` | Admin global functions |
| `src/phpbb/common/functions_module.php` | `web/adm/index.php:25`, `ucp.php:22`, `mcp.php:23` | Module-system global functions |
| `src/phpbb/common/functions_posting.php` | `web/posting.php:21` | Posting global functions |
| `src/phpbb/common/functions_display.php` | `web/index.php:24`, `viewforum.php:21`, `viewtopic.php:21`, etc. | Display global functions |
| `src/phpbb/common/functions_mcp.php` | `web/mcp.php:22` | MCP global functions |
| `src/phpbb/common/functions_user.php` | `web/ucp.php:21` | User management global functions |
| `src/phpbb/common/message_parser.php` | `web/posting.php:23`, inside `functions_content.php` | Message parser (includes `bbcode.php` at module-scope) |
| `src/phpbb/common/bbcode.php` | Inside `functions_content.php` and `message_parser.php` | BBcode class (non-namespaced) + global helpers |
| `src/phpbb/common/functions_compatibility.php` | `common.php` via `include()` | Backward-compat function shims |

---

## 2. MUST-KEEP Requires — Category: Truly Dynamic

These cannot be replaced because the path or file is unknown at compile time.

### 2a. Runtime Config File Load

**File**: `src/phpbb/forums/config_php_file.php`, line 107
```php
require($this->config_file);
// $this->config_file defaults to src/phpbb/common/config/config.php but is overrideable
```
**Reason**: The config file path is injectable. During installer it points to a freshly-written file. **MUST-KEEP** — it loads PHP variables into scope (`$dbms`, `$dbhost`, etc.) that become available after `extract()`.

---

### 2b. Composer/DI Container Compiled Cache

**File**: `src/phpbb/forums/di/container_builder.php`, lines 165, 173, 484
```php
require($this->get_autoload_filename());   // Composer DI extension autoload
require($config_cache->getPath());          // Symfony compiled container cache
```
**File**: `src/phpbb/forums/routing/router.php`, lines 216, 271
```php
require_once($cache->getPath());            // Compiled URL matcher/generator
```
**Reason**: Paths are computed at runtime from cache directory. The files themselves are PHP code generated/compiled at cache-warm time. **MUST-KEEP** — this is the standard Symfony cache pattern.

---

### 2c. Textformatter S9e Compiled Renderer Cache

**File**: `src/phpbb/forums/textformatter/s9e/renderer.php`, line 83
```php
$cache_file = $cache_dir . $class . '.php';
include($cache_file);
```
**Reason**: The rendered class name is determined at runtime from the `$class` variable. Cache files are generated PHP. **MUST-KEEP**.

---

### 2d. Hook Files (Runtime-Discovered)

**File**: `src/phpbb/common/common.php`, line 155
```php
foreach ($phpbb_hook_finder->find() as $hook)
{
    @include($phpbb_root_path . 'src/phpbb/common/hooks/' . $hook . '.php');
}
```
**Reason**: Hook file names are discovered at runtime by `\phpbb\hook\finder` (which scans the filesystem and cache). File names are a variable. `@include` silently skips missing hooks. **MUST-KEEP** — this is phpBB's legacy mod/hook extension point.
- Currently the `hooks/` directory only contains `index.php` (the `phpbb_hook` class), but the loop is ready for additional hook files.

---

### 2e. Install Startup — Dual-Path (new/old file selection)

**File**: `src/phpbb/install/startup.php`, lines 20–46
```php
function phpbb_require_updated($path, $phpbb_root_path, $optional = false)
{
    $new_path = $phpbb_root_path . 'install/update/new/' . $path;
    $old_path = $phpbb_root_path . $path;
    if (file_exists($new_path))
        require($new_path);   // prefer updated file during upgrades
    else if (!$optional || file_exists($old_path))
        require($old_path);
}
```
**Reason**: During upgrades, phpBB prefers files from `install/update/new/` over the current codebase files. This runtime path selection cannot be autoloaded. **MUST-KEEP**.
- Used to load: `startup.php`, `class_loader.php`, `compatibility_globals.php`, `functions.php`, `functions_content.php`, `functions_user.php`.

---

### 2f. Module Manager Legacy Info-Class Files

**File**: `src/phpbb/forums/module/module_manager.php`, line 154
```php
include($directory . $old_info_class_file . '.' . $this->php_ext);
```
where `$old_info_class_file` is derived by stripping a prefix from `$cur_module`.

**Reason**: ACP/MCP/UCP module info classes may follow the legacy non-namespaced naming (`{module}_info`) and live in various `$directory` locations computed at runtime. Class name and directory are both dynamic. **MUST-KEEP** as fallback for legacy modules.

---

### 2g. Convertor Plugin Files (Install/Convert)

**File**: `src/phpbb/install/convert/convertor.php`, lines 187–200
```php
include_once('./convertors/functions_' . $convert->convertor_tag . '.php');
include('./convertors/convert_' . $convert->convertor_tag . '.php');
```
**File**: `src/phpbb/install/convert/controller/convertor.php`, line 290, 727
```php
include_once($convertor_file_path);        // user-selected convertor
include_once($phpbb_root_path . 'src/phpbb/install/convertors/' . $entry);
```
**Reason**: The convertor tag/name comes from user input (form POST). Convertor files are plugin-like. **MUST-KEEP**.

---

### 2h. Update Helper — Dynamic File List During Upgrades

**File**: `src/phpbb/forums/install/helper/update_helper.php`, lines 77–81
```php
include_once($this->path_to_new_files . $filename);
include_once($this->phpbb_root_path . $filename);
```
where `$filename` is iterated from an array of files to copy/include during an upgrade.
**Reason**: Runtime-computed paths over a variable list of upgrade files. **MUST-KEEP**.

---

### 2i. Language Files

**File**: `src/phpbb/install/convertors/functions_phpbb20.php`, line 416
```php
include($convert->options['forum_path'] . '/language/lang_' . $get_lang . '/lang_main.php');
```
**File**: `src/phpbb/common/mcp/mcp_warn.php`, line 539
```php
include($phpbb_root_path . 'src/phpbb/language/' . basename($user_row['user_lang']) . '/mcp.php');
```
**File**: `src/phpbb/common/mcp/mcp_queue.php`, line 1373
```php
@include($phpbb_root_path . '/language/' . basename($post_data['user_lang']) . '/mcp.php');
```
**Reason**: Language is chosen from user preferences/DB. Language files are PHP arrays (`$lang = [...]`) — not classes — and cannot be PSR-4 autoloaded. The path contains `basename()` from user data (minimal sanitization via `basename()`). **MUST-KEEP**.

---

### 2j. Sphinx API (Conditional Load)

**File**: `src/phpbb/forums/search/fulltext_sphinx.php`, line 156
```php
require($this->phpbb_root_path . 'src/phpbb/common/sphinxapi.php');
```
**Reason**: Loaded only when Sphinx search is used. The file is a 3rd-party procedural Sphinx client API. It defines a `SphinxClient` class but without a namespace, so PSR-4 cannot map it. **MUST-KEEP or migrate to a Composer package**.

---

### 2k. UTF Data Files (Lazy-Loaded on First Use)

**Files**: `src/phpbb/common/utf/data/recode_basic.php`, `recode_cjk.php`, `case_fold_c.php`, `case_fold_f.php`, `case_fold_s.php`, `confusables.php`

**File**: `src/phpbb/forums/passwords/driver/md5_phpbb2.php`, line 110
```php
include($this->phpbb_root_path . 'src/phpbb/common/utf/data/recode_basic.' . $this->php_ext);
```
**File**: `src/phpbb/forums/search/fulltext_native.php`, line 134
```php
include($this->phpbb_root_path . 'src/phpbb/common/utf/utf_tools.php');
```
**Reason**: Large data-only PHP arrays, lazy-loaded only when needed. Not classes. **MUST-KEEP as `files[]` entries or lazy-load pattern**.

---

### 2l. Migration Data — Conditional Includes of Helper Files

Inside migrations in `src/phpbb/forums/db/migration/data/`, includes like:
```php
include($this->phpbb_root_path . 'src/phpbb/common/acp/auth.' . $this->php_ext);
include($this->phpbb_root_path . 'src/phpbb/common/acp/acp_bbcodes.' . $this->php_ext);
```
**Reason**: Migration classes run in bootstrap-free context and need procedural helpers. Loading them at the top of `common.php` would be wasteful; they load on demand. While these files define classes, they're non-namespaced legacy ACP files. **MUST-KEEP until those ACP files are namespaced**.

---

## 3. Language File Loading — Analysis

phpBB loads language files **entirely via procedural includes** of PHP files that set `$lang[]` array entries.

**Pattern**:
- Entry point (`common.php`) initializes `$user` object
- `$user->setup()` calls `$user->add_lang()` which resolves `src/phpbb/language/{user_lang}/{file}.php`
- Files are `include()`d, dumping into `$lang` global
- Language extensions can add language files from `ext/vendor/name/language/`

**Can language files use autoload?** No. They are data files (PHP arrays), not classes. They require the `$lang` global to exist. The only viable alternative would be converting them to a class-based or JSON format — a major refactor out of scope.

---

## 4. Extension Loading — Analysis

phpBB's extension system (`\phpbb\extension\manager`) uses the **Symfony DI service container** and PSR-4 through the existing `\phpbb\class_loader` (registered for `\\` namespace under `ext/`). Extensions themselves are proper PSR-4 classes.

**Dynamic include risk**: Extension-provided event listeners, service factories, and ACP module info files are loaded by the container (PSR-4 autoloading works). The only legacy exception is the module_manager fallback at line 154 (see §2f above).

**Extension hooks**: `@include($phpbb_root_path . 'src/phpbb/common/hooks/' . $hook . '.php')` — these hook files (if any existed) would define global functions, not classes, so PSR-4 cannot help.

---

## 5. Purely Conditional Includes of Procedural Files (OOP callers)

Many OOP files (services, controllers, cron tasks) lazy-load procedural global function files only when a specific operation is performed. Pattern:

```php
// Example: src/phpbb/forums/auth/auth.php:960
include($phpbb_root_path . 'src/phpbb/common/functions_user.php');
```

**Full list of lazy-loaded procedural files inside OOP services**:

| Procedural File | Loaded By (examples) |
|----------------|----------------------|
| `functions_user.php` | `auth.php`, `auth/provider/apache.php`, `avatar/driver/*.php`, `user.php`, `console/command/user/*.php`, `notification/method/messenger_base.php`, `ucp/controller/reset_password.php`, `install/module/*`, `db/migration/data/v3*/bot_update.php` |
| `functions_admin.php` | `content_visibility.php`, `cron/task/core/tidy_*.php`, `cron/task/core/prune_*.php`, `install/convert/convertor.php`, `db/migration/tool/permission.php` |
| `functions_posting.php` | `content_visibility.php`, `console/command/thumbnail/generate.php`, `common/ucp/ucp_pm_viewfolder.php`, `common/mcp/mcp_topic.php`, `mcp_reports.php`, `web/search.php` |
| `functions_messenger.php` | `console/command/user/add.php`, `console/command/user/activate.php`, `notification/method/messenger_base.php`, `ucp/controller/reset_password.php`, `install/module/install_finish/task/notify_user.php`, `cron/task/core/queue.php` |
| `functions_display.php` | `user_loader.php`, `common/ucp/ucp_main.php`, `web/memberlist.php`, `web/search.php` |
| `functions_content.php` | `console/command/reparser/reparse.php` (require_once at top-level) |
| `functions_acp.php` | `db/migration/data/v310/dev.php` |
| `functions_admin.php` / `acp/auth.php` | `db/migration/data/v30x/release_3_0_6_rc1.php` |
| `acp/acp_bbcodes.php` | `db/migration/data/v30x/local_url_bbcode.php`, `db/migration/data/v31x/update_custom_bbcodes_with_idn.php` |
| `message_parser.php` | `common/ucp/ucp_main.php`, `common/mcp/mcp_warn.php`, `install/convert/convertor.php` |
| `functions_privmsgs.php` | `common/mcp/mcp_pm_reports.php`, `common/mcp/mcp_warn.php` |

**Why MUST-KEEP**: These are procedural files (define global functions). PSR-4 only works for classes. They could be moved to Composer `files[]` but that makes them always-loaded, costing performance.

---

## 6. `files[]` Autoload Candidates

Files that are always loaded on every request and define no class (only functions/constants) are ideal candidates for Composer `"autoload": { "files": [] }`:

| File | Priority |
|------|----------|
| `src/phpbb/common/functions.php` | HIGH — always loaded |
| `src/phpbb/common/constants.php` | HIGH — always loaded |
| `src/phpbb/common/utf/utf_tools.php` | HIGH — always loaded |
| `src/phpbb/common/compatibility_globals.php` | HIGH — always loaded |
| `src/phpbb/common/functions_content.php` | MEDIUM — always loaded |
| `src/phpbb/common/functions_compatibility.php` | MEDIUM — always loaded |
| `src/phpbb/common/startup.php` | LOW — has side-effects (error_reporting, timezone), best kept explicit |

**NOT suitable for `files[]`** (only needed on specific pages):
- `functions_user.php`, `functions_admin.php`, `functions_posting.php`, `functions_display.php`, `functions_messenger.php`, `functions_module.php`, `functions_mcp.php`, `functions_acp.php`

---

## 7. Verified: What common.php Requires

Full require chain from `common.php`:

```
common.php
  → startup.php                      [vendor/autoload.php inside]
  → class_loader.php                  [non-namespaced phpbb\class_loader]
  → functions.php                     [global functions — if PHPBB_INSTALLED not defined, first load]
  → filesystem/filesystem.php         [non-namespaced class, used for redirect during install]
  → functions.php                     [always loaded after container setup]
  → functions_content.php             [global functions; at module scope: include bbcode.php, message_parser.php]
  → functions_compatibility.php       [include()] 
  → constants.php                     [define() calls]
  → utf/utf_tools.php                 [global UTF functions]
  → [container build / symfony DI]
  → compatibility_globals.php
  → hooks/index.php                   [phpbb_hook class + phpbb_hook_register() call]
  → hooks/{hook}.php                  [@include loop, dynamic]
```

---

## 8. Findings Summary Table

| Category | Count | Autoloadable? | Action |
|----------|-------|---------------|--------|
| Bootstrap procedural (functions/constants) | ~18 files | No (no class) | Composer `files[]` for always-loaded; keep explicit `include` for per-page files |
| Runtime computed paths (cache, config.php) | ~6 callsites | No | MUST-KEEP as `require` |
| Dynamic variable paths (hooks, convertors, migrations) | ~10 callsites | No | MUST-KEEP as `include`/`@include` |
| Language files | ~4 callsites | No | MUST-KEEP |
| OOP classes (non-namespaced legacy) | ~5 files | Partial (class_loader handles it) | Migrate to PSR-4 namespace |
| OOP classes (namespaced PSR-4) | All `src/phpbb/forums/**` | YES | Already autoloadable |

---

## Sources

- `src/phpbb/common/common.php` (full file read)
- `src/phpbb/common/startup.php` (full file read)
- `src/phpbb/forums/config_php_file.php` (lines 43–107)
- `src/phpbb/forums/di/container_builder.php` (lines 160–175, 480–490)
- `src/phpbb/forums/routing/router.php` (lines 200–275)
- `src/phpbb/forums/textformatter/s9e/renderer.php` (lines 79–85)
- `src/phpbb/forums/module/module_manager.php` (lines 140–155)
- `src/phpbb/install/startup.php` (lines 20–50, 246–260)
- `src/phpbb/install/convert/convertor.php` (lines 180–200, 910–915)
- `src/phpbb/common/hooks/index.php` (full file read)
- `web/*.php` entry points (all include patterns)
- grep across entire `src/phpbb/` for `require(` and `include(` and `include_once` and `require_once`
