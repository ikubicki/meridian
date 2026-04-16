# Bootstrap Chain Findings

**Research question:** How can all `require`/`include` statements in the phpBB codebase be replaced with Composer PSR-4 autoloading?

---

## 1. Complete Bootstrap Chain (ASCII)

```
HTTP request
     │
     ▼
web/index.php  (or  web/app.php, web/viewforum.php, etc.)
  define('IN_PHPBB', true)
  define('PHPBB_FILESYSTEM_ROOT', __DIR__ . '/../')
  $phpbb_root_path = './'
  include PHPBB_FILESYSTEM_ROOT . 'src/phpbb/common/common.php'
     │
     ▼
src/phpbb/common/common.php
  require startup.php
  │   ▼
  │   src/phpbb/common/startup.php
  │     error_reporting()
  │     version_compare(PHP_VERSION, '7.2.0')
  │     date_default_timezone_set()
  │     if (PHPBB_NO_COMPOSER_AUTOLOAD env):
  │       require PHPBB_AUTOLOAD   [optional, env-driven]
  │     else:
  │       require vendor/autoload.php   ← Composer PSR-4 for ALL deps
  │     $starttime = microtime()
  │
  require src/phpbb/forums/class_loader.php   ← class definition only
  new \phpbb\class_loader('phpbb\\', "{root}src/phpbb/forums/")
  $phpbb_class_loader->register()             ← SPL autoloader #1: phpbb\ → src/phpbb/forums/
  new \phpbb\config_php_file($root)           ← auto-loaded by class_loader #1
  extract($phpbb_config_php_file->get_all())
  
  [IF !PHPBB_INSTALLED branch]:
    require src/phpbb/common/functions.php          (procedural, for redirect helper)
    require src/phpbb/forums/filesystem/filesystem.php   ← explicit; class already in PSR-4 path
    header('Location: …'); exit
  
  require src/phpbb/common/functions.php             ← procedural global functions
  require src/phpbb/common/functions_content.php     ← procedural global functions
  include src/phpbb/common/functions_compatibility.php ← procedural fallback functions
  require src/phpbb/common/constants.php             ← global define() constants
  require src/phpbb/common/utf/utf_tools.php         ← procedural UTF-8 helpers
  
  [if PHPBB_ENVIRONMENT === 'development']:
    \phpbb\debug\debug::enable()    ← auto-loaded via class_loader #1
  else:
    set_error_handler('msg_handler')  (msg_handler defined in functions.php above)
  
  new \phpbb\class_loader('\\', "{root}ext/")
  $phpbb_class_loader_ext->register()         ← SPL autoloader #2: \\ (any) → ext/
  
  new \phpbb\di\container_builder($root)       ← auto-loaded via class_loader #1
    ↳ container_builder->get_container()
        if use_cache && use_extensions:
          load_extensions()
          require {cache}/autoload_{hash}.php   ← generated; lists ext autoloaders
        if container cache fresh:
          require {cache}/container.php
        else:
          build full Symfony DI container → dump to cache
  
  require src/phpbb/common/compatibility_globals.php  ← defines register_compatibility_globals()
  register_compatibility_globals()                     ← populates $cache,$db,$user,… from container
  require src/phpbb/common/hooks/index.php             ← class phpbb_hook (legacy, non-namespaced)
  
  foreach ($phpbb_hook_finder->find() as $hook):
    @include src/phpbb/common/hooks/{$hook}.php        ← dynamic, optional

     │
     ▼
$phpbb_dispatcher->dispatch('core.common')
```

**CLI path** (`bin/phpbbcli.php`) follows the same first four steps (startup, class_loader, config_php_file) but then manually requires `constants.php`, `functions.php`, `functions_admin.php`, `utf_tools.php`, `functions_compatibility.php`, and `compatibility_globals.php` — identical set to common.php.

---

## 2. Require/Include Inventory — Classification

### 2a. common.php

