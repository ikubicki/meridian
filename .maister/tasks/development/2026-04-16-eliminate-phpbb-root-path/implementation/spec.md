# Specification: Eliminate $phpbb_root_path from Filesystem Operations (Layer 1+2)

## Goal

Replace `$phpbb_root_path = './'` (URL-relative) with `__DIR__`-based absolute paths in all `require`/`include` bootstrap statements, and introduce a `$filesystem_root_path` parameter to `container_builder` and `config_php_file` so that filesystem operations always receive an absolute path regardless of CWD.

---

## Problem Statement

### Root Cause

In `web/index.php` and all other `web/*.php` entry points, the bootstrap is:

```php
$phpbb_root_path = (defined('PHPBB_ROOT_PATH')) ? PHPBB_ROOT_PATH : './';
define('IN_PHPBB', true);
require($phpbb_root_path . 'src/phpbb/common/common.php');
```

When executed via a web server with `CWD=/var/www/phpbb/web/`, the path `./src/phpbb/common/common.php` resolves to `/var/www/phpbb/web/src/phpbb/common/common.php` — a directory that does not exist. Every subsequent `require`/`include` using the same prefix fails with a fatal error.

`PHPBB_FILESYSTEM_ROOT` (absolute, e.g. `/var/www/phpbb/`) is already `define()`d in all `web/*.php` files but is **not** passed to `startup.php`, `container_builder`, or `config_php_file`.

### Why `bin/phpbbcli.php` Works

CLI sets `$phpbb_root_path = __DIR__ . '/../';` — which is an absolute path. CWD-independence is already guaranteed there; the only fix needed is forwarding it as the filesystem path to `container_builder`.

---

## Scope Boundaries

### In Scope

| File | Layer | Change type |
|------|-------|-------------|
| `src/phpbb/common/startup.php` | 1 | 2 `require`/`file_exists` lines → `__DIR__` |
| `src/phpbb/common/common.php` | 1+2 | 11 `require`/`include` lines + 2 call sites |
| `src/phpbb/forums/di/container_builder.php` | 2 | `__construct` signature, 4 methods, L436 self-call |
| `src/phpbb/forums/config_php_file.php` | 2 | `__construct` path computation |
| `bin/phpbbcli.php` | 2 | 2 call sites |
| `web/download/file.php` | 1+2 | `$phpbb_root_path` init + avatar block requires + L64 container_builder + non-avatar L148-149 |
| `src/phpbb/forums/install/helper/container_factory.php` | 2 | L147 container_builder positional arg fix |

### Out of Scope

- Layer 3: ~35 DI YAML services using `%core.root_path%` for filesystem — **deferred** (see section below)
- `install/convert/convertor.php`, `convert_phpbb20.php` — different execution context; backward-compat default param handles them
- URL-relative uses of `$phpbb_root_path` (session paths, `web_root_path`, `$phpbb_adm_relative_path`) — intentionally kept as-is
- `$phpbb_class_loader_ext` path argument — not a filesystem op that causes the bug; separate concern

---

## Architecture Decisions

### Decision 1: `__DIR__`-based paths in startup.php / common.php

`__DIR__` in `src/phpbb/common/common.php` resolves to `/var/www/phpbb/src/phpbb/common` at compile time. This is CWD-independent. All `require`/`include` statements in that file must be rewritten using `__DIR__` relative paths rather than `$phpbb_root_path` prefix.

Same applies to `startup.php` (`__DIR__` = `/var/www/phpbb/src/phpbb/common`).

### Decision 2: `container_builder` constructor — Option B

**Chosen**: Insert `$filesystem_root_path` as the **second** parameter with a default empty string:

```
__construct(string $phpbb_root_path, string $filesystem_root_path = '', string $php_ext = 'php')
```

Rationale:
- `$phpbb_root_path` stays first — no change for existing callers without the new param (e.g. `install/`)
- `$php_ext` moves to third position — no existing callers used positional 2nd for `$php_ext` with a non-default value (confirmed by codebase search)
- Empty-string default triggers fallback: `$this->filesystem_root_path = $filesystem_root_path ?: realpath($phpbb_root_path) . '/'`

