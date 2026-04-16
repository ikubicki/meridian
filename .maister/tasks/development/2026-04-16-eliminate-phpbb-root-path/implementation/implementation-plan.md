# Implementation Plan: Eliminate $phpbb_root_path from Filesystem Operations (Layer 1+2)

## Overview

Total Changes: ~40 individual line/block substitutions across 7 files
Task Groups: 5
Expected Tests: Manual smoke only (no unit tests — CWD-dependent; has_reproducible_defect: false)
No TDD red/green gate applies.

---

## Implementation Steps

### Task Group 1: Layer 1 — `__DIR__`-based requires in startup.php + common.php
**Dependencies:** None
**Files:** `src/phpbb/common/startup.php`, `src/phpbb/common/common.php`
**Estimated Steps:** 13 line changes

- [ ] 1.0 Replace all `$phpbb_root_path`-based `require`/`include` with `__DIR__`-relative paths
  - [ ] 1.1 `src/phpbb/common/startup.php` — line 75: `file_exists` check
    ```diff
    - if (!file_exists($phpbb_root_path . 'vendor/autoload.php'))
    + if (!file_exists(__DIR__ . '/../../../vendor/autoload.php'))
    ```
    Verify: `__DIR__` in startup.php = `.../src/phpbb/common` → `../../../vendor/autoload.php` = `<root>/vendor/autoload.php`
  - [ ] 1.2 `src/phpbb/common/startup.php` — line 83: `require` vendor autoload
    ```diff
    - require($phpbb_root_path . 'vendor/autoload.php');
    + require(__DIR__ . '/../../../vendor/autoload.php');
    ```
    After 1.1 + 1.2: `startup.php` no longer reads `$phpbb_root_path` at all.
  - [ ] 1.3 `src/phpbb/common/common.php` — line 23: bootstrap require of startup.php
    ```diff
    - require($phpbb_root_path . 'src/phpbb/common/startup.php');
    + require(__DIR__ . '/startup.php');
    ```
  - [ ] 1.4 `src/phpbb/common/common.php` — line 36: `!PHPBB_INSTALLED` block, functions.php
    ```diff
    - require($phpbb_root_path . 'src/phpbb/common/functions.php');
    + require(__DIR__ . '/functions.php');
    ```
  - [ ] 1.5 `src/phpbb/common/common.php` — line 53: `!PHPBB_INSTALLED` block, filesystem.php
    ```diff
    - require($phpbb_root_path . 'src/phpbb/forums/filesystem/filesystem.php');
    + require(__DIR__ . '/../forums/filesystem/filesystem.php');
    ```
  - [ ] 1.6 `src/phpbb/common/common.php` — lines 78–83: main include block (5 files)
    ```diff
    - require($phpbb_root_path . 'src/phpbb/common/functions.php');
    - require($phpbb_root_path . 'src/phpbb/common/functions_content.php');
    - include($phpbb_root_path . 'src/phpbb/common/functions_compatibility.php');
    -
    - require($phpbb_root_path . 'src/phpbb/common/constants.php');
    - require($phpbb_root_path . 'src/phpbb/common/utf/utf_tools.php');
    + require(__DIR__ . '/functions.php');
    + require(__DIR__ . '/functions_content.php');
    + include(__DIR__ . '/functions_compatibility.php');
    +
    + require(__DIR__ . '/constants.php');
    + require(__DIR__ . '/utf/utf_tools.php');
    ```
  - [ ] 1.7 `src/phpbb/common/common.php` — line 137: hooks compatibility_globals
    ```diff
    - require($phpbb_root_path . 'src/phpbb/common/compatibility_globals.php');
    + require(__DIR__ . '/compatibility_globals.php');
    ```
  - [ ] 1.8 `src/phpbb/common/common.php` — line 142: hooks index.php
    ```diff
    - require($phpbb_root_path . 'src/phpbb/common/hooks/index.php');
    + require(__DIR__ . '/hooks/index.php');
    ```
  - [ ] 1.9 `src/phpbb/common/common.php` — line 150: hook file dynamic include
    ```diff
    - @include($phpbb_root_path . 'src/phpbb/common/hooks/' . $hook . '.php');
    + @include(__DIR__ . '/hooks/' . $hook . '.php');
    ```

