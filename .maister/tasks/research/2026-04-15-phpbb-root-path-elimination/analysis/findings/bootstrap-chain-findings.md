# Bootstrap-Chain Findings: `$phpbb_root_path` Elimination

**Research question**: How to eliminate `$phpbb_root_path` from all filesystem `require`/`include` operations?

---

## 1. How `$phpbb_root_path` Flows Into `common.php`

`common.php` does **not** define `$phpbb_root_path`. It arrives as a **free variable** from the caller's scope:

| Caller | Value set | Safe? |
|--------|-----------|-------|
| `web/*.php` | `$phpbb_root_path = './'` | ❌ URL-relative; CWD = `/var/www/phpbb/web/` → resolves to wrong dir |
| `bin/phpbbcli.php` | `$phpbb_root_path = __DIR__ . '/../'` | ✅ Absolute path, always correct |
| `web/index.php` then `common.php` include via `PHPBB_FILESYSTEM_ROOT` | The constant guards the include, but `$phpbb_root_path` still `'./'` | ❌ Same problem inside common.php |

**Root cause**: `common.php` (and `startup.php`) inherit the broken `'./'` from web callers and build filesystem paths from it.

---

## 2. `startup.php` — Use/Modification of `$phpbb_root_path`

**startup.php uses `$phpbb_root_path` but NEVER modifies it.**  
Only two uses:

- Line 75: `if (!file_exists($phpbb_root_path . 'vendor/autoload.php'))` — existence check
- Line 83: `require($phpbb_root_path . 'vendor/autoload.php');` — load Composer autoloader

---

## 3. `bin/phpbbcli.php` — Already Uses `__DIR__`

**Yes**, line 25:
```php
$phpbb_root_path = __DIR__ . '/../';
```
`__DIR__` in `bin/phpbbcli.php` = `/var/www/phpbb/bin/`, so `$phpbb_root_path` = `/var/www/phpbb/` — **absolute and correct**.  
All subsequent requires in phpbbcli.php are therefore safe today, but still route through the variable.

---

## 4. PSR-4 Autoloadability Audit

Composer PSR-4 in `composer.json`:
```json
"psr-4": {
    "phpbb\\forums\\": "src/phpbb/forums/",
    "phpbb\\common\\": "src/phpbb/common/"
}
```

