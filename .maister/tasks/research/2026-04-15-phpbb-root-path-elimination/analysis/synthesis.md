# Synthesis: Eliminate $phpbb_root_path from Filesystem Operations

## Research Question
How to eliminate `$phpbb_root_path` from filesystem `require`/`include` operations, replacing with Composer autoload and `__DIR__`-based paths?

## Root Cause
`$phpbb_root_path = './'` — set in `web/*.php` for URL generation — is **inherited as a free variable** by `common.php` and `startup.php`. These files use it for `require(./src/phpbb/...)`. With CWD = `/var/www/phpbb/web/`, `./src/phpbb/...` resolves to `web/src/phpbb/...` which does not exist.

## Three Distinct Problem Layers

### Layer 1 — Bootstrap chain requires (common.php + startup.php)
11 `require`/`include` statements, all using `$phpbb_root_path` for filesystem paths. Can be fixed immediately with `__DIR__`-based paths — zero impact on URL logic.

`__DIR__` in `common.php` = `/var/www/phpbb/src/phpbb/common/`
`__DIR__` in `startup.php` = `/var/www/phpbb/src/phpbb/common/`

Every require resolves correctly using `__DIR__` relative paths.

### Layer 2 — container_builder internal filesystem uses
`container_builder` receives `$phpbb_root_path = './'` and uses it for:
- `get_config_path()` → `'./' . 'src/phpbb/common/config'`
- `get_cache_dir()` → `'./' . 'cache/production/'`
- `register_ext_compiler_pass()` → `in('./' . 'ext')`

Fix: Use `PHPBB_FILESYSTEM_ROOT` constant (already defined in web/*.php) at the point of construction in `common.php`:
```php
$phpbb_container_builder = new \phpbb\di\container_builder(
    $phpbb_root_path,                                    // URL-relative, kept for core.root_path
    defined('PHPBB_FILESYSTEM_ROOT') ? PHPBB_FILESYSTEM_ROOT : realpath($phpbb_root_path) . '/'
);
```
And update `container_builder.__construct()` to accept this second parameter as `$filesystem_root_path`.

### Layer 3 — DI services (core.root_path → ~60 YAML usages)
35+ services receive `%core.root_path%` for filesystem operations (hook_finder, language.*, ext.manager, finder, avatar.driver.*, cron.*, auth.provider.*, migrator.*). These are medium-term work requiring a new `core.filesystem_root_path` parameter.

## What CANNOT Be Autoloaded (Layer 1 confirms)
Zero of the requires in `common.php`/`startup.php` are PSR-4 autoloadable:
- All are procedural (no class) OR non-namespaced classes OR `vendor/autoload.php` itself
- Conclusion: `__DIR__`-based paths, not autoload, is the fix for Layer 1

## Cross-Source Patterns

**Pattern 1** — `startup.php` is self-contained once `__DIR__` is used
After replacing lines 75+83 with `__DIR__ . '/../../../vendor/autoload.php'`, `startup.php` does not need `$phpbb_root_path` at all.

**Pattern 2** — `common.php` needs PHPBB_FILESYSTEM_ROOT for container_builder, not for requires
The requires are all relative to `common.php`'s own `__DIR__`. The container_builder is the only place needing the absolute filesystem root.

**Pattern 3** — Installer is already safe
`web/install.php` defines `PHPBB_ROOT_PATH = __DIR__ . '/../'` (absolute). `install/app.php` reads it correctly. Only the fallback `'../../../'` needs hardening.

**Pattern 4** — `bin/phpbbcli.php` is already safe
Uses `__DIR__ . '/../'` from line 25 — gives an absolute path.

## Confidence
- Layer 1 fix: **High** — direct code analysis with exact line numbers and `__DIR__` values
- Layer 2 fix: **High** — container_builder construction site identified, clear solution
- Layer 3 fix: **Medium** — scope of YAML service changes is large, needs careful testing