**Acceptance Criteria:**
- `startup.php` contains zero references to `$phpbb_root_path`
- All 9 `require`/`include` lines in common.php bootstrap and hook blocks use `__DIR__`
- No remaining `$phpbb_root_path .` in the patched regions of common.php (call-site regions in 2d/2e are intentionally left for Group 3)

---

### Task Group 2: Layer 2 — `container_builder.php` internal changes
**Dependencies:** Group 1
**Files:** `src/phpbb/forums/di/container_builder.php`
**Estimated Steps:** 8 changes (1 property + 2 constructor + 5 method bodies)

- [ ] 2.0 Add `$filesystem_root_path` parameter and update all internal usages
  - [ ] 2.1 Add `$filesystem_root_path` property after the existing `$phpbb_root_path` docblock/property
    ```diff
    +    /**
    +     * @var string Absolute filesystem root path
    +     */
    +    protected $filesystem_root_path;
    +
    ```
  - [ ] 2.2 Update `__construct()` signature — insert `$filesystem_root_path` as 2nd param with default `''`
    ```diff
    - public function __construct($phpbb_root_path, $php_ext = 'php')
    + public function __construct($phpbb_root_path, $filesystem_root_path = '', $php_ext = 'php')
    ```
    Rationale (Option B): `$phpbb_root_path` stays first → no change for existing 1-arg callers; `$php_ext` moves to position 3.
  - [ ] 2.3 Add `$filesystem_root_path` assignment in `__construct()` body
    ```diff
      $this->phpbb_root_path  = $phpbb_root_path;
    + $this->filesystem_root_path = $filesystem_root_path ?: (realpath($phpbb_root_path) . '/');
      $this->php_ext          = $php_ext;
    ```
    `realpath()` fallback ensures correctness when called without the new param (e.g. `install/` context via container_factory with `''`).
  - [ ] 2.4 `get_config_path()` (~line 413) — switch to `$filesystem_root_path`
    ```diff
    - return $this->config_path ?: $this->phpbb_root_path . 'src/phpbb/common/config';
    + return $this->config_path ?: $this->filesystem_root_path . 'src/phpbb/common/config';
    ```
  - [ ] 2.5 `get_cache_dir()` (~line 423) — switch to `$filesystem_root_path`
    ```diff
    - return $this->cache_dir ?: $this->phpbb_root_path . 'cache/' . $this->get_environment() . '/';
    + return $this->cache_dir ?: $this->filesystem_root_path . 'cache/' . $this->get_environment() . '/';
    ```
  - [ ] 2.6 `load_extensions()` internal self-instantiation (~line 436) — forward `$filesystem_root_path`
    ```diff
    - $container_builder = new container_builder($this->phpbb_root_path, $this->php_ext);
    + $container_builder = new container_builder($this->phpbb_root_path, $this->filesystem_root_path, $this->php_ext);
    ```
    Critical: without this fix the internal instance would use `$this->php_ext` as `$filesystem_root_path`.
  - [ ] 2.7 `get_core_parameters()` (~line 596) — register `core.filesystem_root_path`
    ```diff
      'core.root_path'     => $this->phpbb_root_path,
    + 'core.filesystem_root_path' => $this->filesystem_root_path,
      'core.php_ext'       => $this->php_ext,
    ```
  - [ ] 2.8 `register_ext_compiler_pass()` (~line 681) — switch `->in()` arg
    ```diff
    - ->in($this->phpbb_root_path . 'ext')
    + ->in($this->filesystem_root_path . 'ext')
    ```

**Acceptance Criteria:**
- `container_builder.__construct` has signature `($phpbb_root_path, $filesystem_root_path = '', $php_ext = 'php')`
- `$this->filesystem_root_path` is set in constructor with `realpath` fallback
- `get_config_path()`, `get_cache_dir()`, `register_ext_compiler_pass()` all use `$this->filesystem_root_path`
- `get_core_parameters()` includes `core.filesystem_root_path` key
- Internal self-instantiation in `load_extensions()` passes all three args

---

### Task Group 3: Layer 2 — Call sites (common.php + phpbbcli.php + container_factory.php)
**Dependencies:** Group 2 (container_builder signature must be updated first)
**Files:** `src/phpbb/common/common.php`, `bin/phpbbcli.php`, `src/phpbb/forums/install/helper/container_factory.php`
**Estimated Steps:** 5 changes

