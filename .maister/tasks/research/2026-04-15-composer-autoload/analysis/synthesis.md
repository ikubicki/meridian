# Synthesis: Composer Autoload Migration for phpBB

## Research Question
How can all `require`/`include` statements in this phpBB codebase be replaced with Composer PSR-4 autoloading?

## Short Answer
Full replacement is not possible — but the largest and most important category (class loading for `phpbb\*`) can be migrated cleanly. The remainder breaks down into two irremovable categories: procedural global-function files, and truly dynamic/runtime-computed paths.

## 1. Cross-Source Pattern Analysis

### Pattern 1 — Entry points are thin wrappers; `common.php` is the sole bootstrap hub
**Evidence**: entry-points-findings.md + bootstrap-findings.md

15 out of 17 `web/*.php` entry points do exactly one thing: `include(PHPBB_FILESYSTEM_ROOT . 'src/phpbb/common/common.php')`. Any autoload migration must happen inside `common.php` and `composer.json`, not spread across each entry file.

**Confidence**: High

---

### Pattern 2 — Composer is already bootstrapped, but `phpbb\` is not registered in it
**Evidence**: bootstrap-findings.md + composer-config-findings.md

`src/phpbb/common/startup.php` already loads `vendor/autoload.php`. The root `composer.json` has **no `autoload` section**. `vendor/composer/autoload_psr4.php` has zero `phpbb\` entries. The gap is purely in configuration, not in infrastructure.

**Confidence**: High

---

### Pattern 3 — `phpbb\class_loader` duplicates Composer PSR-4 exactly
**Evidence**: bootstrap-findings.md

The custom `src/phpbb/forums/class_loader.php` resolves `phpbb\Foo\Bar` → `src/phpbb/forums/Foo/Bar.php`. This is identical to what `"phpbb\\": "src/phpbb/forums/"` in `composer.json` would do. The custom loader is entirely redundant once Composer mapping is added.

**Confidence**: High

---

### Pattern 4 — 867/1086 class files (80%) are already PSR-4-ready with no code changes
**Evidence**: src-classes-findings.md

All 852 files in `src/phpbb/forums/` use `namespace phpbb\*` declarations that match their directory paths exactly. Adding one PSR-4 entry to `composer.json` immediately covers all of them. The remaining 219 files are in `src/phpbb/common/` (procedural) and `src/phpbb/language/` (arrays), which PSR-4 cannot cover by design.

**Confidence**: High

---

### Pattern 5 — Procedural files are not a PSR-4 problem; they are an architectural constant
**Evidence**: dynamic-includes-findings.md + entry-points-findings.md

`functions.php`, `constants.php`, `utf_tools.php`, and all `functions_*.php` files define global functions and `define()` constants — PHP has no mechanism to autoload these. They must remain explicit `require`. Optionally the always-on subset can move to Composer `autoload.files`, which auto-includes them via `vendor/autoload.php` instead of explicit `require` in `common.php`.

**Contradiction**: Some findings suggest moving all procedural files to `files[]`. Other findings flag performance cost of eager loading. **Reconciled**: Only always-sent-on-every-request files belong in `files[]`; page-specific ones stay lazy `require` in entry points.

**Confidence**: High (reconciliation: Medium-High pending benchmarks)

---

### Pattern 6 — Dynamic/runtime includes form a second irremovable category (11+ sites)
**Evidence**: dynamic-includes-findings.md

Runtime-computed paths span at least 11 distinct patterns: DI container cache, Symfony router cache, S9E textformatter cache, discovered hook files, user-selected language files, convertor plugin files (user-provided tag name), installer upgrade file selection, legacy module info classes, Sphinx API, UTF data files, config.php loader. None of these paths is known at compile time — they cannot be covered by any static autoload mechanism.

**Confidence**: High

---

## 2. What CAN Be Migrated

| What | How | Files Affected |
|------|-----|----------------|
| `phpbb\*` class loading | Add PSR-4 `"phpbb\\": "src/phpbb/forums/"` to `composer.json` | 852 class files — zero code changes |
| Optional: `phpbb\convert\*` | `"phpbb\\convert\\": "src/phpbb/install/convert/"` | 3 files |
| Optional: viglink extension | `"phpbb\\viglink\\": "src/phpbb/ext/phpbb/viglink/"` | 12 files |
| Always-on procedural includes | Move to `autoload.files[]` | 5 files (see below) |
| Removal of custom loader wiring | Delete from `common.php` lines 24–26 | 3 lines removed |

**Candidates for `autoload.files`** (always loaded on every request):
- `src/phpbb/common/functions.php`
- `src/phpbb/common/functions_content.php`
- `src/phpbb/common/functions_compatibility.php`
- `src/phpbb/common/constants.php`
- `src/phpbb/common/utf/utf_tools.php`

---

## 3. What MUST Stay Explicit `require`

| Category | Files / Pattern | Reason |
|----------|----------------|---------|
| Bootstrap chain | `startup.php`, `common.php` | Side effects, ordering |
| Per-page procedural | `functions_display.php`, `functions_posting.php`, `functions_user.php`, `functions_admin.php`, `functions_module.php`, `functions_mcp.php`, `message_parser.php`, `bbcode.php` | Global functions, lazy-loaded for memory |
| Compatibility | `compatibility_globals.php` | Must run after container is built |
| Hook system init | `hooks/index.php` | Non-namespaced `phpbb_hook` class + side effects |
| Dynamic hooks | `hooks/{$name}.php` | Runtime-discovered filenames |
| Language files | `language/{locale}/*.php` | PHP arrays, runtime locale |
| DI/Router/S9E cache | `cache/container.php`, `cache/url_*.php`, etc. | Generated PHP, runtime path |
| Convertor plugins | `convertors/convert_{tag}.php` | User-provided tag name |
| Config file | `config/config.php` | Loaded via `config_php_file` with injectable path |
| Sphinx API | `common/sphinxapi.php` | Non-namespaced 3rd-party library |
| UTF data arrays | `utf/data/recode_*.php` | PHP arrays, lazy-loaded |

---

## 4. Gaps and Uncertainties

1. **Extension autoload** (`$phpbb_class_loader_ext` mapping `\\` → `ext/`): migration of this secondary loader was not validated against third-party extensions in a real deployment. **Leave for phase 2.**
2. **Performance delta**: moving procedural files to `autoload.files` always-includes them (even if the request would not need them). Not benchmarked; minimal risk for always-needed files.
3. **No runtime integration tests** were executed in this research phase.

---

## 5. Overall Confidence

| Area | Confidence | Reason |
|------|-----------|--------|
| What CAN be autoloaded | **High** | Direct namespace + directory scan, no ambiguity |
| What MUST stay as require | **High** | Each site documented with file path and line number |
| Migration steps | **High** | Straight Composer config change + 3-line removal |
| Performance impact of `files[]` | **Medium** | Not benchmarked |
| ext loader migration | **Low** | Not validated against third-party extensions |