### Decision 3: `config_php_file` receives absolute path directly

`config_php_file::__construct($phpbb_root_path)` builds `$this->config_file` via concatenation. Pass `PHPBB_FILESYSTEM_ROOT` (absolute) from `common.php`, so the concatenation `$phpbb_root_path . 'src/phpbb/common/config/config.php'` produces an absolute path. The `$phpbb_root_path` property name inside the class does not change — only what is passed in.

### Decision 4: `$phpbb_filesystem_root` variable in `common.php`

A local variable is introduced before `container_builder` construction:

```php
$phpbb_filesystem_root = defined('PHPBB_FILESYSTEM_ROOT') ? PHPBB_FILESYSTEM_ROOT : realpath($phpbb_root_path) . '/';
```

This variable is then passed to both `container_builder` and `config_php_file`. The `realpath()` fallback ensures correctness for callers (like CLI) where `PHPBB_FILESYSTEM_ROOT` is not defined.

---

## File-by-File Changes

### 1. `src/phpbb/common/startup.php`

**Lines 75 and 83** — vendor autoload check and require:

```diff
-	if (!file_exists($phpbb_root_path . 'vendor/autoload.php'))
+	if (!file_exists(__DIR__ . '/../../../vendor/autoload.php'))
```

```diff
-	require($phpbb_root_path . 'vendor/autoload.php');
+	require(__DIR__ . '/../../../vendor/autoload.php');
```

`__DIR__` = `.../src/phpbb/common`, so `__DIR__ . '/../../../vendor/autoload.php'` = `<project_root>/vendor/autoload.php`.

After this change `startup.php` no longer reads `$phpbb_root_path` at all.

---

### 2. `src/phpbb/common/common.php`

#### 2a. Bootstrap require (line 23)

```diff
-require($phpbb_root_path . 'src/phpbb/common/startup.php');
+require(__DIR__ . '/startup.php');
```

#### 2b. `!PHPBB_INSTALLED` block (lines 36, 53)

```diff
-	require($phpbb_root_path . 'src/phpbb/common/functions.php');
+	require(__DIR__ . '/functions.php');
```

```diff
-	require($phpbb_root_path . 'src/phpbb/forums/filesystem/filesystem.php');
+	require(__DIR__ . '/../forums/filesystem/filesystem.php');
```

#### 2c. Main include block (lines 78–83)

```diff
-require($phpbb_root_path . 'src/phpbb/common/functions.php');
-require($phpbb_root_path . 'src/phpbb/common/functions_content.php');
-include($phpbb_root_path . 'src/phpbb/common/functions_compatibility.php');
-
-require($phpbb_root_path . 'src/phpbb/common/constants.php');
-require($phpbb_root_path . 'src/phpbb/common/utf/utf_tools.php');
+require(__DIR__ . '/functions.php');
+require(__DIR__ . '/functions_content.php');
+include(__DIR__ . '/functions_compatibility.php');
+
+require(__DIR__ . '/constants.php');
+require(__DIR__ . '/utf/utf_tools.php');
```

#### 2d. New `$phpbb_filesystem_root` variable (before line 25) + call sites (lines 25, 102)

Introduce the variable **before** the `config_php_file` and `container_builder` constructions:

```diff
+$phpbb_filesystem_root = defined('PHPBB_FILESYSTEM_ROOT') ? PHPBB_FILESYSTEM_ROOT : realpath($phpbb_root_path) . '/';
+
-$phpbb_config_php_file = new \phpbb\config_php_file($phpbb_root_path);
+$phpbb_config_php_file = new \phpbb\config_php_file($phpbb_filesystem_root);
```

```diff
-	$phpbb_container_builder = new \phpbb\di\container_builder($phpbb_root_path);
+	$phpbb_container_builder = new \phpbb\di\container_builder($phpbb_root_path, $phpbb_filesystem_root);
```

#### 2e. Hooks includes (lines 137, 142, 150)

