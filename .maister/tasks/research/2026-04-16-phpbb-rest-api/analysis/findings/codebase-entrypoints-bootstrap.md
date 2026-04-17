# Codebase Findings: phpBB Entry Point Bootstrap Patterns

**Research Question**: How do existing phpBB web entry points bootstrap the application, so we can replicate the pattern for REST API entry points (`web/api.php`, `web/adm/api.php`, `web/install/api.php`)?

**Source category**: `codebase-entrypoints`
**Gathered**: 2026-04-16

---

## Files Examined

| File | Exists | Role |
|------|--------|------|
| `web/app.php` | ✅ | Symfony HttpKernel entry point |
| `web/index.php` | ✅ | Forum index (classic entry point) |
| `web/adm/index.php` | ✅ | Admin panel entry point |
| `src/phpbb/common/common.php` | ✅ | Shared bootstrap core |
| `src/phpbb/common/startup.php` | ✅ | PHP environment setup, autoload |
| `src/phpbb/common/compatibility_globals.php` | ✅ | Global variable population |
| `src/phpbb/install/redirect.php` | ✅ | Install-check redirect helper |
| `src/phpbb/Container.php` | ✅ | Container facade (`\phpbb\Container`) |

---

## 1. `src/phpbb/common/startup.php` — PHP Environment Initialisation

**Role**: Very first include in `common.php`. Sets up raw PHP environment before any phpBB code runs.

### Key operations (in order)

| Line | Operation |
|------|-----------|
| 20 | `error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED)` |
| 31 | PHP version gate — `die()` if `< 7.2.0` |
| 49 | `date_default_timezone_set(...)` — forces UTC-detected timezone |
| 62–74 | Composer autoloader: loads `vendor/autoload.php` (unless `PHPBB_NO_COMPOSER_AUTOLOAD` env var is set) |
| 76 | `$starttime = microtime(true)` |

**Constants/globals expected**: none (this is the very first file)
**Error handling**: raw `die()` for fatal PHP version mismatch
**Termination**: does not send response, continues

---

## 2. `src/phpbb/common/common.php` — Shared Bootstrap Core

**Role**: The canonical bootstrap sequence shared by ALL phpBB entry points. Every `*.php` entry point does exactly one thing before its own logic: `include('src/phpbb/common/common.php')`.

### Constants/globals expected BEFORE include

| Name | Who sets it | Example |
|------|-------------|---------|
| `PHPBB_FILESYSTEM_ROOT` | Entry point (`define(...)`) | `__DIR__ . '/../'` |
| `$phpbb_root_path` | Entry point | `'./'` (relative to web root) |
| `PHPBB_ENVIRONMENT` | `config.php` via `extract()` or entry point | `'production'` |
| `PHPBB_MSG_HANDLER` | Optional, entry point | name of error-handler function |
| `ADMIN_START` | `web/adm/index.php` sets it before include | `true` |
| `NEED_SID` | `web/adm/index.php` sets it before include | `true` |
| `IN_ADMIN` | set by `web/adm/index.php` AFTER include | `true` |

### Full bootstrap sequence (annotated)

```
common.php:20  require startup.php              // PHP env, autoload (see §1)
common.php:22  $phpbb_config_php_file = new \phpbb\config_php_file($phpbb_filesystem_root)
common.php:23  extract($phpbb_config_php_file->get_all())
               // injects: $dbms, $dbhost, $dbname, $dbuser, $dbpasswd,
               //          $table_prefix, $phpbb_adm_relative_path, $acm_type
               // defines: PHPBB_INSTALLED, PHPBB_ENVIRONMENT (via config.php @define)

common.php:25  if (!defined('PHPBB_ENVIRONMENT')) @define('PHPBB_ENVIRONMENT', 'production')

common.php:30  \phpbb\install\checkInstallation($phpbb_root_path)
               // exits to installer if PHPBB_INSTALLED not defined

common.php:33  $phpbb_adm_relative_path = ... (default 'web/adm/')
common.php:34  $phpbb_admin_path = ...

common.php:37  require functions.php            // ~3 100 lines of core helpers
common.php:38  require functions_content.php
common.php:39  include functions_compatibility.php
common.php:41  require constants.php
common.php:42  require utf/utf_tools.php

common.php:45-50  if (PHPBB_ENVIRONMENT === 'development') debug::enable()
                  else set_error_handler('msg_handler')   // ← sets $phpbb_app_container-aware handler

common.php:52  $phpbb_class_loader_ext = new \phpbb\class_loader('\\', "{$phpbb_root_path}ext/")
common.php:53  $phpbb_class_loader_ext->register()

common.php:56-79  try {
                    $phpbb_container_builder = new \phpbb\di\container_builder(...)
                    $phpbb_container = $phpbb_container_builder
                                          ->with_config($phpbb_config_php_file)
                                          ->get_container()
                  } catch (InvalidArgumentException $e) { ... trigger_error / rethrow }

common.php:81  if ($phpbb_container->getParameter('debug.error_handler')) debug::enable()

common.php:83  $phpbb_class_loader_ext->set_cache($phpbb_container->get('cache.driver'))

common.php:85  $phpbb_container->get('dbal.conn')->set_debug_sql_explain(...)
common.php:86  $phpbb_container->get('dbal.conn')->set_debug_load_time(...)

common.php:88  require compatibility_globals.php   // defines register_compatibility_globals()
common.php:89  register_compatibility_globals()
               // populates globals: $cache, $phpbb_dispatcher, $request, $user, $auth,
               //                    $db, $config, $language, $phpbb_log,
               //                    $symfony_request, $phpbb_filesystem,
               //                    $phpbb_path_helper, $phpbb_extension_manager, $template

common.php:92  $phpbb_app_container = new \phpbb\Container($phpbb_container)
               // ⚠ SET AFTER register_compatibility_globals() to avoid circular DI reference
               //   msg_handler() checks ($phpbb_app_container === null) for bootstrap-safe output

common.php:94  require hooks/index.php
common.php:95  $phpbb_hook = new phpbb_hook([...])
common.php:97  $phpbb_hook_finder->find()  // loads hook files from ext/

common.php:107 $phpbb_dispatcher->dispatch('core.common')
               // final event — no session/auth available yet
```

