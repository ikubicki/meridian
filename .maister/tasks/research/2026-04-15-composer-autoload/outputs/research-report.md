# Research Report: Composer Autoload Migration (phpBB)

**Research question**: How can all `require`/`include` statements be replaced with Composer PSR-4 autoloading?
**Date**: 2026-04-15
**Confidence**: High

---

## Executive Summary

- **Full replacement is not achievable** — PHP has no mechanism to autoload functions, constants, or dynamically-computed paths.
- **Class autoloading (867 files) can be migrated immediately** with a single `composer.json` change and 3 lines removed from `common.php`.
- The custom `phpbb\class_loader` duplicates Composer PSR-4 exactly — it can be deleted after migration.
- Root `composer.json` currently has **no `autoload` section at all** — the fix is additive, not destructive.
- 11+ distinct sites of dynamic/runtime `include` will always remain; they are not a bug, they are runtime behavior.

---

## Current State

### Autoloading today

| Component | Status |
|-----------|--------|
| `vendor/autoload.php` loaded | ✅ Yes — in `startup.php` |
| `composer.json` `autoload` section | ❌ Missing entirely |
| `phpbb\` in `vendor/composer/autoload_psr4.php` | ❌ Absent |
| `phpbb\class_loader` (custom SPL autoloader) | ✅ Active — registered in `common.php` |
| Classes in `src/phpbb/forums/` using PSR-4 namespace | ✅ 852/852 (100%) |

### Bootstrap chain

```
web/{page}.php
  └─ include common.php
       ├─ require startup.php → require vendor/autoload.php
       ├─ require forums/class_loader.php → register SPL: phpbb\ → src/phpbb/forums/
       ├─ require functions.php            [procedural]
       ├─ require functions_content.php    [procedural]
       ├─ include functions_compatibility.php [procedural]
       ├─ require constants.php            [define() calls]
       ├─ require utf/utf_tools.php        [procedural]
       ├─ new container_builder → require cache/container.php [dynamic]
       ├─ require compatibility_globals.php [procedural]
       └─ require hooks/index.php + @include hooks/{name}.php [dynamic]