```diff
-require($phpbb_root_path . 'src/phpbb/common/compatibility_globals.php');
+require(__DIR__ . '/compatibility_globals.php');
```

```diff
-require($phpbb_root_path . 'src/phpbb/common/hooks/index.php');
+require(__DIR__ . '/hooks/index.php');
```

```diff
-	@include($phpbb_root_path . 'src/phpbb/common/hooks/' . $hook . '.php');
+	@include(__DIR__ . '/hooks/' . $hook . '.php');
```

---

### 3. `src/phpbb/forums/di/container_builder.php`

#### 3a. Add `$filesystem_root_path` property (class body, after `$phpbb_root_path` docblock)

```diff
+	/**
+	 * @var string Absolute filesystem root path
+	 */
+	protected $filesystem_root_path;
+
```

#### 3b. `__construct()` signature and body (line ~130)

```diff
-	public function __construct($phpbb_root_path, $php_ext = 'php')
+	public function __construct($phpbb_root_path, $filesystem_root_path = '', $php_ext = 'php')
 	{
 		$this->phpbb_root_path	= $phpbb_root_path;
+		$this->filesystem_root_path = $filesystem_root_path ?: (realpath($phpbb_root_path) . '/');
 		$this->php_ext			= $php_ext;
 		$this->env_parameters	= $this->get_env_parameters();
```

#### 3c. `get_config_path()` (line ~413)

```diff
 	protected function get_config_path()
 	{
-		return $this->config_path ?: $this->phpbb_root_path . 'src/phpbb/common/config';
+		return $this->config_path ?: $this->filesystem_root_path . 'src/phpbb/common/config';
 	}
```

#### 3d. `get_cache_dir()` (line ~423)

```diff
 	public function get_cache_dir()
 	{
-		return $this->cache_dir ?: $this->phpbb_root_path . 'cache/' . $this->get_environment() . '/';
+		return $this->cache_dir ?: $this->filesystem_root_path . 'cache/' . $this->get_environment() . '/';
 	}
```

#### 3e. `load_extensions()` — internal self-instantiation at L436

```diff
-			$container_builder = new container_builder($this->phpbb_root_path, $this->php_ext);
+			$container_builder = new container_builder($this->phpbb_root_path, $this->filesystem_root_path, $this->php_ext);
```

#### 3f. `get_core_parameters()` — add `core.filesystem_root_path` (line ~596)

```diff
 		return array_merge(
 			[
 				'core.root_path'     => $this->phpbb_root_path,
+				'core.filesystem_root_path' => $this->filesystem_root_path,
 				'core.php_ext'       => $this->php_ext,
 				'core.environment'   => $this->get_environment(),
 				'core.debug'         => defined('DEBUG') ? DEBUG : false,
 				'core.cache_dir'     => $this->get_cache_dir(),
 			],
```

#### 3g. `register_ext_compiler_pass()` — `->in()` argument (line ~681)

```diff
-			->in($this->phpbb_root_path . 'ext')
+			->in($this->filesystem_root_path . 'ext')
```

---

### 4. `src/phpbb/forums/config_php_file.php`

The constructor receives the absolute filesystem root path directly. The internal concatenation stays unchanged — it will now produce an absolute path:

```diff
-	function __construct($phpbb_root_path, $php_ext = 'php')
+	function __construct($phpbb_root_path, $php_ext = 'php')   // signature unchanged
 	{
 		$this->phpbb_root_path = $phpbb_root_path;
 		$this->php_ext = $php_ext;
 		$this->config_file = $this->phpbb_root_path . 'src/phpbb/common/config/config.' . $this->php_ext;
 	}
```

**No code change needed** in `config_php_file.php` itself. The fix is entirely in what the callers pass in (see `common.php` 2d, `phpbbcli.php` below). Document this is an intentional caller-side fix.

---

### 5. `bin/phpbbcli.php`

`$phpbb_root_path = __DIR__ . '/../';` is already absolute. Pass it as the filesystem root to both `config_php_file` and `container_builder`:

