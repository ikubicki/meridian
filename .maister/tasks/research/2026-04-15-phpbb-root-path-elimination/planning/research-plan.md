# Research Plan: Eliminate `$phpbb_root_path` from Filesystem Include Operations

## Research Overview

**Research Question**: How to eliminate `$phpbb_root_path` from all filesystem `require`/`include` operations in this phpBB codebase, replacing class loading with Composer PSR-4 autoload and remaining procedural includes with `__DIR__`-based absolute paths?

**Research Type**: Technical — codebase analysis, architecture mapping, dependency tracing

**Scope**:
- Filesystem `require`/`include` uses of `$phpbb_root_path` (not URL generation uses)
- All entrypoints: `web/*.php`, `bin/phpbbcli.php`, `src/phpbb/install/app.php`
- Bootstrap chain: `common.php`, `startup.php`, `src/phpbb/install/startup.php`
- DI service classes that store `$phpbb_root_path` as a constructor parameter
- Out of scope: instances where `$phpbb_root_path` is used purely for URL generation (e.g., `append_sid("{$phpbb_root_path}viewtopic.php", ...)`)

**Known Context**:
- `PHPBB_FILESYSTEM_ROOT = __DIR__ . '/../'` already defined in every `web/*.php`
- `$phpbb_root_path = './'` is set in `web/*.php` for URL generation (relative to web/)
- Composer PSR-4 active: `phpbb\forums\` → `src/phpbb/forums/`, `phpbb\common\` → `src/phpbb/common/`
- `vendor/autoload.php` already loaded via `startup.php` (once reachable)
- Root problem: `common.php` calls `require($phpbb_root_path . 'src/phpbb/common/startup.php')` but CWD is `/var/www/phpbb/web/` so `./src/phpbb/...` resolves incorrectly

---

## Methodology

**Primary Approach**: Static codebase analysis — trace all filesystem includes that use `$phpbb_root_path`, classify each by replacement strategy, and identify downstream DI service changes.

**Two-track classification for each include**:
1. **Class-loading includes** → can be deleted entirely once Composer autoload is loaded before `common.php` (e.g., `require($phpbb_root_path . 'src/phpbb/forums/class_loader.php')`)
2. **Procedural/non-autoloadable includes** → must be rewritten as `require(__DIR__ . '/relative/path.php')` or `require(PHPBB_FILESYSTEM_ROOT . 'relative/path.php')`

**Fallback**: If a procedural file itself references `$phpbb_root_path` for nested includes, trace those recursively.

---

## Research Phases

### Phase 1: Broad Discovery
- Enumerate every `require`/`include` statement containing `$phpbb_root_path` across the full codebase
- Identify every file that sets `$phpbb_root_path` (entrypoints) and every file that receives it (via `global` or constructor)
- Enumerate all Composer PSR-4 registered namespaces from `composer.json`

### Phase 2: Targeted Reading
- Read `src/phpbb/common/common.php` fully — map all includes and their fixability
- Read `src/phpbb/common/startup.php` — identify the vendor/autoload require and its guard
- Read `bin/phpbbcli.php` — already uses `$phpbb_root_path = __DIR__ . '/../'` (absolute), determine if safe
- Read `src/phpbb/install/app.php` and `src/phpbb/install/startup.php` — relative path fallback pattern
- Read DI service constructors: `container_builder`, `config_php_file`, `finder`, `extension/manager`, `path_helper`, `user.php`

### Phase 3: Deep Dive
- For each DI service that stores `$this->phpbb_root_path`, determine what it uses it for:
  - Filesystem operations (file_exists, is_dir, include) → must be changed to `PHPBB_FILESYSTEM_ROOT`
  - Passing to sub-objects as a path parameter → propagates the problem
  - URL path construction → out of scope (leave as-is)
- Check `src/phpbb/common/config/` for YAML service definitions that inject `%core.root_path%` (the container parameter)
- Determine if `core.root_path` container parameter can be changed from `'./'` to `PHPBB_FILESYSTEM_ROOT` without breaking URL generation

### Phase 4: Verification Strategy
- For each changed include, confirm the target file is covered by PSR-4 (namespace `phpbb\...`) OR exists as a procedural file that must be explicitly required
- Verify `vendor/autoload.php` loading happens before any class reference in `common.php`
- Document files that remain as explicit requires after PSR-4 adoption (constants.php, hooks/index.php, hooks/*.php, functions_compatibility.php)

---

## Gathering Strategy

### Instances: 3

| # | Category ID | Focus Area | Tools | Output Prefix |
|---|------------|------------|-------|---------------|
| 1 | `bootstrap-chain` | `common.php`, `startup.php`, `bin/phpbbcli.php`, `web/*.php` entrypoints, `install/app.php` — full include chain from request entry to bootstrap complete | Grep, Read | `bootstrap-chain` |
| 2 | `di-services` | DI service PHP files that store `$phpbb_root_path` as property (`container_builder`, `config_php_file`, `finder`, `extension/manager`, `path_helper`, `user.php`, `auth/provider/*`) plus YAML service configs that inject `%core.root_path%` | Grep, Read | `di-services` |
| 3 | `install-scripts` | `src/phpbb/install/app.php`, `src/phpbb/install/startup.php`, `src/phpbb/install/convert/convertor.php` and any other install-path files that use `$phpbb_root_path` for filesystem requires | Grep, Read | `install-scripts` |

### Rationale
- **bootstrap-chain** is the critical path where the CWD bug lives — it needs full end-to-end mapping
- **di-services** holds the systemic root of the problem: passing `'./'` as a root path into constructors propagates broken paths throughout the DI container; this category maps what changes are needed in service layer after the bootstrap fix
- **install-scripts** is isolated because install uses a completely different bootstrap (`install/startup.php` with `phpbb_require_updated()`) and has its own relative-path fallback pattern that requires separate treatment

---

## Success Criteria

- [ ] Every `require`/`include` that uses `$phpbb_root_path` for a filesystem path is catalogued
- [ ] Each instance classified: (a) delete (PSR-4 autoloads it), (b) rewrite with `__DIR__`, (c) rewrite with `PHPBB_FILESYSTEM_ROOT`, (d) out-of-scope (URL generation)
- [ ] DI services identified that must receive `PHPBB_FILESYSTEM_ROOT` instead of `$phpbb_root_path = './'`
- [ ] Container parameter `core.root_path` impact assessed (URL vs filesystem dual use)
- [ ] Install subsystem changes documented separately from web bootstrap changes
- [ ] Procedural files that cannot be autoloaded are explicitly listed

---

## Expected Outputs

- `analysis/findings/bootstrap-chain-*.md` — per-file include maps with replacement recommendations
- `analysis/findings/di-services-*.md` — service class audit with property-usage classification
- `analysis/findings/install-scripts-*.md` — install subsystem include audit
- `outputs/research-report.md` — consolidated findings with prioritised change list