| Line | Statement | File | Classification |
|------|-----------|------|----------------|
| 23 | `require` | `src/phpbb/common/startup.php` | **MUST KEEP** — procedural boot (error level, timezone, vendor/autoload) |
| 24 | `require` | `src/phpbb/forums/class_loader.php` | **CAN REMOVE** if phpbb\ added to Composer PSR-4 |
| 40 | `require` | `src/phpbb/common/functions.php` | **MUST KEEP** — procedural functions (not a class) |
| 57 | `require` | `src/phpbb/forums/filesystem/filesystem.php` | **CAN REMOVE** — `phpbb\filesystem\filesystem` is a namespaced class; PSR-4 handles it |
| 82 | `require` | `src/phpbb/common/functions.php` | **MUST KEEP** — procedural |
| 83 | `require` | `src/phpbb/common/functions_content.php` | **MUST KEEP** — procedural |
| 84 | `include` | `src/phpbb/common/functions_compatibility.php` | **MUST KEEP** — procedural |
| 86 | `require` | `src/phpbb/common/constants.php` | **MUST KEEP** — `define()` constants, not a class |
| 87 | `require` | `src/phpbb/common/utf/utf_tools.php` | **MUST KEEP** — procedural UTF-8 functions |
| 142 | `require` | `src/phpbb/common/compatibility_globals.php` | **MUST KEEP** — procedural, defines `register_compatibility_globals()` |
| 147 | `require` | `src/phpbb/common/hooks/index.php` | **MUST KEEP** — defines legacy class `phpbb_hook` (no namespace) |
| 155 | `@include` | `src/phpbb/common/hooks/*.php` | **MUST KEEP** — dynamic hook files |

### 2b. startup.php

| Line | Statement | File | Classification |
|------|-----------|------|----------------|
| 70 | `require` | `$PHPBB_AUTOLOAD` (env) | **MUST KEEP** — env-driven alternative autoloader |
| 83 | `require` | `vendor/autoload.php` | **MUST KEEP** — Composer bootstrap; enables all PSR-4 |

### 2c. bin/phpbbcli.php (mirrors common.php)

| Line | Statement | File | Classification |
|------|-----------|------|----------------|
| 26 | `require` | `startup.php` | **MUST KEEP** |
| 27 | `require` | `class_loader.php` | **CAN REMOVE** after PSR-4 |
| 40 | `require` | `constants.php` | **MUST KEEP** |
| 41 | `require` | `functions.php` | **MUST KEEP** |
| 42 | `require` | `functions_admin.php` | **MUST KEEP** |
| 43 | `require` | `utf/utf_tools.php` | **MUST KEEP** |
| 44 | `require` | `functions_compatibility.php` | **MUST KEEP** |
| 69 | `require` | `compatibility_globals.php` | **MUST KEEP** |

### 2d. Lazy requires inside services (not boot-time, but still manual)

These are `require` calls inside service methods — triggered on demand:

| File | Lazy requires |
|------|--------------|
| `console/command/reparser/reparse.php:70` | `require_once functions_content.php` |
| `console/command/user/delete.php:136` | `require functions_user.php` |
| `console/command/user/activate.php:164,204` | `require functions_user.php`, `functions_messenger.php` |
| `console/command/user/add.php:234,306` | `require functions_user.php`, `functions_messenger.php` |
| `avatar/driver/gravatar.php:79` | `require functions_user.php` |
| `avatar/driver/remote.php:70` | `require functions_user.php` |
| `avatar/driver/upload.php:131` | `require functions_user.php` |
| `console/command/thumbnail/generate.php:122` | `require functions_posting.php` |
| `message/admin_form.php:137` | `require functions_user.php` |
| `forums/search/fulltext_sphinx.php:156` | `require sphinxapi.php` |
| `db/migration/…/local_url_bbcode.php:53` | `require acp/acp_bbcodes.php` |