#### 5a. `config_php_file` call (line 28) — already passes `$phpbb_root_path` which is absolute; no change required.

**No change needed** — `$phpbb_root_path` in `phpbbcli.php` is already `__DIR__ . '/../'` (absolute). The `config_php_file` call at L28 is correct as-is.

#### 5b. `container_builder` call (line 41)

```diff
-$phpbb_container_builder = new \phpbb\di\container_builder($phpbb_root_path);
+$phpbb_container_builder = new \phpbb\di\container_builder($phpbb_root_path, $phpbb_root_path);
```

Passing `$phpbb_root_path` as both arguments is correct because in CLI context it is already absolute. The `$filesystem_root_path` default fallback (`realpath()`) would also work, but being explicit avoids the `realpath()` call.

---

### 6. `web/download/file.php`

#### 6a. `$phpbb_root_path` initialization (line 19)

```diff
-$phpbb_root_path = (defined('PHPBB_ROOT_PATH')) ? PHPBB_ROOT_PATH : '../../';
+$phpbb_root_path = (defined('PHPBB_ROOT_PATH')) ? PHPBB_ROOT_PATH : './';
+$phpbb_filesystem_root = defined('PHPBB_FILESYSTEM_ROOT') ? PHPBB_FILESYSTEM_ROOT : realpath(__DIR__ . '/../../') . '/';
```

Note: `PHPBB_ROOT_PATH` / `PHPBB_FILESYSTEM_ROOT` are deliberately not `define()`d in `file.php` (unlike `web/*.php`) — the fallback uses `__DIR__` arithmetic.

#### 6b. `startup.php` require (line 37)

```diff
-	require($phpbb_root_path . 'src/phpbb/common/startup.php');
+	require($phpbb_filesystem_root . 'src/phpbb/common/startup.php');
```

*(Or use `__DIR__ . '/../../src/phpbb/common/startup.php'` — equivalent and more robust.)*

#### 6c. `class_loader.php` require (line 39)

```diff
-	require($phpbb_root_path . 'src/phpbb/forums/class_loader.php');
-	$phpbb_class_loader = new \phpbb\class_loader('phpbb\\', "{$phpbb_root_path}src/phpbb/forums/");
+	require($phpbb_filesystem_root . 'src/phpbb/forums/class_loader.php');
+	$phpbb_class_loader = new \phpbb\class_loader('phpbb\\', "{$phpbb_filesystem_root}src/phpbb/forums/");
```

#### 6d. `config_php_file` call (line 41)

```diff
-	$phpbb_config_php_file = new \phpbb\config_php_file($phpbb_root_path);
+	$phpbb_config_php_file = new \phpbb\config_php_file($phpbb_filesystem_root);
```

#### 6e. Remaining requires in `$_GET['avatar']` block (lines 54–57)

```diff
-	require($phpbb_root_path . 'src/phpbb/common/constants.php');
-	require($phpbb_root_path . 'src/phpbb/common/functions.php');
-	require($phpbb_root_path . 'src/phpbb/common/functions_download' . '.php');
-	require($phpbb_root_path . 'src/phpbb/common/utf/utf_tools.php');
+	require($phpbb_filesystem_root . 'src/phpbb/common/constants.php');
+	require($phpbb_filesystem_root . 'src/phpbb/common/functions.php');
+	require($phpbb_filesystem_root . 'src/phpbb/common/functions_download' . '.php');
+	require($phpbb_filesystem_root . 'src/phpbb/common/utf/utf_tools.php');
```

#### 6f. `class_loader_ext` and `container_builder` in `$_GET['avatar']` block (~lines 61–64)

```diff
-	$phpbb_class_loader_ext = new \phpbb\class_loader('\\', "{$phpbb_root_path}ext/");
+	$phpbb_class_loader_ext = new \phpbb\class_loader('\\', "{$phpbb_filesystem_root}ext/");
```

```diff
-	$phpbb_container_builder = new \phpbb\di\container_builder($phpbb_root_path);
+	$phpbb_container_builder = new \phpbb\di\container_builder($phpbb_root_path, $phpbb_filesystem_root);
```