**Source**: `src/phpbb/common/common.php:1–113` (full file)

---

## 3. `web/app.php` — Symfony HttpKernel Entry Point

**Role**: Routes requests through the Symfony HttpKernel (used for controller-based routing, REST-style responses in modern phpBB).

### Post-common.php sequence

```php
// web/app.php:24
$user->session_begin();        // starts/resumes PHP session, sets $user->data
$auth->acl($user->data);       // loads permission bitfield for this user
$user->setup('app');           // loads language pack named 'app'

// web/app.php:29
$http_kernel = $phpbb_container->get('http_kernel');
$symfony_request = $phpbb_container->get('symfony_request');
$response = $http_kernel->handle($symfony_request);
$response->send();
$http_kernel->terminate($symfony_request, $response);
```

**Source**: `web/app.php:1–35`

**Key facts**:
- `session_begin()` is mandatory before `acl()`.
- `$user->setup('app')` loads the `'app'` language file — the string arg is the language pack key, not a module name.
- Response is sent via Symfony `Response::send()`, not by echoing directly.
- `http_kernel->terminate()` fires kernel terminate events (e.g., writes sessions, flushing buffers).
- No direct HTML output. Pure HTTP contract via Symfony.

---

## 4. `web/index.php` — Forum Index Entry Point

**Role**: Classic forum index page. Pattern representative of ALL non-Symfony entry points.

### Post-common.php sequence

```php
// web/index.php:24-25
include(...'functions_display.php');   // extra helper not in common.php

// web/index.php:28-30
$user->session_begin();
$auth->acl($user->data);
$user->setup('viewforum');   // loads 'viewforum' language pack
```

Then: business logic, SQL queries, `$template->assign_vars()`, `page_header()`, `page_footer()`.

**Source**: `web/index.php:1–30`

**Key facts**:
- `$user->setup('viewforum')` — the string selects which lang pack to preload; for API entries `'api'` or no language pack is appropriate.
- Template rendering (`page_header`, `page_footer`, `assign_var`) is the ONLY thing to skip for JSON responses.

---

## 5. `web/adm/index.php` — Admin Panel Entry Point

**Role**: Admin control panel. Demonstrates the pattern for admin-scoped entry points.

### Pre-common.php constants set by this file

```php
// web/adm/index.php:20-21
define('ADMIN_START', true);
define('NEED_SID', true);
$phpbb_root_path = '../../';   // relative path differs (inside web/adm/)
```

### Post-common.php sequence

```php
// web/adm/index.php:26-27
require '.../functions_acp.php';
require '.../functions_admin.php';
require '.../functions_module.php';

$user->session_begin();
$auth->acl($user->data);
$user->setup('acp/common');

// Auth check
if (!$user->data['session_admin']) { login_box(...); }
if (!$auth->acl_get('a_')) { send_status_line(403, 'Forbidden'); trigger_error('NO_ADMIN'); }

define('IN_ADMIN', true);

// ... module system, template, adm_page_header(), adm_page_footer()
```

**Source**: `web/adm/index.php:1–82`

