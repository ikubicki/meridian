# Entry Points — require/include Analysis

**Research question**: How can all `require`/`include` statements in phpBB entry-point files be replaced with Composer PSR-4 autoloading?

**Files analysed**: 17 web entry points + `web/download/file.php`

---

## 1. Summary Table — require/include per Entry Point

| Entry Point | Includes | Notes |
|---|---|---|
| `web/app.php` | `src/phpbb/common/common.php` | Minimal bootstrap |
| `web/cron.php` | `src/phpbb/common/common.php` | Minimal bootstrap |
| `web/faq.php` | `src/phpbb/common/common.php` | Minimal bootstrap |
| `web/feed.php` | `src/phpbb/common/common.php` | Minimal bootstrap |
| `web/report.php` | `src/phpbb/common/common.php` | Minimal bootstrap |
| `web/search.php` | `src/phpbb/common/common.php` | Minimal bootstrap |
| `web/viewonline.php` | `src/phpbb/common/common.php` | Minimal bootstrap |
| `web/index.php` | `src/phpbb/common/common.php`, `src/phpbb/common/functions_display.php` | +display funcs |
| `web/viewforum.php` | `src/phpbb/common/common.php`, `src/phpbb/common/functions_display.php` | +display funcs |
| `web/memberlist.php` | `src/phpbb/common/common.php`, `src/phpbb/common/functions_display.php` | +display funcs |
| `web/mcp.php` | `src/phpbb/common/common.php`, `src/phpbb/common/functions_admin.php`, `src/phpbb/common/functions_mcp.php`, `src/phpbb/common/functions_module.php` | 4 includes |
| `web/ucp.php` | `src/phpbb/common/common.php` (via `require`), `src/phpbb/common/functions_user.php`, `src/phpbb/common/functions_module.php` | Uses `require` not `include` |
| `web/posting.php` | `src/phpbb/common/common.php`, `src/phpbb/common/functions_posting.php`, `src/phpbb/common/functions_display.php`, `src/phpbb/common/message_parser.php` | 4 includes |
| `web/viewtopic.php` | `src/phpbb/common/common.php`, `src/phpbb/common/functions_display.php`, `src/phpbb/common/bbcode.php`, `src/phpbb/common/functions_user.php` | 4 includes |
| `web/install.php` | `src/phpbb/install/app.php` (via relative `require('../src/phpbb/install/app.php')`) | Special: install bootstrap |
| `web/css.php` | **none** | Standalone file-server, no phpBB bootstrap |
| `web/js.php` | **none** | Standalone file-server, no phpBB bootstrap |
| `web/download/file.php` | Conditional: avatar path → `startup.php`, `class_loader.php`, `constants.php`, `functions.php`, `functions_download.php`, `utf/utf_tools.php`; non-avatar path → `common.php`, `functions_download.php` | Two distinct bootstrap paths |

---

## 2. Classification of Every Included File

### Files included directly from entry points (first layer)

| File | Classification | Reason |
|---|---|---|
| `src/phpbb/common/common.php` | **[BOOTSTRAP]** | Central bootstrap; loads everything else. Must keep. |
| `src/phpbb/common/functions_display.php` | **[PROCEDURAL]** | 13 global functions (`generate_forum_nav`, etc.). Must keep. |
| `src/phpbb/common/functions_admin.php` | **[PROCEDURAL]** | 29 global functions for admin area. Must keep. |
| `src/phpbb/common/functions_mcp.php` | **[PROCEDURAL]** | 15 global functions for MCP. Must keep. |
| `src/phpbb/common/functions_module.php` | **[MIXED]** | Defines class `p_master` (could be autoloaded) + wraps it. Candidate for refactoring. |
| `src/phpbb/common/functions_user.php` | **[PROCEDURAL]** | 43 global functions for user management. Must keep. |
| `src/phpbb/common/functions_posting.php` | **[PROCEDURAL]** | 16 global functions for posting. Must keep. |
| `src/phpbb/common/message_parser.php` | **[MIXED]** | Classes `bbcode_firstpass`, `parse_message` — autoloadable if moved to PSR-4 paths. |
| `src/phpbb/common/bbcode.php` | **[MIXED]** | Class `bbcode` — autoloadable if moved to PSR-4 path. |
| `src/phpbb/install/app.php` | **[BOOTSTRAP]** | Install subsystem bootstrap. Must keep. |