#### 6g. Non-avatar block includes (~lines 148–149)

The implicit-else path (attachment downloads) uses `include`/`require` with `$phpbb_root_path`:

```diff
-include($phpbb_root_path . 'src/phpbb/common/common.php');
-require($phpbb_root_path . 'src/phpbb/common/functions_download' . '.php');
+include($phpbb_filesystem_root . 'src/phpbb/common/common.php');
+require($phpbb_filesystem_root . 'src/phpbb/common/functions_download' . '.php');
```

`$phpbb_filesystem_root` is available from line 19 onward (introduced in 6a).

---

### 7. `src/phpbb/forums/install/helper/container_factory.php`

`container_factory` is a DI service in the installer. It instantiates `container_builder` and `config_php_file` with `$this->phpbb_root_path` and `$this->php_ext` positionally. After the Option B signature change, `$this->php_ext = 'php'` would be silently assigned to `$filesystem_root_path`, causing all paths to become `'phpsrc/...'` etc.

Minimal fix: pass `''` explicitly as `$filesystem_root_path` to preserve backward compatibility. The fallback `realpath($phpbb_root_path)` in `container_builder.__construct` will compute the absolute path.

#### 7a. `config_php_file` call (line 146)

```diff
-		$phpbb_config_php_file = new \phpbb\config_php_file($this->phpbb_root_path, $this->php_ext);
+		$phpbb_config_php_file = new \phpbb\config_php_file($this->phpbb_root_path, $this->php_ext); // unchanged — caller-side fix not needed here
```

**No change needed** for `config_php_file` — it receives `$this->phpbb_root_path` which is a DI-injected value (from `%core.root_path%`). The `config_php_file` signature is unchanged; fixing the caller to pass absolute path is a Layer 3 / DI YAML concern.

#### 7b. `container_builder` call (line 147)

```diff
-		$phpbb_container_builder = new \phpbb\di\container_builder($this->phpbb_root_path, $this->php_ext);
+		$phpbb_container_builder = new \phpbb\di\container_builder($this->phpbb_root_path, '', $this->php_ext);
```

Passing `''` explicitly for `$filesystem_root_path` ensures `$this->php_ext` stays at position 3 with no corruption. The `realpath()` fallback inside `container_builder.__construct` will compute `filesystem_root_path` from `$phpbb_root_path`.

---

## Reusable Components

### Existing Patterns Leveraged

| Pattern | Source | Used in |
|---------|--------|---------|
| `PHPBB_FILESYSTEM_ROOT` constant | `web/index.php`, all `web/*.php` | `common.php` fallback logic |
| `$phpbb_root_path = __DIR__ . '/../'` | `bin/phpbbcli.php` L26 | Confirms absolute path in CLI; informs `container_builder` call |
| `realpath($path) . '/'` fallback | Standard PHP idiom | `container_builder.__construct`, `common.php` |

### New Components Required

None. All changes are in-place modifications of existing code. No new classes, services, or files are introduced.

---

## Testing Approach

Tests should be limited to 2–6 verification checks per step group. Given `has_reproducible_defect: false` and `modifies_existing_code: true`, focus on integration smoke tests and backward compatibility.

### Test Group 1: Web Entry Point Integration

**Manual / Docker-based verification** (not unit-testable — the bug is CWD-dependent):

1. Start the forum via `docker-compose up`
2. Request `http://localhost/` — must return HTTP 200 (not 500 or blank)
3. Request `http://localhost/download/file.php?avatar=1` — must return HTTP 200 or graceful `exit` (not fatal)
4. Check PHP error log — must contain zero `require(): Failed opening required` errors

### Test Group 2: CLI Backward Compatibility

```bash
php bin/phpbbcli.php list
```
Must list available Symfony console commands without fatal error.

### Test Group 3: Container Build

1. Delete `cache/production/` directory
2. Load any forum page — container must rebuild without error
3. Confirm `cache/production/container_*.php` file is created

### Test Group 4: `config_php_file` Path Resolution

After changes, `config_php_file::$config_file` must resolve to an existing file:

```php
$f = new \phpbb\config_php_file('/var/www/phpbb/');
// $f->config_file === '/var/www/phpbb/src/phpbb/common/config/config.php'  ← must exist
```

### PHPUnit (if applicable)

Any existing PHPUnit tests for `container_builder` must pass. No new unit tests needed for this change (path arithmetic is not unit-testable in isolation from filesystem).

---

## Acceptance Criteria

| # | Criterion | How to Verify |
|---|-----------|---------------|
| 1 | `web/index.php` loads without PHP fatal error | HTTP 200 from `localhost/` |
| 2 | `web/download/file.php?avatar=1` loads without fatal | HTTP 200 or graceful exit |
| 3 | Container cache rebuilds correctly after deletion | `cache/production/container_*.php` exists |
| 4 | `php bin/phpbbcli.php list` succeeds | Exit code 0, command list shown |
| 5 | No `require(): Failed opening` in PHP error log | `grep "Failed opening" /var/log/php/error.log` returns empty |
| 6 | `core.filesystem_root_path` is registered in container | `$container->getParameter('core.filesystem_root_path')` returns absolute path |
| 7 | `install/` context backward compat | `new container_builder($phpbb_root_path)` (2-arg) still works via `realpath()` fallback |

---

## Risks and Mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|-----------|
| `realpath()` returns `false` if path doesn't exist | Low | High | Only occurs in non-standard installs; acceptable — same behavior as original broken state |
| Internal `new container_builder(L436)` misses `$filesystem_root_path` | Medium (previous state) | High | **Fixed explicitly in 3e** — pass `$this->filesystem_root_path` as 2nd arg |
| `container_factory.php` L147 passes `$php_ext` positionally | High (caught in audit) | High | **Fixed in section 7b** — pass `''` explicitly as 2nd arg so `$php_ext` stays at pos 3 |
| `web/download/file.php` remaining requires after line 60 (not reviewed) | Low | Medium | Verify full file for remaining `$phpbb_root_path` uses after line 60; replace with `$phpbb_filesystem_root` |
| `$phpbb_class_loader_ext` in `common.php` still uses `$phpbb_root_path` | Low | Low | Extension loader path is URL-relative — acceptable short-term; Layer 3 concern |

---

## Layer 3 — Deferred

**Not implemented in this task.**

After Layer 2 is complete, the DI container will have `core.filesystem_root_path` registered as a parameter. The follow-up task (Layer 3) will:

- Audit all 35+ YAML service definitions currently using `%core.root_path%` for filesystem operations
- Switch each to `%core.filesystem_root_path%`
- Affected service categories: `hook_finder`, `language.*`, `ext.manager`, `finder`, `avatar.driver.*`, `cron.*`, `auth.provider.*`, `migrator.*`
- Risk: Medium-High (large surface, each service requires individual verification)
- Blocking: No — forum functions correctly with Layer 1+2 complete

---

## Standards Compliance

- **SQL safety**: not applicable (no database queries)
- **Namespacing**: all new code lives under existing `phpbb\di` and `phpbb` namespaces — no change
- **DI**: `core.filesystem_root_path` added as a container parameter per phpBB DI conventions (key/value in `get_core_parameters()`)
- **No `global`**: no `global` keyword introduced
- **Language strings**: not applicable
- **CSRF**: not applicable

See `.maister/docs/standards/backend/STANDARDS.md` and `.maister/docs/standards/global/STANDARDS.md` for reference.

---

## Out of Scope

- Layer 3 YAML service migration (~35 services)
- `install/convert/` files
- URL-relative uses of `$phpbb_root_path` (session cookies, redirect paths)
- Refactoring of `config_php_file` property name (`$phpbb_root_path` → `$filesystem_root_path`)

## Success Criteria Summary

- All 7 files modified per scope table
- Zero `require(): Failed opening` errors in web and CLI contexts
- `container_builder` backward-compatible (2-arg construction still works)
- `core.filesystem_root_path` available in the built container
- `web/download/file.php` functions without fatal error