**Key facts for `web/adm/api.php`**:
- Must `define('ADMIN_START', true)` and `define('NEED_SID', true)` **before** the common.php include.
- `$phpbb_root_path` must be `'../../'` (two levels up from `web/adm/`).
- `PHPBB_FILESYSTEM_ROOT` must be `__DIR__ . '/../../'` (also two levels up).
- Auth check (`session_admin`, `acl_get('a_')`) is mandatory; return JSON 403 instead of `login_box()`.
- `IN_ADMIN` constant signals admin context to hooks/extensions.
- `adm_page_header()` / `adm_page_footer()` / module system → SKIP for API.

---

## 6. `src/phpbb/install/redirect.php` — Install-check Helper

**Role**: Called at `common.php:30`. Reads `PHPBB_INSTALLED` constant; if absent, performs an HTTP redirect to the installer and `exit`s immediately.

### Key logic

```php
// src/phpbb/install/redirect.php:24-30
function checkInstallation(string $phpbb_root_path): void
{
    if (!defined('PHPBB_INSTALLED'))
    {
        redirectToInstaller($phpbb_root_path);  // emits Location header + exit
    }
}
```

**Source**: `src/phpbb/install/redirect.php:24–73`

**Key facts**:
- `PHPBB_INSTALLED` is defined (via `@define`) inside `src/phpbb/common/config/config.php` which is loaded by `$phpbb_config_php_file->get_all()` → `extract()`.
- For `web/install/api.php`, `PHPBB_INSTALLED` will NOT be defined (installer context), so `checkInstallation()` must be skipped or the constant must be pre-defined before calling `common.php`.
- `redirectToInstaller()` uses raw `header('Location: ...')` + `exit` — it is completely independent of the DI container.

---

## 7. `src/phpbb/Container.php` — Container Facade

**Role**: Typed accessor wrapper around the Symfony DI container (`ContainerInterface`). Assigned to the global `$phpbb_app_container` immediately after `register_compatibility_globals()`.

### Typed accessors provided

| Method | Returns | DI service key |
|--------|---------|----------------|
| `getUser()` | `\phpbb\user` | `'user'` |
| `getConfig()` | `\phpbb\config\config` | `'config'` |
| `getDb()` | `\phpbb\db\driver\driver_interface` | `'dbal.conn'` |
| `getAuth()` | `\phpbb\auth\auth` | `'auth'` |
| `getLanguage()` | `\phpbb\language\language` | `'language'` |
| `getTemplate()` | `\phpbb\template\template` | `'template'` |
| `getRequest()` | `\phpbb\request\request_interface` | `'request'` |
| `getCache()` | `\phpbb\cache\service` | `'cache'` |
| `getLog()` | `\phpbb\log\log_interface` | `'log'` |
| `getDispatcher()` | `\phpbb\event\dispatcher_interface` | `'dispatcher'` |
| `getPathHelper()` | `\phpbb\path_helper` | `'path_helper'` |
| `getFilesystem()` | `\phpbb\filesystem\filesystem_interface` | `'filesystem'` |
| `getExtensionManager()` | `\phpbb\extension\manager` | `'ext.manager'` |
| `get(string $id)` | `mixed` | generic fallback |

**Source**: `src/phpbb/Container.php:1–138`

**Critical ordering note** (confirmed in code):
- `$phpbb_app_container` is assigned at `common.php:92`, which is AFTER `register_compatibility_globals()` at `common.php:89`.
- `msg_handler()` (`functions.php:3103`) explicitly checks `if ($phpbb_app_container === null)` and uses a minimal bootstrap-safe output path when the container is not yet available (during DI compilation).

---

## 8. `src/phpbb/forums/json_response.php` — JSON Output Helper

**Role**: Utility class for sending JSON responses — already used in existing entry points for AJAX paths.

```php
// src/phpbb/forums/json_response.php:29-38
public function send($data, $exit = true)
{
    header('Content-Type: application/json');
    echo json_encode($data);

    if ($exit)
    {
        garbage_collection();
        exit_handler();
    }
}
```

**Source**: `src/phpbb/forums/json_response.php:29–38`

**Key facts**:
- `garbage_collection()` + `exit_handler()` are the standard phpBB request-end sequence (close DB, run hooks, etc.).
- `$exit = false` allows embedding JSON output without terminating (useful for middleware).

---

## 9. Minimum Bootstrap Sequence for a JSON REST Entry Point

The minimum required to have a functional phpBB DI container + DB + auth available for a JSON response:

```
STEP 1 — Pre-include constants (set BEFORE common.php)
──────────────────────────────────────────────────────
define('PHPBB_FILESYSTEM_ROOT', __DIR__ . '/../');   // absolute path to repo root
$phpbb_root_path = './';                              // relative from web/

// For adm/api.php only:
define('ADMIN_START', true);
define('NEED_SID', true);
define('PHPBB_FILESYSTEM_ROOT', __DIR__ . '/../../');
$phpbb_root_path = '../../';

STEP 2 — Bootstrap
──────────────────
include(PHPBB_FILESYSTEM_ROOT . 'src/phpbb/common/common.php');
// After this: $phpbb_container, $phpbb_app_container, and all globals are available

STEP 3 — Session (mandatory before any permission check)
────────────────────────────────────────────────────────
$user->session_begin();
$auth->acl($user->data);
$user->setup('api');   // or omit language; use '' for no language preloading

STEP 4 — API controller dispatch
────────────────────────────────
// Route $request to controller, return JSON via \phpbb\json_response or
// via Symfony Response with Content-Type: application/json
```

---

## 10. What Can Be SKIPPED for API Entry Points

| Feature | Used in | Skip for API? | Reason |
|---------|---------|---------------|--------|
| `include functions_display.php` | `index.php` | ✅ Skip | HTML rendering helpers only |
| `include functions_acp.php` etc. | `adm/index.php` | ✅ Skip | ACP HTML helpers |
| `$user->setup('viewforum')` language arg | all | ⚠ Change | Use `'api'` or `''` |
| `$template->assign_vars()` | all | ✅ Skip | No template output |
| `page_header()` / `page_footer()` | index.php | ✅ Skip | HTML skeleton |
| `adm_page_header()` / `adm_page_footer()` | adm/index.php | ✅ Skip | Admin HTML |
| Module system (`p_master`) | adm/index.php | ✅ Skip | ACP module routing |
| `$template->set_custom_style()` | adm/index.php | ✅ Skip | Template theming |
| `$phpbb_hook` (hook system) | common.php | ❌ Keep | Ext hook compatibility |
| `$phpbb_dispatcher->dispatch('core.common')` | common.php | ❌ Keep | Extension event |
| `$user->session_begin()` | all | ❌ Keep | Required for auth |
| `$auth->acl($user->data)` | all | ❌ Keep | Required for permissions |
| `login_box()` on auth fail | adm/index.php | ✅ Replace | Return JSON 401/403 |
| `send_status_line(403, ...)` | adm/index.php | ❌ Keep | HTTP semantics correct for API too |
| `trigger_error('NO_ADMIN')` | adm/index.php | ✅ Replace | Return JSON 403 body |

---

## 11. Cross-Cutting Observations

1. **Single shared bootstrap**: 100% of entry points (forum, admin, Symfony app) share `common.php`. The pattern is `define constants → set $phpbb_root_path → include common.php → session_begin → acl → setup`.

2. **`$phpbb_root_path` is relative**: It must be adjusted per entry point location (`'./'` from `web/`, `'../../'` from `web/adm/`).

3. **`PHPBB_FILESYSTEM_ROOT` is absolute**: Always `__DIR__ . '/<n levels up>/'`.

4. **Container is available after `common.php`**: Both `$phpbb_container` (raw Symfony) and `$phpbb_app_container` (typed facade) are fully wired after the include returns. No additional configuration is needed.

5. **`web/install/api.php` edge case**: The installer context means `PHPBB_INSTALLED` is not defined. `common.php:30` would redirect to the installer. Options:
   - Pre-define `PHPBB_INSTALLED` before the include (unsafe if board truly isn't installed).
   - Skip `common.php` entirely and bootstrap a lighter container directly.
   - Define `PHPBB_MSG_HANDLER` to a custom handler before include to control error output.

6. **Error handler is JSON-aware**: `msg_handler()` uses `$phpbb_app_container` which is set after `register_compatibility_globals()`. During container compilation it falls back to raw HTML output. For API entry points, a custom error handler that emits JSON could be registered via `set_error_handler()` before `common.php`, then re-registered after (or `PHPBB_MSG_HANDLER` constant could point to a JSON msg handler).

7. **`\phpbb\json_response` is the idiomatic JSON exit**: Calls `garbage_collection()` + `exit_handler()` for clean shutdown. Use this in API controllers instead of raw `echo json_encode() + exit`.

---

## Source Index

| Source | Location |
|--------|----------|
| `web/app.php` | `web/app.php` |
| `web/index.php` | `web/index.php` |
| `web/adm/index.php` | `web/adm/index.php` |
| `common.php` full bootstrap | `src/phpbb/common/common.php:1–113` |
| `startup.php` | `src/phpbb/common/startup.php:1–76` |
| `compatibility_globals.php` | `src/phpbb/common/compatibility_globals.php:1–100` |
| `config.php` (generated) | `src/phpbb/common/config/config.php:1–15` |
| `install/redirect.php` | `src/phpbb/install/redirect.php:1–73` |
| `Container.php` facade | `src/phpbb/Container.php:1–138` |
| `json_response.php` | `src/phpbb/forums/json_response.php:1–45` |
| `msg_handler()` | `src/phpbb/common/functions.php:3103–3180` |