### Files included by `common.php` (second layer / transitive)

| File | Classification | Reason |
|---|---|---|
| `src/phpbb/common/startup.php` | **[BOOTSTRAP]** | Sets error level, loads `vendor/autoload.php`. Must keep. |
| `src/phpbb/forums/class_loader.php` | **[BOOTSTRAP]** | Registers phpBB's legacy `\phpbb\class_loader`. Must keep until all classes are PSR-4. |
| `src/phpbb/common/functions.php` | **[PROCEDURAL]** | 66 global functions (core utilities). Must keep. |
| `src/phpbb/common/functions_content.php` | **[PROCEDURAL]** | 23 global functions for content rendering. Must keep. |
| `src/phpbb/common/functions_compatibility.php` | **[PROCEDURAL]** | 33 compatibility shims. Keep (optional phase-out later). |
| `src/phpbb/common/constants.php` | **[CONFIG]** | 100+ `define()` calls. Must keep (cannot autoload constants). |
| `src/phpbb/common/utf/utf_tools.php` | **[PROCEDURAL]** | 27 global UTF-8 utility functions. Must keep. |
| `src/phpbb/common/compatibility_globals.php` | **[PROCEDURAL]** | Registers global vars from container. Must keep. |
| `src/phpbb/common/hooks/index.php` | **[MIXED]** | Class `phpbb_hook` + hook registration logic. Class is autoloadable. |
| `src/phpbb/common/hooks/*.php` (dynamic) | **[PROCEDURAL]** | User-installed hooks; loaded with `@include`. Conditional/dynamic. |
| `src/phpbb/common/functions_download.php` | **[PROCEDURAL]** | 15 global functions for file serving. Must keep. |
| `src/phpbb/forums/filesystem/filesystem.php` | **[MIXED]** | Class `phpbb\filesystem\filesystem`, loaded only on install-redirect path. Autoloadable. |

---

## 3. Common Bootstrap Sequence

Every standard entry point follows this identical pattern:

```php
// Step 1 — Mark as phpBB context
define('IN_PHPBB', true);

// Step 2 — Set filesystem roots
define('PHPBB_FILESYSTEM_ROOT', __DIR__ . '/../');
$phpbb_root_path = './';

// Step 3 — Load the single bootstrap file
include(PHPBB_FILESYSTEM_ROOT . 'src/phpbb/common/common.php');

// Step 4 — Start session (in most entry points)
$user->session_begin();
$auth->acl($user->data);
$user->setup('...');
```

**`common.php` in turn loads (always)**:
1. `startup.php` → sets error level, loads `vendor/autoload.php`
2. `forums/class_loader.php` → registers legacy class loader
3. `functions.php` → global functions
4. `functions_content.php` → global functions
5. `functions_compatibility.php` → compat shims
6. `constants.php` → all `define()` constants
7. `utf/utf_tools.php` → UTF-8 helpers
8. `compatibility_globals.php` → wires container into globals
9. `hooks/index.php` → hook system init
10. Dynamic hooks via `@include`

**`$phpbb_root_path = './'`** — This is set to `./` (relative to `web/`), but `PHPBB_FILESYSTEM_ROOT` (`__DIR__ . '/../'`) is used for all actual `include`/`require` paths. The `$phpbb_root_path` variable is passed into procedural functions generating URLs and SQL.

---

## 4. Evidence — Representative Code Snippets

