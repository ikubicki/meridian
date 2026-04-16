# DI Services Findings: `$phpbb_root_path` in Symfony Container

## Research Question
How is `$phpbb_root_path = './'` (URL-relative) passed into the Symfony DI container and used by services — and where does it cause filesystem breakage?

---

## 1. Flow: web/*.php → container_builder → DI parameter → services

```
web/index.php (CWD = /var/www/phpbb/web/)
├── define('PHPBB_FILESYSTEM_ROOT', __DIR__ . '/../')   # = /var/www/phpbb/
├── $phpbb_root_path = './'                              # URL-relative, WRONG for FS
└── include(PHPBB_FILESYSTEM_ROOT . 'src/phpbb/common/common.php')

src/phpbb/common/common.php
├── new \phpbb\config_php_file($phpbb_root_path)         # reads config.php via './'
├── new \phpbb\di\container_builder($phpbb_root_path)   # <-- $phpbb_root_path = './'
│   ├── get_core_parameters():
│   │   └── 'core.root_path' => $this->phpbb_root_path  # = './'  ← INJECTED AS DI PARAM
│   ├── get_config_path():
│   │   └── $this->phpbb_root_path . 'src/phpbb/common/config'   ← FS USE (broken!)
│   ├── get_cache_dir():
│   │   └── $this->phpbb_root_path . 'cache/production/'          ← FS USE (broken!)
│   └── register_ext_compiler_pass():
│       └── in($this->phpbb_root_path . 'ext')                    ← FS USE (broken!)
└── container: 'core.root_path' = './'
```

**Key insight**: `container_builder` itself uses `$phpbb_root_path` for three internal filesystem operations **before** any service is built:
1. Config path: `'./' . 'src/phpbb/common/config'` → resolves to `/var/www/phpbb/web/src/phpbb/common/config` ❌
2. Cache dir: `'./' . 'cache/production/'` → `/var/www/phpbb/web/cache/production/` ❌
3. Ext compiler pass: `in('./' . 'ext')` → `/var/www/phpbb/web/ext` ❌