All of these are **procedural files** — they **CANNOT** be handled by PSR-4.

---

## 3. phpbb\class_loader vs. Composer PSR-4

### How phpbb\class_loader works

Source: `src/phpbb/forums/class_loader.php`

```php
// Constructor (line 55-65):
public function __construct($namespace, $path, $php_ext = 'php', ...)
{
    $this->namespace = '\\' . $namespace;  // e.g. "\\phpbb\\"
    $this->path = $path;                   // e.g. ".../src/phpbb/forums/"
}

// Resolution (lines 120-148):
public function resolve_path($class)
{
    // Check cache first
    if (isset($this->cached_paths[$class])) { ... }

    // Regexp: must start with namespace
    if (!preg_match('/^' . preg_quote($this->namespace) . '[a-zA-Z0-9_\\\\]+$/', $class))
        return false;

    // Convert: strip namespace prefix, replace \ with /
    $relative_path = str_replace('\\', '/', substr($class, strlen($this->namespace)));

    // Check file exists
    if (!file_exists($this->path . $relative_path . '.' . $this->php_ext))
        return false;

    // Cache result
    ...
    return $this->path . $relative_path . '.' . $this->php_ext;
}
```

**Resolution example:**
- Class `phpbb\filesystem\filesystem` → strips `phpbb\` → `filesystem/filesystem` → appends `.php` → `src/phpbb/forums/filesystem/filesystem.php` ✓

This is **exactly what Composer PSR-4 does** with the mapping `"phpbb\\": "src/phpbb/forums/"`.

### Second instance: `$phpbb_class_loader_ext`

```php
$phpbb_class_loader_ext = new \phpbb\class_loader('\\', "{$phpbb_root_path}ext/");
```

namespace `\\` (root) + base path `ext/` → resolves ANY class to `ext/…`. This handles **extension classes** that live in `ext/`. Extensions would need their own composer.json PSR-4 mappings to replace this.

### Verdict

| Loader | Namespace covered | Can Composer replace it? |
|--------|-------------------|--------------------------|
| `$phpbb_class_loader` | `phpbb\` → `src/phpbb/forums/` | **YES** — add `"phpbb\\": "src/phpbb/forums/"` to root composer.json |
| `$phpbb_class_loader_ext` | `\\` → `ext/` | **PARTIALLY** — extensions with their own `composer.json` already work; others may not |

---

## 4. Current composer.json Autoload Configuration

### Root `composer.json`

There is **NO `autoload` section** in the root `composer.json`. The phpBB project itself is not declared as a PSR-4 source to Composer. The `phpbb\` namespace is entirely handled by the custom `phpbb\class_loader`.

```json
// composer.json (root) — relevant sections only:
{
    "name": "phpbb/phpbb",
    "type": "project",
    "replace": {
        "phpbb/phpbb-core": "self.version"
    },
    "require": { ... },
    "require-dev": { ... }
    // ← NO "autoload" key
}
```

### `src/phpbb/forums/composer.json`

This is the sub-library composer.json. Its autoload uses a **classmap**, not PSR-4:

```json
{
    "name": "phpbb/phpbb-core",
    "autoload": {
        "classmap": [""]
    }
}
```

This means it asks Composer to scan all files and build a classmap — but this `composer.json` is NOT loaded by the root Composer install (it's a standalone library descriptor, not in the root's `require`). The root does `"replace": { "phpbb/phpbb-core": "self.version" }` which prevents it from being resolved as an external dependency.

### vendor/composer/autoload_psr4.php

Composer registered PSR-4 mappings for all **third-party packages** only:

```php
return array(
    's9e\\TextFormatter\\'      => [...'/s9e/text-formatter/src'],
    'Twig\\'                    => [...'/twig/twig/src'],
    'Symfony\\Component\\...\\ => [...],
    // ... 50+ entries for third-party packages
    // CONSPICUOUSLY ABSENT: 'phpbb\\' => [...]
);
```

**`phpbb\` is NOT in Composer's PSR-4 table.** All `phpbb\` class resolution falls through to the custom `spl_autoload_register` registered by `phpbb\class_loader`.

---

## 5. vendor/autoload.php — What It Provides

`vendor/autoload.php` bootstraps the Composer ClassLoader:

```php
// vendor/autoload.php
require_once __DIR__ . '/composer/autoload_real.php';
return ComposerAutoloaderInitXXX::getLoader();
```

It provides:
1. **PSR-4 autoloading** for ~50 third-party packages (Symfony, Twig, Guzzle, s9e, etc.)
2. **Classmap autoloading** for legacy packages that don't follow PSR-4
3. **Files autoloading** (auto-required via `autoload_static.php::$files`):
   - `symfony/polyfill-ctype/bootstrap.php`
   - `symfony/polyfill-mbstring/bootstrap.php`
   - `symfony/polyfill-php72/bootstrap.php`
   - `ralouphie/getallheaders/src/getallheaders.php`
   - `symfony/polyfill-intl-normalizer/bootstrap.php`
   - `guzzlehttp/promises/src/functions_include.php`
   - `guzzlehttp/psr7/src/functions_include.php`
   - `myclabs/deep-copy/src/deep_copy.php`
   - `symfony/polyfill-intl-idn/bootstrap.php`
   - `guzzlehttp/guzzle/src/functions_include.php`
   - `php-webdriver/webdriver/lib/Exception/TimeoutException.php`

It does **NOT** provide `phpbb\` class autoloading.

---

## 6. Can phpbb\class_loader Be Entirely Replaced by Composer?

**Yes, for the core `phpbb\` namespace.** The steps required:

1. Add to root `composer.json`:
   ```json
   "autoload": {
       "psr-4": {
           "phpbb\\": "src/phpbb/forums/"
       }
   }
   ```
2. Run `composer dump-autoload`
3. Remove these two lines from `common.php` and `bin/phpbbcli.php`:
   ```php
   require($phpbb_root_path . 'src/phpbb/forums/class_loader.php');
   $phpbb_class_loader = new \phpbb\class_loader('phpbb\\', "{$phpbb_root_path}src/phpbb/forums/");
   $phpbb_class_loader->register();
   ```
4. Remove also:
   ```php
   $phpbb_class_loader->set_cache($phpbb_container->get('cache.driver'));
   ```
   (The Composer ClassLoader has no equivalent cache hook, but it's faster by default as it uses OPcache-friendly static arrays.)

**For `$phpbb_class_loader_ext` (extension classes):** This is trickier. Extensions that already ship their own `composer.json` with PSR-4 mappings will work if Composer merges them (requires extensions to be Composer packages). For now, the ext autoloader is a simpler "scan" fallback — it would need a separate strategy (e.g., Composer's `merge-plugin`, or a custom autoloader for ext/).

---

## 7. Minimum Set of requires That MUST Remain After Migration

These files contain **procedural code** (global functions, `define()` constants, or legacy non-namespaced classes) that cannot be handled by any PSR-4 autoloader:

```
REQUIRED — cannot be autoloaded:
├── src/phpbb/common/startup.php          [error level, timezone, vendor/autoload.php]
├── src/phpbb/common/functions.php        [~150+ global functions: msg_handler, append_sid, redirect, …]
├── src/phpbb/common/functions_content.php [global content rendering functions]
├── src/phpbb/common/functions_compatibility.php [deprecated wrapper functions]
├── src/phpbb/common/constants.php        [200+ define() constants]
├── src/phpbb/common/utf/utf_tools.php    [UTF-8 global functions]
├── src/phpbb/common/compatibility_globals.php [register_compatibility_globals()]
└── src/phpbb/common/hooks/index.php      [class phpbb_hook — no namespace, not PSR-4]