### Standard entry point pattern (`web/app.php`, lines 20–23)
```php
define('IN_PHPBB', true);
define('PHPBB_FILESYSTEM_ROOT', __DIR__ . '/../');
$phpbb_root_path = './';
include(PHPBB_FILESYSTEM_ROOT . 'src/phpbb/common/common.php');
```

### Entry point with extra includes (`web/posting.php`, lines 20–23)
```php
include(PHPBB_FILESYSTEM_ROOT . 'src/phpbb/common/common.php');
include(PHPBB_FILESYSTEM_ROOT . 'src/phpbb/common/functions_posting.php');
include(PHPBB_FILESYSTEM_ROOT . 'src/phpbb/common/functions_display.php');
include(PHPBB_FILESYSTEM_ROOT . 'src/phpbb/common/message_parser.php');
```

### `ucp.php` using `require` instead of `include` (lines 20–22)
```php
require(PHPBB_FILESYSTEM_ROOT . 'src/phpbb/common/common.php');
require(PHPBB_FILESYSTEM_ROOT . 'src/phpbb/common/functions_user.php');
require(PHPBB_FILESYSTEM_ROOT . 'src/phpbb/common/functions_module.php');
```

### `web/install.php` — relative require (lines 16–17)
```php
define('PHPBB_ROOT_PATH', __DIR__ . '/../');
require('../src/phpbb/install/app.php');
```

### `download/file.php` — two bootstrap paths (lines 35–57, 149–150)
**Avatar branch** (minimal, no DI container):
```php
require($phpbb_root_path . 'src/phpbb/common/startup.php');
require($phpbb_root_path . 'src/phpbb/forums/class_loader.php');
// ... manual container setup ...
require($phpbb_root_path . 'src/phpbb/common/constants.php');
require($phpbb_root_path . 'src/phpbb/common/functions.php');
require($phpbb_root_path . 'src/phpbb/common/functions_download' . '.php');  // NOTE: split string literal
require($phpbb_root_path . 'src/phpbb/common/utf/utf_tools.php');
```
**Normal attachment branch** (full bootstrap):
```php
include($phpbb_root_path . 'src/phpbb/common/common.php');
require($phpbb_root_path . 'src/phpbb/common/functions_download' . '.php');  // NOTE: split string literal
```

### `common.php` second-layer includes (lines 82–87, 142, 147, 155)
```php
require($phpbb_root_path . 'src/phpbb/common/functions.php');
require($phpbb_root_path . 'src/phpbb/common/functions_content.php');
include($phpbb_root_path . 'src/phpbb/common/functions_compatibility.php');
require($phpbb_root_path . 'src/phpbb/common/constants.php');
require($phpbb_root_path . 'src/phpbb/common/utf/utf_tools.php');
// ...
require($phpbb_root_path . 'src/phpbb/common/compatibility_globals.php');
require($phpbb_root_path . 'src/phpbb/common/hooks/index.php');
@include($phpbb_root_path . 'src/phpbb/common/hooks/' . $hook . '.php');  // DYNAMIC
```

### `startup.php` — Composer autoload (lines ~82)
```php
require($phpbb_root_path . 'vendor/autoload.php');
```

---

## 5. Conditional / Dynamic Includes

| Location | Pattern | Notes |
|---|---|---|
| `common.php` line 155 | `@include('src/phpbb/common/hooks/' . $hook . '.php')` | Fully dynamic, user-installed hooks. Cannot be pre-declared. |
| `download/file.php` line 35 | `if (isset($_GET['avatar'])) { require startup.php; ... }` | Conditional on request parameter |
| `download/file.php` line 149 | `include(... 'common.php')` in implicit else | Conditional (non-avatar path) |
| `common.php` line 40 | `require 'functions.php'` inside `if (!defined('PHPBB_INSTALLED'))` | Conditional on install state |
| `common.php` line 57 | `require '.../filesystem/filesystem.php'` inside install-redirect block | Conditional |
| `install/app.php` line 28 | `$startup_path = file_exists($new) ? $new : $old; require($startup_path)` | Dynamic path selection for update scenario |
| `src/phpbb/common/functions_download.php` | Split string: `'functions_download' . '.php'` | Intentional obfuscation to prevent naive file-scanning (trivially solvable) |