| Required file | Contains | Namespace | PSR-4 match? | Autoloadable? |
|---------------|----------|-----------|--------------|---------------|
| `src/phpbb/common/functions.php` | procedural functions | none | — | ❌ No |
| `src/phpbb/common/functions_content.php` | procedural functions | none | — | ❌ No |
| `src/phpbb/common/functions_compatibility.php` | procedural functions | none | — | ❌ No |
| `src/phpbb/common/constants.php` | constant definitions | none | — | ❌ No |
| `src/phpbb/common/utf/utf_tools.php` | procedural UTF functions | none | — | ❌ No |
| `src/phpbb/common/compatibility_globals.php` | procedural globals | none | — | ❌ No |
| `src/phpbb/common/hooks/index.php` | `class phpbb_hook` | none (global) | — | ❌ No (non-NS class) |
| `src/phpbb/forums/filesystem/filesystem.php` | `class filesystem` | `phpbb\filesystem` | `phpbb\forums\` ≠ `phpbb\filesystem` | ❌ No (namespace mismatch) |
| `vendor/autoload.php` | Composer bootstrap | — | N/A | ❌ Must be required explicitly |

**Conclusion: ZERO requires in `common.php` or `startup.php` can be removed due to autoloading.** All files are either procedural or have namespace/path mismatches with the current PSR-4.

---

## 5. Complete Inventory Table

### 5a. `src/phpbb/common/startup.php`

| Line | Statement | Type | Classification | Proposed Replacement |
|------|-----------|------|----------------|----------------------|
| 70 | `require(getenv('PHPBB_AUTOLOAD'));` | bootstrap / env-dynamic | [KEEP-DYNAMIC] | No change — path is controlled by environment variable |
| 75 | `if (!file_exists($phpbb_root_path . 'vendor/autoload.php'))` | existence check | [USE-__DIR__] | `if (!file_exists(__DIR__ . '/../../../vendor/autoload.php'))` |
| 83 | `require($phpbb_root_path . 'vendor/autoload.php');` | bootstrap | [USE-__DIR__] | `require(__DIR__ . '/../../../vendor/autoload.php');` |

`__DIR__` in startup.php = `src/phpbb/common/`, so `__DIR__ . '/../../../'` = project root ✓

**After this change**, `startup.php` no longer reads `$phpbb_root_path` at all.

---

### 5b. `src/phpbb/common/common.php`

`__DIR__` in common.php = `src/phpbb/common/`

| Line | Statement | Type | Classification | Proposed Replacement |
|------|-----------|------|----------------|----------------------|
| 23 | `require($phpbb_root_path . 'src/phpbb/common/startup.php');` | bootstrap | [USE-__DIR__] | `require(__DIR__ . '/startup.php');` |
| 36 | `require($phpbb_root_path . 'src/phpbb/common/functions.php');` *(conditional: !PHPBB_INSTALLED)* | procedural | [USE-__DIR__] | `require(__DIR__ . '/functions.php');` |
| 53 | `require($phpbb_root_path . 'src/phpbb/forums/filesystem/filesystem.php');` *(conditional: !PHPBB_INSTALLED)* | class file | [USE-__DIR__] | `require(__DIR__ . '/../forums/filesystem/filesystem.php');` |
| 78 | `require($phpbb_root_path . 'src/phpbb/common/functions.php');` | procedural | [USE-__DIR__] | `require(__DIR__ . '/functions.php');` |
| 79 | `require($phpbb_root_path . 'src/phpbb/common/functions_content.php');` | procedural | [USE-__DIR__] | `require(__DIR__ . '/functions_content.php');` |
| 80 | `include($phpbb_root_path . 'src/phpbb/common/functions_compatibility.php');` | procedural (legacy compat) | [USE-__DIR__] | `include(__DIR__ . '/functions_compatibility.php');` |
| 82 | `require($phpbb_root_path . 'src/phpbb/common/constants.php');` | declarative constants | [USE-__DIR__] | `require(__DIR__ . '/constants.php');` |
| 83 | `require($phpbb_root_path . 'src/phpbb/common/utf/utf_tools.php');` | procedural UTF | [USE-__DIR__] | `require(__DIR__ . '/utf/utf_tools.php');` |
| 137 | `require($phpbb_root_path . 'src/phpbb/common/compatibility_globals.php');` | procedural | [USE-__DIR__] | `require(__DIR__ . '/compatibility_globals.php');` |
| 142 | `require($phpbb_root_path . 'src/phpbb/common/hooks/index.php');` | global class | [USE-__DIR__] | `require(__DIR__ . '/hooks/index.php');` |
| 150 | `@include($phpbb_root_path . 'src/phpbb/common/hooks/' . $hook . '.php');` | dynamic (hook name from finder) | [USE-__DIR__] + dynamic suffix | `@include(__DIR__ . '/hooks/' . $hook . '.php');` |

**Observation — line 36 vs. line 78**: `functions.php` is required TWICE:
- Line 36 inside the `!PHPBB_INSTALLED` branch (early, to call `phpbb_get_install_redirect()`)
- Line 78 in the main flow (always)

If `!PHPBB_INSTALLED`, the early require at line 36 fires, then `exit` at line 66 stops execution — so line 78 is NOT double-loaded in that case. If `PHPBB_INSTALLED`, only line 78 runs. No double-load issue.

---

### 5c. `bin/phpbbcli.php`

`$phpbb_root_path = __DIR__ . '/../'` at line 25 makes all paths ABSOLUTE already.  
Replacing with inline `__DIR__ . '/../'` would eliminate the variable from this file.

| Line | Statement | Type | Classification | Proposed Replacement |
|------|-----------|------|----------------|----------------------|
| 26 | `require($phpbb_root_path . 'src/phpbb/common/startup.php');` | bootstrap | [USE-__DIR__] | `require(__DIR__ . '/../src/phpbb/common/startup.php');` |
| 36 | `require($phpbb_root_path . 'src/phpbb/common/constants.php');` | declarative | [USE-__DIR__] | `require(__DIR__ . '/../src/phpbb/common/constants.php');` |
| 37 | `require($phpbb_root_path . 'src/phpbb/common/functions.php');` | procedural | [USE-__DIR__] | `require(__DIR__ . '/../src/phpbb/common/functions.php');` |
| 38 | `require($phpbb_root_path . 'src/phpbb/common/functions_admin.php');` | procedural | [USE-__DIR__] | `require(__DIR__ . '/../src/phpbb/common/functions_admin.php');` |
| 39 | `require($phpbb_root_path . 'src/phpbb/common/utf/utf_tools.php');` | procedural | [USE-__DIR__] | `require(__DIR__ . '/../src/phpbb/common/utf/utf_tools.php');` |
| 40 | `require($phpbb_root_path . 'src/phpbb/common/functions_compatibility.php');` | procedural | [USE-__DIR__] | `require(__DIR__ . '/../src/phpbb/common/functions_compatibility.php');` |
| 63 | `require($phpbb_root_path . 'src/phpbb/common/compatibility_globals.php');` | procedural | [USE-__DIR__] | `require(__DIR__ . '/../src/phpbb/common/compatibility_globals.php');` |

Note: phpbbcli.php also passes `$phpbb_root_path` to `\phpbb\config_php_file` (line 30) and `\phpbb\di\container_builder` (line 47) and `\phpbb\class_loader` (line 59) — those are NOT require/include operations and are out of scope here, but the variable would still need to exist for those calls (or those constructors must be migrated to use `PHPBB_FILESYSTEM_ROOT` or a defined constant).

---

### 5d. `web/index.php` (caller, reference)

```php
define('PHPBB_FILESYSTEM_ROOT', __DIR__ . '/../');  // = /var/www/phpbb/
$phpbb_root_path = './';                             // BROKEN for filesystem use
include(PHPBB_FILESYSTEM_ROOT . 'src/phpbb/common/common.php');     // ✅ correct
include(PHPBB_FILESYSTEM_ROOT . 'src/phpbb/common/functions_display.php'); // ✅ correct
```

The two includes in web/index.php already use `PHPBB_FILESYSTEM_ROOT` correctly. The only problem is `$phpbb_root_path = './'` which leaks into `common.php` and `startup.php`.

---

## 6. Summary: How Many Require/Includes Can Be Eliminated vs. Must Remain

| Classification | Count | Description |
|----------------|-------|-------------|
| [AUTOLOAD-REMOVED] | **0** | No files are PSR-4 autoloadable with current config |
| [USE-__DIR__] | **17** | All static paths → replace `$phpbb_root_path . 'X'` with `__DIR__ . '/relative/X'` |
| [KEEP-DYNAMIC] | **1** | `require(getenv('PHPBB_AUTOLOAD'))` — env-controlled, leave as-is |
| [USE-__DIR__] + dynamic suffix | **1** | `hooks/$hook.php` — base becomes `__DIR__`, suffix stays variable |

**Total requires/includes across the three files: 19**  
**Eliminates `$phpbb_root_path` from require/include: 18** (all except the `getenv()` one)

---

## 7. Migration Strategy (Ordered by Dependency)

1. **Fix `startup.php` first** — it is a leaf in the include chain; changing it removes all `$phpbb_root_path` use from startup.php independently of callers.
2. **Fix `common.php`** — after startup.php is fixed, replace all 10 requires in common.php with `__DIR__`-based paths. The `$phpbb_root_path` variable will no longer be needed by any require/include in this file.
3. **Fix `bin/phpbbcli.php`** — straightforward since it already computes a correct absolute path; just inline the computation.
4. **`web/*.php` callers** — `PHPBB_FILESYSTEM_ROOT` is already correctly used for the top-level includes. No change needed for the two includes in each web file. The `$phpbb_root_path = './'` variable must remain for now because it is still passed to `$phpbb_container_builder`, `$phpbb_config_php_file`, etc. (non-include uses). Those constructors are a separate migration step.

**After steps 1–3**, `$phpbb_root_path` is no longer used in any `require`/`include` statement in the entire bootstrap chain. The variable survives only as an argument to OOP constructors, which is a separate concern.