REQUIRED lazily (service-level, cannot be autoloaded):
├── src/phpbb/common/functions_user.php
├── src/phpbb/common/functions_messenger.php
├── src/phpbb/common/functions_posting.php
├── src/phpbb/common/functions_admin.php  (CLI only)
├── src/phpbb/common/sphinxapi.php
└── src/phpbb/common/acp/acp_bbcodes.php (via migration)

CAN BE REMOVED after adding PSR-4:
├── src/phpbb/forums/class_loader.php     [require + instantiation]
└── src/phpbb/forums/filesystem/filesystem.php (line 57 in common.php — early boot explicit require)
```

---

## 8. Evidence: Key Code Snippets

### startup.php — vendor/autoload.php loading

```php
// src/phpbb/common/startup.php:65-84
if (getenv('PHPBB_NO_COMPOSER_AUTOLOAD'))
{
    if (getenv('PHPBB_AUTOLOAD'))
    {
        require(getenv('PHPBB_AUTOLOAD'));
    }
}
else
{
    if (!file_exists($phpbb_root_path . 'vendor/autoload.php'))
    {
        trigger_error('Composer dependencies have not been set up yet, ...', E_USER_ERROR);
    }
    require($phpbb_root_path . 'vendor/autoload.php');
}
```

### class_loader: PSR-4 equivalent logic

```php
// src/phpbb/forums/class_loader.php:120-148
public function resolve_path($class)
{
    $relative_path = str_replace('\\', '/', substr($class, strlen($this->namespace)));

    if (!file_exists($this->path . $relative_path . '.' . $this->php_ext))
    {
        return false;
    }
    // ...
    return $this->path . $relative_path . '.' . $this->php_ext;
}
```

This is **identical in behavior** to Composer's PSR-4 resolution.

### common.php: full autoloader setup sequence

```php
// src/phpbb/common/common.php:23-28
require($phpbb_root_path . 'src/phpbb/common/startup.php');
require($phpbb_root_path . 'src/phpbb/forums/class_loader.php');