(These work only when PHP's CWD is the phpBB root, not the `web/` subdirectory.)

---

## 2. `config_php_file` also uses root_path for FS

**File**: `src/phpbb/forums/config_php_file.php:57`
```php
$this->config_file = $this->phpbb_root_path . 'src/phpbb/common/config/config.' . $this->php_ext;
```
→ **Filesystem use** — reads the site config file by path.

---

## 3. `core.root_path` in YAML: ~60 usages across 18+ service files

Services receiving `%core.root_path%` and how they use it:

| Service | YAML file | Filesystem use? | URL use? | Fix needed? |
|---------|-----------|-----------------|----------|-------------|
| `hook_finder` | services_hook.yml | ✅ `opendir(root . 'src/phpbb/common/hooks/')` | ❌ | YES |
| `language.helper.language_file` | services_language.yml | ✅ `->in(root . 'src/phpbb/language')` | ❌ | YES |
| `language.loader_abstract` | services_language.yml | ✅ language file loading | ❌ | YES |
| `ext.manager` | services.yml | ✅ `is_dir(root . 'src/phpbb/ext/')`, `RecursiveDirectoryIterator` | ⚠️ relative path returned if `$phpbb_relative=true` | YES (FS path) |
| `finder` | services.yml | ✅ `root . 'src/phpbb/forums/'` / `root . 'src/phpbb/ext/'` for file discovery | ❌ | YES |
| `class_loader` | services.yml | ✅ `'%core.root_path%src/phpbb/forums/'` → autoload path | ❌ | YES |
| `class_loader.ext` | services.yml | ✅ `'%core.root_path%src/phpbb/ext/'` → autoload path | ❌ | YES |
| `cache` (service) | services.yml | ✅ `root . 'src/phpbb/styles/' . style_path . '/style.cfg'` | ❌ | YES |
| `text_formatter.data_access` | services_text_formatter.yml | ✅ `root . 'src/phpbb/styles/'` (styles dir path) | ❌ | YES |
| `migrator` | services_migrator.yml | ✅ passes to migration classes via `new $name(..., $this->phpbb_root_path, ...)` | ❌ | YES |
| `migrator.tool.module` | services_migrator.yml | ✅ passed to module migration tool (include paths) | ❌ | YES |
| `migrator.tool.permission` | services_migrator.yml | ✅ passed to permission migration tool | ❌ | YES |
| `files.filespec` | services_files.yml | ✅ `$this->phpbb_root_path . $destination` (upload dest) | ❌ | YES |
| `files.types.remote` | services_files.yml | ✅ stored, forwarded to filespec | ❌ | YES |
| `routing.resources_locator.default` | services_routing.yml | ✅ `file_exists(root . $path . 'config/...')` | ❌ | YES |
| `auth.provider.apache` | services_auth.yml | ✅ `include(root . 'src/phpbb/common/functions_user.php')` | ❌ | YES |
| `auth.provider.db` | services_auth.yml | ⚠️ stored, usage unclear from grep (likely passed to sub-calls) | ❌ | YES (precaution) |
| `auth.provider.oauth` | services_auth.yml | ⚠️ stored for OAuth redirect context | ❌ | Investigate |
| `avatar.driver.upload` | services_avatar.yml | ✅ `root . $destination . '/' . $prefix . $id` (avatar file path), `filesystem->exists(root . avatar_path)` | ❌ | YES |
| `avatar.driver.gravatar` | services_avatar.yml | ⚠️ inherits from base, delegates URL to `path_helper` | ❌ | Investigate |
| `avatar.driver.local` | services_avatar.yml | ⚠️ inherits from base | ❌ | Investigate |
| `avatar.driver.remote` | services_avatar.yml | ⚠️ inherits from base | ❌ | Investigate |
| `cron.task.core.prune_all_forums` | services_cron.yml | ✅ `include(root . 'src/phpbb/common/functions_admin.php')` | ❌ | YES |
| `cron.task.core.prune_forum` | services_cron.yml | ✅ same include pattern | ❌ | YES |
| `cron.manager` | services_cron.yml | ⚠️ passed to `wrapper` — used to call cron tasks | ❌ | YES |
| `cron.*` (remaining tasks x9) | services_cron.yml | ✅ likely include/require patterns | ❌ | YES |
| `path_helper` | services.yml | ❌ | ✅ URL prefix, `get_web_root_path()`, `update_web_root_path()` | NO (needs URL path) |
| `log` | services.yml | ❌ | ✅ `append_sid(root . 'memberlist.php')`, viewforum, viewtopic | NO (needs URL path) |
| `controller.helper` | services.yml | ❌ | ✅ `$root_path . $admin_path` (URL) | NO (needs URL path) |
| `controller.resolver` | services.yml | ❌ | ✅ URL resolution | NO (needs URL path) |
| `text_formatter.s9e.quote_helper` | services_text_formatter.yml | ❌ | ✅ `append_sid(root . 'viewtopic.php', ...)` | NO (needs URL path) |
| `routing.helper` | services_routing.yml | ❌ | ✅ URL base building (explicitly checks `$root_path[0] === '/'` to skip URL prefix if absolute) | NO (needs URL path) |
| `message.form.*` | services_content.yml | ❌ | ✅ `append_sid(root . 'index.php')` | NO (needs URL path) |
| `plupload` | services.yml | ⚠️ mixed: used for URL context in upload | ❌ | Investigate |
| `file_locator` | services.yml | ✅ `@filesystem` based file location | ❌ | YES |

---

## 4. No existing `core.filesystem_root_path` distinction

Search result: **zero** occurrences of `core.filesystem`, `filesystem_root_path`, or similar in any `.php` or `.yml` file under `src/phpbb/` (excluding vendor).

`PHPBB_FILESYSTEM_ROOT` is defined in every `web/*.php` file as `__DIR__ . '/../'` and used **only** for direct `include()` calls in those entry-point files. It is never passed to `container_builder` and never becomes a DI parameter.

---

## 5. Critical container_builder internal filesystem uses

These happen **before the container is even built** and cannot be fixed by changing YAML service args alone:

```php
// container_builder.php:406
protected function get_config_path()
{
    return $this->config_path ?: $this->phpbb_root_path . 'src/phpbb/common/config';
    // When $phpbb_root_path = './' and CWD=/var/www/phpbb/web/ → BROKEN
}

// container_builder.php:416
public function get_cache_dir()
{
    return $this->cache_dir ?: $this->phpbb_root_path . 'cache/' . $this->get_environment() . '/';
    // Same problem → resolves to web/cache/ instead of /var/www/phpbb/cache/
}

// container_builder.php:674
->in($this->phpbb_root_path . 'ext')
// Ext compiler pass path
```

---

## 6. Recommendation

### Option A: Fix at `container_builder` level (recommended)

Accept an optional `$filesystem_root_path` in `container_builder::__construct()`:

```php
public function __construct($phpbb_root_path, $php_ext = 'php', $filesystem_root_path = null)
{
    $this->phpbb_root_path = $phpbb_root_path;
    $this->filesystem_root_path = $filesystem_root_path ?? (defined('PHPBB_FILESYSTEM_ROOT') ? PHPBB_FILESYSTEM_ROOT : $phpbb_root_path);
    ...
}
```

Then in `get_core_parameters()`:
```php
return array_merge([
    'core.root_path'            => $this->phpbb_root_path,      // stays './' — URL use
    'core.filesystem_root_path' => $this->filesystem_root_path, // new — FS use
    'core.php_ext'              => $this->php_ext,
    'core.environment'          => $this->get_environment(),
    'core.cache_dir'            => $this->get_cache_dir(),
], $this->env_parameters);
```

Fix internal methods:
```php
protected function get_config_path()
{
    return $this->config_path ?: $this->filesystem_root_path . 'src/phpbb/common/config';
}

public function get_cache_dir()
{
    return $this->cache_dir ?: $this->filesystem_root_path . 'cache/' . $this->get_environment() . '/';
}
```

Call from `common.php`:
```php
$phpbb_container_builder = new \phpbb\di\container_builder(
    $phpbb_root_path,        // './'
    'php',
    PHPBB_FILESYSTEM_ROOT    // '/var/www/phpbb/'
);
```

### Option B: Add `core.filesystem_root_path` parameter only (simpler, incomplete)

Only add the parameter without fixing `container_builder` internals — then migrate YAML services one by one. This leaves `get_config_path()` and `get_cache_dir()` broken until separately fixed.

### Services that need `%core.filesystem_root_path%` (replace root_path):
- All services in the **"Filesystem use? ✅"** column above
- Specifically: `hook_finder`, `language.*`, `ext.manager`, `finder`, `class_loader*`, `cache`, `text_formatter.data_access`, `migrator*`, `files.filespec`, `files.types.remote`, `routing.resources_locator.default`, `auth.provider.apache`, `auth.provider.db`, `avatar.driver.upload`, `cron.*`

### Services that MUST keep `%core.root_path%` (URL-relative):
- `path_helper`, `log`, `controller.helper`, `controller.resolver`, `text_formatter.s9e.quote_helper`, `routing.helper`, `message.form.*`

### Services needing both (dual injection):
- `cron.manager`: wraps cron tasks (passes root_path to them for FS includes)
- `avatar.driver.upload`: uses both FS path AND delegates URL via `path_helper`

---

## 7. Evidence trail

| File | Line | Evidence |
|------|------|----------|
| `web/index.php` | 21-22 | `define('PHPBB_FILESYSTEM_ROOT', __DIR__ . '/../')` + `$phpbb_root_path = './'` |
| `src/phpbb/common/common.php` | 102 | `new \phpbb\di\container_builder($phpbb_root_path)` |
| `src/phpbb/forums/di/container_builder.php` | 594-603 | `get_core_parameters()` sets `core.root_path` |
| `src/phpbb/forums/di/container_builder.php` | 406, 416 | `get_config_path()`, `get_cache_dir()` use `$this->phpbb_root_path` |
| `src/phpbb/forums/di/container_builder.php` | 674 | `->in($this->phpbb_root_path . 'ext')` |
| `src/phpbb/forums/hook/finder.php` | 70 | `opendir($this->phpbb_root_path . 'src/phpbb/common/hooks/')` |
| `src/phpbb/forums/language/language_file_helper.php` | 51 | `->in($this->phpbb_root_path . 'src/phpbb/language')` |
| `src/phpbb/forums/extension/manager.php` | 380, 387 | `is_dir(root . 'src/phpbb/ext/')`, `RecursiveDirectoryIterator` |
| `src/phpbb/forums/avatar/driver/upload.php` | 279, 330 | FS avatar path operations |
| `src/phpbb/forums/auth/provider/apache.php` | 205 | `include($this->phpbb_root_path . 'src/phpbb/common/functions_user.php')` |
| `src/phpbb/forums/routing/resources_locator/default_resources_locator.php` | 85-95 | `file_exists(root . $path . 'config/...')` |
| `src/phpbb/forums/textformatter/s9e/quote_helper.php` | 47-49 | `append_sid($root_path . 'viewtopic.php', ...)` → URL |
| `src/phpbb/forums/log/log.php` | 425, 651, 749-751 | `append_sid("{$root}memberlist.php", ...)` → URL |
| `src/phpbb/forums/routing/helper.php` | 133-143 | Explicit check: skips URL prefix if root_path starts with `/` |