```

---

## What CAN Be Replaced with Composer Autoload

### 1. PSR-4 class loading — 852 files, zero code changes

Add to `composer.json` (inside the top-level JSON object):

```json
"autoload": {
    "psr-4": {
        "phpbb\\": "src/phpbb/forums/"
    }
}
```

This single entry covers all 852 namespaced class files in `src/phpbb/forums/`. Every `phpbb\auth\auth`, `phpbb\db\driver\*`, `phpbb\template\*`, etc. is immediately autoloadable via `vendor/autoload.php`.

Optional additional mappings (smaller sub-trees):

```json
"phpbb\\convert\\": "src/phpbb/install/convert/",
"phpbb\\viglink\\": "src/phpbb/ext/phpbb/viglink/"
```

### 2. Removal of custom class loader

After `composer dump-autoload` is run and verified, remove these 3 lines from `src/phpbb/common/common.php`:

```php
// REMOVE:
require($phpbb_filesystem_root . 'src/phpbb/forums/class_loader.php');
$phpbb_class_loader = new \phpbb\class_loader('phpbb\\', "{$phpbb_filesystem_root}src/phpbb/forums/", 'php');
$phpbb_class_loader->register();
```

Keep the ext loader (`$phpbb_class_loader_ext`) unchanged for now — its migration requires validating third-party extensions.

### 3. Optional — move always-on procedural files to `autoload.files`

These 5 files are included on every single request. They can be listed in `autoload.files` so that `vendor/autoload.php` loads them automatically, removing the explicit `require` lines from `common.php`:

```json
"files": [
    "src/phpbb/common/functions.php",
    "src/phpbb/common/functions_content.php",
    "src/phpbb/common/functions_compatibility.php",
    "src/phpbb/common/constants.php",
    "src/phpbb/common/utf/utf_tools.php"
]
```

**Trade-off**: This makes them always-eager (even for `css.php`/`js.php` which currently load nothing). If that is acceptable, the explicit `require` lines in `common.php` for these 5 files can then be removed. **Recommended: keep this as optional phase 2.**

---

## What CANNOT Be Replaced (Must Stay `require`)

### Procedural files — global functions and constants

PHP autoloading is class-only. Files that define global functions (`function redirect(…)`) or constants (`define('PHPBB_VERSION', …)`) must be explicitly `require`d. No autoloader can trigger them.

| File | Loaded by | Type |
|------|-----------|------|
| `src/phpbb/common/startup.php` | `common.php` | Bootstrap + side effects |
| `src/phpbb/common/functions.php` | `common.php` | ~66 global functions |
| `src/phpbb/common/functions_content.php` | `common.php` | ~23 global functions |
| `src/phpbb/common/functions_compatibility.php` | `common.php` | Compat shims |
| `src/phpbb/common/constants.php` | `common.php` | 100+ `define()` calls |
| `src/phpbb/common/utf/utf_tools.php` | `common.php` | ~27 UTF-8 functions |
| `src/phpbb/common/compatibility_globals.php` | `common.php` | Must run post-container |
| `src/phpbb/common/hooks/index.php` | `common.php` | Non-namespaced `phpbb_hook` class |
| `src/phpbb/common/functions_display.php` | `web/index.php` etc. | ~13 page-specific functions |
| `src/phpbb/common/functions_posting.php` | `web/posting.php` | ~16 page-specific functions |
| `src/phpbb/common/functions_user.php` | `web/ucp.php` etc. | ~43 page-specific functions |
| `src/phpbb/common/functions_admin.php` | `web/adm/` | Admin functions |
| `src/phpbb/common/functions_module.php` | `web/mcp.php` etc. | Module system |
| `src/phpbb/common/functions_mcp.php` | `web/mcp.php` | MCP functions |
| `src/phpbb/common/message_parser.php` | `web/posting.php` | Non-namespaced classes |
| `src/phpbb/common/bbcode.php` | `functions_content.php` | Non-namespaced class |

### Dynamic / runtime-computed paths

| Where | Pattern | Why dynamic |
|-------|---------|-------------|
| `common.php` | `@include hooks/{$name}.php` | File list discovered at runtime by hook_finder |
| `di/container_builder.php` | `require $config_cache->getPath()` | Generated Symfony container cache |
| `routing/router.php` | `require_once $cache->getPath()` | Generated URL matcher cache |
| `textformatter/s9e/renderer.php` | `include $cache_dir . $class . '.php'` | Generated S9E renderer |
| `config_php_file.php` | `require $this->config_file` | Injectable config path (changes during install) |
| `install/convertor.php` | `include './convertors/convert_{$tag}.php'` | User-provided tag |
| `mcp/mcp_warn.php` | `include language/{$user_lang}/mcp.php` | Runtime locale |
| `module/module_manager.php` | `include $directory . $info_class . '.php'` | Legacy module, dynamic name |
| `search/fulltext_sphinx.php` | `require sphinxapi.php` | Non-namespaced 3rd-party library |
| `passwords/driver/md5_phpbb2.php` | `include utf/data/recode_basic.php` | Large data arrays, lazy-load |

---

## Migration Steps

### Phase 1 — Core class autoloading (safe, reversible)

**Step 1**: Add `autoload` section to `composer.json`

Open `composer.json` and add after `"require-dev": {...},`:

```json
"autoload": {
    "psr-4": {
        "phpbb\\": "src/phpbb/forums/"
    }
},
```

**Step 2**: Regenerate autoload maps (in Docker container or locally with Composer installed)

```bash
docker compose exec app composer dump-autoload --optimize
```

**Step 3**: Smoke-test existing behavior (class loading still works via both loaders)

```bash
curl -s -o /dev/null -w "%{http_code}" http://localhost:8181/
# expect 200
```

**Step 4**: Remove custom loader wiring from `src/phpbb/common/common.php`

Remove these lines (exact lines — check with `grep -n class_loader src/phpbb/common/common.php`):

```php
require($phpbb_filesystem_root . 'src/phpbb/forums/class_loader.php');
$phpbb_class_loader = new \phpbb\class_loader('phpbb\\', "{$phpbb_filesystem_root}src/phpbb/forums/", 'php');
$phpbb_class_loader->register();
```

**Step 5**: Test again

```bash
curl -s -o /dev/null -w "%{http_code}" http://localhost:8181/
curl -s -o /dev/null -w "%{http_code}" http://localhost:8181/viewforum.php?f=1
curl -s -o /dev/null -w "%{http_code}" http://localhost:8181/ucp.php
```

Also check `bin/phpbbcli.php` — it wires the class loader manually too; remove there as well.

### Phase 2 (optional) — Move always-on procedural files to `autoload.files`

Only after Phase 1 is stable. Add a `files` array to the `autoload` section and remove the corresponding `require` lines from `common.php`. Performance-test before committing.

---

## Risk Assessment

| Risk | Severity | Mitigation |
|------|----------|-----------|
| Removing custom loader before Composer mapping active → class-not-found | **High** | Strictly sequence: add mapping → dump-autoload → test → remove loader |
| Over-removing procedural `require` → undefined function fatal | **High** | Only remove namespaced class `require`; never touch function files |
| Extension (`ext/`) loader change breaks third-party extensions | **Medium** | Do not touch `$phpbb_class_loader_ext` in Phase 1 |
| Adding too many `files[]` entries → memory overhead on every request | **Medium** | Keep `files[]` to always-on minimal set only |
| Composer namespace collision (`phpbb\` claimed by a package) | **Low** | Confirmed absent in current vendor map |

---

## Concrete Files to Change

### `composer.json` (root) — add `autoload` section
### `src/phpbb/common/common.php` — remove 3 lines (class_loader wiring)
### `bin/phpbbcli.php` — remove same class_loader wiring (check lines ~20–25)

No other files need to change for Phase 1.

---

## Conclusion

The migration is a **2-file change** (composer.json + common.php). The 852 PSR-4-ready class files require zero code changes. The custom `phpbb\class_loader` can be deleted after regenerating vendor autoload. Dynamic/runtime includes and procedural function files are architectural constants that cannot be autoloaded — they are not a regression, they are intended behavior.


## Current State

### Autoload Today

- `src/phpbb/common/startup.php` loads `vendor/autoload.php`.
- Root `composer.json` has no `autoload` section.
- `vendor/composer/autoload_psr4.php` has no `phpbb\` mapping.
- `src/phpbb/forums/class_loader.php` is manually loaded and registered to autoload `phpbb\` from `src/phpbb/forums/`.

### Scope Baseline

- 17 entry points in `web/` plus `web/download/file.php` analyzed.
- 1086 PHP files in `src/phpbb/` analyzed.
- Dynamic/conditional include sites inventoried (hooks, convertors, cache-generated files, language files).

## What CAN Be Replaced with Composer Autoload

### 1. Core PSR-4 class loading

Add to root `composer.json`:

```json
"autoload": {
  "psr-4": {
    "phpbb\\": "src/phpbb/forums/"
  },
  "files": [
    "src/phpbb/common/functions.php",
    "src/phpbb/common/functions_content.php",
    "src/phpbb/common/functions_compatibility.php",
    "src/phpbb/common/constants.php",
    "src/phpbb/common/utf/utf_tools.php"
  ]
}
```

Optional PSR-4 additions if desired:

```json
"phpbb\\convert\\": "src/phpbb/install/convert/",
"phpbb\\viglink\\": "src/phpbb/ext/phpbb/viglink/"
```

### 2. Custom class loader removal (core namespace only)

After `composer dump-autoload` and validation:
- remove manual require of `src/phpbb/forums/class_loader.php`
- remove registration of `new \phpbb\class_loader('phpbb\\', ...)`
- remove class-loader cache hook assignment for core loader

### 3. Some explicit class-file requires

Any explicit `require` of namespaced class files under `src/phpbb/forums/` becomes unnecessary after Composer mapping.

## What CANNOT Be Replaced (Must Remain Explicit)

### Procedural/global files

- `src/phpbb/common/functions*.php`
- `src/phpbb/common/constants.php`
- `src/phpbb/common/utf/utf_tools.php`
- `src/phpbb/common/compatibility_globals.php`

Reason: functions/constants/side effects are not PSR-4 class targets.

### Dynamic/runtime includes

- hook files discovered at runtime: `src/phpbb/common/hooks/*.php`
- installer convertor plugins: `src/phpbb/install/convertors/*`
- cache-generated PHP: DI container/router/textformatter cache files
- language files selected by runtime locale: `src/phpbb/language/...`

Reason: path decided dynamically at runtime.

### Bootstrap scripts

- `src/phpbb/common/startup.php`
- `src/phpbb/common/common.php`
- installer entry scripts

Reason: initialization ordering and side effects.

## Migration Strategy (Recommended)

1. Add root Composer autoload block
- Update root `composer.json` with PSR-4 + minimal `files` entries.

2. Regenerate autoload maps
- Run: `composer dump-autoload --optimize`

3. Validate core class resolution
- Smoke test front controller routes and CLI (`bin/phpbbcli.php`).
- Ensure no class-not-found for `phpbb\*` classes.

4. Remove redundant core custom loader wiring
- In `src/phpbb/common/common.php` and `bin/phpbbcli.php`, remove core loader registration for `phpbb\`.
- Keep extension loading path unchanged for now.

5. Keep explicit procedural includes where needed
- Do not remove page-specific lazy includes for procedural files.
- Do not remove dynamic include patterns.

6. Incremental cleanup
- Remove explicit requires for namespaced class files only after test coverage confirms parity.

## Concrete Changes Needed

### A. `composer.json`

Insert an `autoload` section (exact minimal block):

```json
"autoload": {
  "psr-4": {
    "phpbb\\": "src/phpbb/forums/"
  },
  "files": [
    "src/phpbb/common/functions.php",
    "src/phpbb/common/functions_content.php",
    "src/phpbb/common/functions_compatibility.php",
    "src/phpbb/common/constants.php",
    "src/phpbb/common/utf/utf_tools.php"
  ]
}
```

### B. `src/phpbb/common/common.php`

After Composer mapping is active and tested:
- remove:
  - `require($phpbb_root_path . 'src/phpbb/forums/class_loader.php');`
  - core loader instantiation and registration for `phpbb\`
- keep:
  - `require($phpbb_root_path . 'src/phpbb/common/startup.php');`
  - procedural includes (`functions.php`, `constants.php`, etc.)
  - hook dynamic includes

### C. Entry points in `web/*.php`

No broad rewrite is needed for autoload migration itself as long as bootstrap path continues through `common.php` and `startup.php`.

## Risk Assessment

### High

1. Bootstrap order regression
- Risk: Removing loader wiring before Composer PSR-4 is effective causes class-not-found.
- Mitigation: enforce sequence: composer update -> dump-autoload -> smoke tests -> remove loader.

2. Over-eager removal of procedural includes
- Risk: runtime fatal errors for undefined functions/constants.
- Mitigation: only remove explicit requires for namespaced class files; keep procedural includes.

### Medium

3. Extension autoload behavior
- Risk: changing/removing ext loader could break extensions that depend on legacy loading.
- Mitigation: do not migrate `$phpbb_class_loader_ext` in first phase.

4. Performance regression from broad `autoload.files`
- Risk: memory/startup cost if too many procedural files are eagerly loaded.
- Mitigation: keep `files` list minimal (always-on files only).

### Low

5. Composer namespace conflict
- Risk: package collision for `phpbb\`.
- Mitigation: confirmed absent in current vendor map.

## Confidence and Evidence

### Overall Confidence: High

Reasoning:
- direct inventory across entry points and bootstrap files
- direct inspection of Composer generated autoload maps
- full namespace/non-namespace scan across `src/phpbb/`
- consistent findings across five independent source categories

### Residual Uncertainty

- extension ecosystem compatibility for future ext-loader removal
- runtime performance impact of optional `autoload.files` expansion beyond minimal set

## Final Recommendation

Proceed with a staged hybrid migration:

1. Composer PSR-4 for `phpbb\` classes.
2. Keep procedural and dynamic includes explicit.
3. Remove only redundant class-loader code after verification.
4. Treat full "replace all requires" as a separate long-term refactor requiring procedural-to-OOP migration.