- [ ] 3.0 Update all callers to pass `$filesystem_root_path` correctly
  - [ ] 3.1 `src/phpbb/common/common.php` — introduce `$phpbb_filesystem_root` variable BEFORE `config_php_file` construction (before existing line 25)
    ```diff
    + $phpbb_filesystem_root = defined('PHPBB_FILESYSTEM_ROOT') ? PHPBB_FILESYSTEM_ROOT : realpath($phpbb_root_path) . '/';
    +
      $phpbb_config_php_file = new \phpbb\config_php_file($phpbb_root_path);
    ```
    `PHPBB_FILESYSTEM_ROOT` is `define()`d in all `web/*.php` entry points. CLI fallback uses `realpath()`.
  - [ ] 3.2 `src/phpbb/common/common.php` — update `config_php_file` call to pass absolute path
    ```diff
    - $phpbb_config_php_file = new \phpbb\config_php_file($phpbb_root_path);
    + $phpbb_config_php_file = new \phpbb\config_php_file($phpbb_filesystem_root);
    ```
    `config_php_file` signature is **unchanged** — the fix is solely in what is passed. Concatenation `$phpbb_root_path . 'src/phpbb/common/config/config.php'` now produces an absolute path.
  - [ ] 3.3 `src/phpbb/common/common.php` — update `container_builder` call to pass `$phpbb_filesystem_root`
    ```diff
    - $phpbb_container_builder = new \phpbb\di\container_builder($phpbb_root_path);
    + $phpbb_container_builder = new \phpbb\di\container_builder($phpbb_root_path, $phpbb_filesystem_root);
    ```
  - [ ] 3.4 `bin/phpbbcli.php` — line 41: update `container_builder` call
    ```diff
    - $phpbb_container_builder = new \phpbb\di\container_builder($phpbb_root_path);
    + $phpbb_container_builder = new \phpbb\di\container_builder($phpbb_root_path, $phpbb_root_path);
    ```
    `$phpbb_root_path` in CLI is already `__DIR__ . '/../'` (absolute). Passing it as both args is explicit and avoids `realpath()` overhead. `config_php_file` at L28 already receives this absolute path — **no change needed** there.
  - [ ] 3.5 `src/phpbb/forums/install/helper/container_factory.php` — line 147: fix positional arg corruption
    ```diff
    - $phpbb_container_builder = new \phpbb\di\container_builder($this->phpbb_root_path, $this->php_ext);
    + $phpbb_container_builder = new \phpbb\di\container_builder($this->phpbb_root_path, '', $this->php_ext);
    ```
    Without this fix, `$this->php_ext = 'php'` would be silently assigned to `$filesystem_root_path`, producing paths like `'phpsrc/phpbb/common/config'`. Passing `''` explicitly triggers the `realpath()` fallback in `container_builder.__construct`.

**Acceptance Criteria:**
- `$phpbb_filesystem_root` is defined before first use in `common.php`
- `config_php_file` receives an absolute path in all three call sites (common.php, phpbbcli.php — implicitly already correct, container_factory — indirectly via fallback)
- `container_builder` receives `$filesystem_root_path` in all three direct call sites
- `container_factory.php` passes explicit `''` as second arg, preserving `$php_ext` at position 3
- `bin/phpbbcli.php list` still executes without error after change

---

### Task Group 4: `web/download/file.php`
**Dependencies:** Group 2, Group 3 (container_builder must be updated; common.php defines used as reference)
**Files:** `web/download/file.php`
**Estimated Steps:** 14 changes