$phpbb_class_loader = new \phpbb\class_loader('phpbb\\', "{$phpbb_root_path}src/phpbb/forums/");
$phpbb_class_loader->register();
```

```php
// src/phpbb/common/common.php:100-101
$phpbb_class_loader_ext = new \phpbb\class_loader('\\', "{$phpbb_root_path}ext/");
$phpbb_class_loader_ext->register();
```

### Root composer.json — absent autoload section

```json
{
    "name": "phpbb/phpbb",
    "type": "project",
    "replace": { "phpbb/phpbb-core": "self.version" },
    "require": { ... }
    // "autoload" key does not exist
}
```

### autoload_psr4.php — phpbb\ absent

```php
// vendor/composer/autoload_psr4.php
return array(
    's9e\\TextFormatter\\' => [...],
    'Twig\\'               => [...],
    'Symfony\\...\\'       => [...],
    // phpbb\ IS NOT HERE
);
```

---

## 9. Summary

| Finding | Evidence |
|---------|----------|
| `vendor/autoload.php` is always loaded first (via startup.php) | `startup.php:83` |
| `phpbb\class_loader` duplicates PSR-4 for `phpbb\` → `src/phpbb/forums/` | `class_loader.php:120-148`, `common.php:25-27` |
| Root `composer.json` has NO autoload section | Root `composer.json` |
| Composer PSR-4 table has NO `phpbb\` mapping | `vendor/composer/autoload_psr4.php` |
| `phpbb\class_loader` is 100% replaceable for `phpbb\` by adding one PSR-4 entry | Analysis |
| 8 procedural files cannot be autoloaded and MUST remain as explicit `require` | Inventory above |
| Lazy `require` in services (functions_user.php etc.) are also procedural → MUST stay | `grep_search` on src/phpbb/forums/ |
| Ext autoloader (`\\` → `ext/`) needs separate migration strategy | `common.php:100-101` |