---

## 6. Autoload Potential Assessment

### Cannot be autoloaded — must be `require`d

| File | Reason |
|---|---|
| `constants.php` | Contains only `define()` calls — PHP constants cannot be registered via autoloader |
| `functions*.php` (all) | Global procedural functions — PHP cannot autoload functions |
| `utf/utf_tools.php` | Global procedural functions |
| `compatibility_globals.php` | Executes `register_compatibility_globals()` side-effect on load |
| `startup.php` | Imperative setup code (error levels, timezone, vendor autoload) |
| `hooks/index.php` | `phpbb_hook` class is not PSR-4 namespaced; also registers hook instance |
| Dynamic hooks | Unknown at compile time |

### Could be autoloaded with namespace/path refactoring

| File | Current class(es) | PSR-4 target path | Blocker |
|---|---|---|---|
| `bbcode.php` | `bbcode` | `phpbb\bbcode\bbcode` → `src/phpbb/bbcode/bbcode.php` | No namespace; global class name |
| `message_parser.php` | `bbcode_firstpass`, `parse_message` | Needs namespace + split files | Multiple classes in one file; no namespace |
| `functions_module.php` | `p_master` | `phpbb\module\p_master` → `src/phpbb/module/p_master.php` | No namespace |
| `hooks/index.php` | `phpbb_hook` | `phpbb\hook\hook` → `src/phpbb/hook/hook.php` | No namespace; legacy name format |
| `forums/class_loader.php` | `\phpbb\class_loader` | Already in `phpbb\` namespace — check if it's already in PSR-4 | Can potentially be Composer-registered |

### Already handled by Composer (no action needed)

- `vendor/autoload.php` — Composer PSR-4 autoloader; loaded in `startup.php`
- All `\phpbb\*` namespaced classes in `src/phpbb/forums/` — loaded by `phpbb\class_loader` and/or Composer PSR-4

---

## 7. Key Findings

1. **The entry point pattern is almost entirely uniform**: 15 of 17 entry points do exactly `include(PHPBB_FILESYSTEM_ROOT . 'src/phpbb/common/common.php')` plus 0–3 additional procedural includes. `css.php` and `js.php` have no phpBB bootstrap at all.

2. **`common.php` is the single chokepoint**: All autoloading strategy changes should focus on what `common.php` loads — the entry points themselves have minimal surface area.

3. **Procedural functions cannot be autoloaded**: The bulk of the include work (`functions.php`, `functions_display.php`, etc.) consists of global function definitions and must remain as explicit `require` calls. They cannot be migrated to Composer PSR-4 autoloading without a full architectural refactor (wrapping functions in classes).

4. **Three classes in non-PSR-4 files are the low-hanging fruit**: `bbcode`, `bbcode_firstpass`/`parse_message`, `p_master`, and `phpbb_hook` are legacy classes defined in procedural-style files. Moving them to namespaced PSR-4 files would allow their includes to be removed from entry points and handled by Composer.

5. **`download/file.php` is the most complex**: It has two distinct bootstrap paths and a split-string dynamic path (`'functions_download' . '.php'`). It bypasses `common.php` entirely in the avatar path.

6. **`$phpbb_root_path = './'` is a URL-generation artifact**, not used for filesystem `require` paths. All filesystem requires use `PHPBB_FILESYSTEM_ROOT` or `$phpbb_root_path` (which equals `PHPBB_FILESYSTEM_ROOT . 'web/../'` = same root). In `download/file.php`, `$phpbb_root_path` is set to `'../../'`.

7. **Composer autoload is already partially active**: `startup.php` loads `vendor/autoload.php`, which handles all properly namespaced `\phpbb\*` classes under `src/phpbb/forums/`. The legacy `phpbb\class_loader` is an additional (redundant with Composer) loader for the same path.