- [ ] 4.0 Replace all `$phpbb_root_path`-based filesystem operations with `$phpbb_filesystem_root`
  - [ ] 4.1 Line 19: introduce `$phpbb_filesystem_root` alongside existing `$phpbb_root_path` init
    ```diff
    - $phpbb_root_path = (defined('PHPBB_ROOT_PATH')) ? PHPBB_ROOT_PATH : '../../';
    + $phpbb_root_path = (defined('PHPBB_ROOT_PATH')) ? PHPBB_ROOT_PATH : './';
    + $phpbb_filesystem_root = defined('PHPBB_FILESYSTEM_ROOT') ? PHPBB_FILESYSTEM_ROOT : realpath(__DIR__ . '/../../') . '/';
    ```
    Note: `PHPBB_ROOT_PATH` / `PHPBB_FILESYSTEM_ROOT` are NOT `define()`d in `file.php` (unlike `web/*.php`), so `__DIR__` arithmetic is used for the fallback. `__DIR__` = `.../web/download`, so `../../` = project root.
  - [ ] 4.2 Line 37: `startup.php` require in avatar block
    ```diff
    - require($phpbb_root_path . 'src/phpbb/common/startup.php');
    + require($phpbb_filesystem_root . 'src/phpbb/common/startup.php');
    ```
  - [ ] 4.3 Line 39: `class_loader.php` require
    ```diff
    - require($phpbb_root_path . 'src/phpbb/forums/class_loader.php');
    + require($phpbb_filesystem_root . 'src/phpbb/forums/class_loader.php');
    ```
  - [ ] 4.4 Line 39: `class_loader` instantiation — path arg
    ```diff
    - $phpbb_class_loader = new \phpbb\class_loader('phpbb\\', "{$phpbb_root_path}src/phpbb/forums/");
    + $phpbb_class_loader = new \phpbb\class_loader('phpbb\\', "{$phpbb_filesystem_root}src/phpbb/forums/");
    ```
  - [ ] 4.5 Line 41: `config_php_file` call
    ```diff
    - $phpbb_config_php_file = new \phpbb\config_php_file($phpbb_root_path);
    + $phpbb_config_php_file = new \phpbb\config_php_file($phpbb_filesystem_root);
    ```
  - [ ] 4.6 Lines 54–57: four requires in `$_GET['avatar']` block
    ```diff
    - require($phpbb_root_path . 'src/phpbb/common/constants.php');
    - require($phpbb_root_path . 'src/phpbb/common/functions.php');
    - require($phpbb_root_path . 'src/phpbb/common/functions_download' . '.php');
    - require($phpbb_root_path . 'src/phpbb/common/utf/utf_tools.php');
    + require($phpbb_filesystem_root . 'src/phpbb/common/constants.php');
    + require($phpbb_filesystem_root . 'src/phpbb/common/functions.php');
    + require($phpbb_filesystem_root . 'src/phpbb/common/functions_download' . '.php');
    + require($phpbb_filesystem_root . 'src/phpbb/common/utf/utf_tools.php');
    ```
  - [ ] 4.7 Line ~61: `class_loader_ext` instantiation in avatar block
    ```diff
    - $phpbb_class_loader_ext = new \phpbb\class_loader('\\', "{$phpbb_root_path}ext/");
    + $phpbb_class_loader_ext = new \phpbb\class_loader('\\', "{$phpbb_filesystem_root}ext/");
    ```
  - [ ] 4.8 Line ~64: `container_builder` instantiation in avatar block
    ```diff
    - $phpbb_container_builder = new \phpbb\di\container_builder($phpbb_root_path);
    + $phpbb_container_builder = new \phpbb\di\container_builder($phpbb_root_path, $phpbb_filesystem_root);
    ```
  - [ ] 4.9 Lines 148–149: non-avatar (attachment download) block includes
    ```diff
    - include($phpbb_root_path . 'src/phpbb/common/common.php');
    - require($phpbb_root_path . 'src/phpbb/common/functions_download' . '.php');
    + include($phpbb_filesystem_root . 'src/phpbb/common/common.php');
    + require($phpbb_filesystem_root . 'src/phpbb/common/functions_download' . '.php');
    ```
    `$phpbb_filesystem_root` is available from line 19 onward (introduced in step 4.1).
  - [ ] 4.10 Verify: grep the file for any remaining `$phpbb_root_path .` uses in filesystem operations after the above — replace any found with `$phpbb_filesystem_root`
    ```bash
    grep -n 'phpbb_root_path \.' web/download/file.php
    ```
    URL-relative uses (if any) should remain as-is. Only filesystem `require`/`include`/path operations need the swap.

**Acceptance Criteria:**
- `$phpbb_filesystem_root` is set at line ~19 using `PHPBB_FILESYSTEM_ROOT` or `realpath(__DIR__ . '/../../')` fallback
- All `require`/`include` and class loader path args in the file use `$phpbb_filesystem_root`
- `container_builder` call passes `$phpbb_filesystem_root` as second arg
- No `$phpbb_root_path .` prefix remains on filesystem path operations

---

### Task Group 5: Smoke Verification
**Dependencies:** Groups 1, 2, 3, 4 (all implementation complete)
**Files:** None (manual + CLI verification)
**Estimated Steps:** 6 verification checks

- [ ] 5.0 Run all smoke verification checks and confirm zero regressions
  - [ ] 5.1 **Web bootstrap** — start Docker environment and request main page
    ```bash
    docker-compose up -d
    curl -o /dev/null -s -w "%{http_code}" http://localhost/
    # Expected: 200
    ```
  - [ ] 5.2 **Download file.php** — request avatar download endpoint
    ```bash
    curl -o /dev/null -s -w "%{http_code}" "http://localhost/download/file.php?avatar=1"
    # Expected: 200 or graceful exit (not 500)
    ```
  - [ ] 5.3 **PHP error log** — confirm no `require(): Failed opening` errors
    ```bash
    grep "Failed opening required" /var/log/php/error.log || echo "CLEAN"
    # Expected: CLEAN
    ```
  - [ ] 5.4 **CLI backward compatibility** — run phpbbcli
    ```bash
    php bin/phpbbcli.php list
    # Expected: exit code 0, Symfony command list shown
    ```
  - [ ] 5.5 **Container cache rebuild** — delete cache and verify rebuild
    ```bash
    rm -rf cache/production/
    curl -o /dev/null -s -w "%{http_code}" http://localhost/
    ls cache/production/container_*.php 2>/dev/null && echo "REBUILT" || echo "FAIL"
    # Expected: 200, REBUILT
    ```
  - [ ] 5.6 **`core.filesystem_root_path` registration** — verify container parameter
    ```bash
    php -r "
    define('IN_PHPBB', true);
    define('PHPBB_FILESYSTEM_ROOT', realpath(__DIR__) . '/');
    \$phpbb_root_path = './';
    require 'src/phpbb/common/common.php';
    echo \$phpbb_container->getParameter('core.filesystem_root_path');
    "
    # Expected: absolute path ending with /
    ```
    Or verify via `phpbbcli.php` if available.

**Acceptance Criteria (all must pass):**
- `web/index.php` — HTTP 200, no fatal error
- `web/download/file.php?avatar=1` — HTTP 200 or graceful `exit`, no fatal
- PHP error log — zero `Failed opening required` entries
- `php bin/phpbbcli.php list` — exit code 0
- Container cache file recreated after deletion
- `core.filesystem_root_path` parameter returns absolute path

---

## Execution Order

1. **Group 1** — Layer 1: startup.php + common.php `__DIR__` requires (13 changes, no deps)
2. **Group 2** — Layer 2: container_builder.php internal (8 changes, depends on 1)
3. **Group 3** — Layer 2 call sites: common.php call sites + phpbbcli.php + container_factory.php (5 changes, depends on 2)
4. **Group 4** — file.php (14 changes, depends on 2 + 3)
5. **Group 5** — Smoke verification (depends on 1-4 complete)

---

## Change Summary

| Group | Files | Changes | Depends on |
|-------|-------|---------|------------|
| 1 — Layer 1 requires | startup.php, common.php | 13 | None |
| 2 — container_builder internals | container_builder.php | 8 | Group 1 |
| 3 — Call sites | common.php, phpbbcli.php, container_factory.php | 5 | Group 2 |
| 4 — file.php | web/download/file.php | 14 | Groups 2+3 |
| 5 — Smoke | (manual) | 6 checks | Groups 1-4 |
| **Total** | **7 files** | **~40 changes** | |

---

## Standards Compliance

Follow standards from `.maister/docs/standards/`:
- `global/` — Always applicable (file structure, PHPDoc, naming)
- `backend/` — PHP namespacing, no raw user input in filesystem paths, no `global` in OOP code

**No new classes, services, or files are introduced.** All changes are in-place substitutions in existing files.

---

## Notes

- **No TDD gate**: This task has `has_reproducible_defect: false`; changes are mechanical path substitutions. Verification is integration/smoke only.
- **Strict execution order**: Group 3 (call sites) must NOT be applied before Group 2 (container_builder signature). Applying call sites with the old signature would pass 2 positional args to a 2-param constructor with no harm, but apply Group 2 first for consistency and safety.
- **`config_php_file.php` — no code changes**: The fix is entirely caller-side. The class receives an absolute path and its internal concatenation produces a correct result. Document this explicitly when reviewing.
- **Layer 3 deferred**: ~35 YAML DI services using `%core.root_path%` for filesystem are out of scope. `core.filesystem_root_path` will be registered in the container after Group 2 is complete, unblocking the follow-up Layer 3 task.
- **URL-relative uses remain**: `$phpbb_root_path` uses for `web_root_path`, `$phpbb_adm_relative_path`, session paths are intentionally unchanged — those are URL-relative by design.
